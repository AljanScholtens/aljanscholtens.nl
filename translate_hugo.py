#!/usr/bin/env python3
import os
import re
import hashlib
import requests
from pathlib import Path
from frontmatter import load, dumps, Post
from dotenv import load_dotenv
from slugify import slugify

##############################################################################
# 1) .env laden (DEEPL_API_KEY en DEEPL_API_URL)
##############################################################################
load_dotenv()
DEEPL_API_KEY = os.getenv("DEEPL_API_KEY")
DEEPL_API_URL = os.getenv("DEEPL_API_URL", "https://api-free.deepl.com/v2/translate")

if not DEEPL_API_KEY:
    raise RuntimeError("Geen DEEPL_API_KEY gevonden. Zet deze in je .env of als environment variable.")

##############################################################################
# 2) Regex voor shortcodes
#    - Blokshortcodes: {{< ... >}} ... {{</ ... >}}
#    - Single-tag shortcodes: {{< ... >}} of {% ... %}
##############################################################################
SHORTCODE_BLOCK_REGEX = re.compile(
    r'(\{\{<[^>]+>\}\}[\s\S]*?\{\{</[^>]+>\}\})'
)
SINGLE_TAG_REGEX = re.compile(
    r'(\{\{<[^>]+>\}\}|\{%[^%]+%\})'
)

##############################################################################
# 3) Extract & reinsert shortcodes
##############################################################################
def extract_shortcodes(text: str):
    """
    Vervangt blok- en single-tag shortcodes door placeholders.
    Retourneert (nieuwe tekst, dict met {placeholder: originele_shortcode}).
    """
    shortcodes = {}
    placeholder_index = 0

    def replace_block(m):
        nonlocal placeholder_index
        sc_id = f"<<<SHORTCODE_{placeholder_index}>>>"
        shortcodes[sc_id] = m.group(1)
        placeholder_index += 1
        return sc_id

    new_text = SHORTCODE_BLOCK_REGEX.sub(replace_block, text)
    new_text = SINGLE_TAG_REGEX.sub(replace_block, new_text)
    return new_text, shortcodes

def reinsert_shortcodes(text: str, shortcodes: dict):
    """
    Vervangt de placeholders in de tekst door de originele shortcode-strings.
    We gebruiken hier een eenvoudige str.replace() zodat de exacte placeholder
    weer vervangen wordt.
    """
    for placeholder, original_sc in shortcodes.items():
        text = text.replace(placeholder, original_sc)
    return text

##############################################################################
# 4) Footnote vertalen in shortcodes
##############################################################################
def translate_footnotes_in_shortcodes(shortcodes: dict, translator_func):
    """
    Zoek in elke shortcode-string naar footnote="..." en vertaal de inhoud tussen
    de aanhalingstekens via translator_func.
    """
    footnote_pattern = re.compile(r'footnote="([^"]*)"')
    updated = {}
    for placeholder, code in shortcodes.items():
        def replacer(m):
            original_val = m.group(1)
            translated_val = translator_func(original_val)
            return f'footnote="{translated_val}"'
        new_code = footnote_pattern.sub(replacer, code)
        updated[placeholder] = new_code
    return updated

##############################################################################
# 5) DeepL vertaal-functie
##############################################################################
def translate_text_deepl(text: str, source_lang="NL", target_lang="EN") -> str:
    """
    Verstuur de tekst naar DeepL en retourneer de vertaling.
    """
    if not text.strip():
        return text

    params = {
        "auth_key": DEEPL_API_KEY,
        "text": text,
        "source_lang": source_lang,
        "target_lang": target_lang
    }
    try:
        resp = requests.post(DEEPL_API_URL, data=params, timeout=15)
        resp.raise_for_status()
        j = resp.json()
        return j["translations"][0]["text"]
    except Exception as e:
        print(f"[FOUT] Mislukt te vertalen: {e}")
        return text

##############################################################################
# 6) MD5-hash van de bronfile
##############################################################################
def compute_hash_of_file(file_path: Path) -> str:
    with open(file_path, "r", encoding="utf-8") as f:
        content = f.read()
    return hashlib.md5(content.encode("utf-8")).hexdigest()

##############################################################################
# 7) Velden die actief vertaald moeten worden in de front matter
##############################################################################
FIELDS_TO_TRANSLATE = ["title", "subtitle", "description", "summary"]

##############################################################################
# 8) process_post(): vertaalt een NL-bestand naar een EN-variant (.en.md of _index.en.md)
##############################################################################
def process_post(post_path: Path, target_lang="en"):
    # Lees de NL-post
    with open(post_path, "r", encoding="utf-8") as f:
        nl_post = load(f)

    if "slug" not in nl_post.metadata:
        print(f"[SKIP] {post_path} - geen 'slug' in front matter.")
        return

    current_hash = compute_hash_of_file(post_path)
    base_name = post_path.stem  # bv. "index" of "_index"
    target_file = post_path.parent / f"{base_name}.{target_lang}.md"

    existing_post = None
    if target_file.exists():
        with open(target_file, "r", encoding="utf-8") as ef:
            existing_post = load(ef)
        old_hash = existing_post.metadata.get("source_hash")
        if old_hash == current_hash:
            print(f"[SKIP] {post_path} -> {target_file} (ongewijzigd)")
            return
        else:
            print(f"[UPDATE] {post_path} -> {target_file} (bron is gewijzigd)")
    else:
        print(f"[NEW] {post_path} -> {target_file}")

    # Neem alle metadata over zodat lege velden, booleans, etc. behouden blijven
    new_metadata = dict(nl_post.metadata)

    # Vertaal specifieke front matter-velden
    for field in FIELDS_TO_TRANSLATE:
        if field in nl_post.metadata and isinstance(nl_post.metadata[field], str):
            original_val = nl_post.metadata[field]
            translated_val = translate_text_deepl(original_val, source_lang="NL", target_lang=target_lang.upper())
            new_metadata[field] = translated_val

    # Slug: als er al een slug in de EN-versie bestaat, behouden; anders genereren
    if existing_post and "slug" in existing_post.metadata:
        new_metadata["slug"] = existing_post.metadata["slug"]
    else:
        if "title" in new_metadata:
            new_metadata["slug"] = slugify(new_metadata["title"])

    # Body: verwerk shortcodes en vertaal de kale tekst
    nl_content = nl_post.content
    content_no_sc, sc_map = extract_shortcodes(nl_content)
    translated_no_sc = translate_text_deepl(content_no_sc, source_lang="NL", target_lang=target_lang.upper())
    sc_map = translate_footnotes_in_shortcodes(
        sc_map,
        translator_func=lambda txt: translate_text_deepl(txt, "NL", target_lang.upper())
    )
    final_content = reinsert_shortcodes(translated_no_sc, sc_map)

    # Zet de nieuwe source_hash
    new_metadata["source_hash"] = current_hash

    # Schrijf de vertaalde post weg, behoud de front matter volgorde (sort_keys=False)
    en_post = Post(content=final_content, **new_metadata)
    en_output = dumps(en_post, sort_keys=False)
    with open(target_file, "w", encoding="utf-8") as f:
        f.write(en_output)

    print(f"   -> Opgeslagen: {target_file}")

##############################################################################
# 9) main(): doorloopt de gehele content-map en zoekt naar *index.md (dus index.md en _index.md)
##############################################################################
def main():
    content_dir = Path("content")
    if not content_dir.is_dir():
        print(f"Map '{content_dir}' bestaat niet.")
        return

    for path in content_dir.rglob("*index.md"):
        process_post(path, target_lang="en")

if __name__ == "__main__":
    main()

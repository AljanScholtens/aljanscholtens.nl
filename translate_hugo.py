#!/usr/bin/env python3
import os
import re
import hashlib
import requests
from pathlib import Path
from frontmatter import load, dump, Post
from dotenv import load_dotenv
from slugify import slugify

# 1) .env laden, zodat we DEEPSEEK_API_KEY kunnen gebruiken
load_dotenv()

# De DeepSeek API-key uit de omgevingsvariabele
DEEPSEEK_API_KEY = os.getenv("DEEPSEEK_API_KEY")
if not DEEPSEEK_API_KEY:
    raise RuntimeError("Geen DEEPSEEK_API_KEY gevonden. Zet deze in je .env of als environment variable.")

##############################################################################
# Regex voor shortcodes
# We gebruiken twee regexes:
# 1) SHORTCODE_BLOCK_REGEX: matcht blokshortcodes van vorm {{< ... >}} ... {{</ ... >}}
# 2) SINGLE_TAG_REGEX: matcht 'enkeltag'-shortcodes, bijv. {{< shortcode param="..." >}}
##############################################################################

SHORTCODE_BLOCK_REGEX = re.compile(r'(\{\{<.*?>\}\}.*?\{\{</.*?>\}\})', re.DOTALL)
SINGLE_TAG_REGEX = re.compile(r'(\{\{<.*?>\}\}|\{% .*? %\})', re.DOTALL)

def extract_shortcodes(text: str):
    """
    Vervang alle shortcode-blokken en single-tag shortcodes door placeholders.
    We slaan de originele shortcode-tekst op in een dict (shortcodes),
    en in de string zelf plaatsen we placeholders, bijv. <<<SHORTCODE_0>>>.
    """
    shortcodes = {}
    placeholder_index = 0

    def replace_block(m):
        nonlocal placeholder_index
        sc_id = f"<<<SHORTCODE_{placeholder_index}>>>"
        shortcodes[sc_id] = m.group(1)
        placeholder_index += 1
        return sc_id

    # Eerst blokshortcodes vervangen
    new_text = SHORTCODE_BLOCK_REGEX.sub(replace_block, text)
    # Daarna single-tag shortcodes
    new_text = SINGLE_TAG_REGEX.sub(replace_block, new_text)

    return new_text, shortcodes

def reinsert_shortcodes(text: str, shortcodes: dict):
    """
    Vervangt de placeholders weer terug door de originele shortcode-blokken.
    """
    for placeholder, original_sc in shortcodes.items():
        text = text.replace(placeholder, original_sc)
    return text

##############################################################################
# Hulpfunctie voor DeepSeek API-vertaling
##############################################################################

def translate_text(text: str, target_lang="en") -> str:
    """
    Verstuur de tekst naar de DeepSeek API en retourneer de vertaling.
    """
    if not text.strip():
        return text  # Als de tekst leeg is, geen call doen

    url = "https://api.deepseek.ai/v1/translate"
    headers = {
        "Authorization": f"Bearer {DEEPSEEK_API_KEY}",
        "Content-Type": "application/json"
    }
    data = {
        "text": text,
        "target_lang": target_lang
    }

    try:
        response = requests.post(url, json=data, headers=headers, timeout=15)
        response.raise_for_status()
        json_resp = response.json()
        # Hier moet kloppen met de structuur van je API-respons
        return json_resp["data"]["translations"][0]["text"]
    except Exception as e:
        print(f"[FOUT] Kon niet vertalen: {e}")
        # fallback: geef ongewijzigde tekst terug als er iets misgaat
        return text

##############################################################################
# Hulp: MD5-hash van de bron (NL) berekenen
##############################################################################

def compute_hash_of_file(file_path: Path) -> str:
    """
    Leest het hele bestand (front matter + content) en geeft een MD5-hash terug.
    """
    with open(file_path, "r", encoding="utf-8") as f:
        content = f.read()
    return hashlib.md5(content.encode("utf-8")).hexdigest()

##############################################################################
# Velden in de front matter die we willen vertalen
##############################################################################

FIELDS_TO_TRANSLATE = ["title", "subtitle", "description", "summary"]

##############################################################################
# De hoofd-functie die één NL-post verwerkt en zo nodig een EN-post maakt/bijwerkt
##############################################################################

def process_post(post_path: Path, target_lang="en"):
    """
    - Inlezen van 'index.md' (NL)
    - Checken of er al een 'index.en.md' is.
    - Zo niet: maak hem aan (slugify de vertaalde titel of kopieer de slug).
    - Zo ja: check 'source_hash'. Als die gelijk is, skippen we.
    - Als anders, vertaal front matter-velden en de content (shortcodes uitgezonderd).
    - Schrijf de .en.md weg en zet 'source_hash' in de metadata.
    """

    # 1) Open en parse de NL-file
    with open(post_path, "r", encoding="utf-8") as f:
        nl_post = load(f)

    # Als er geen slug in de NL-file staat, kun je eventueel niets doen. 
    if 'slug' not in nl_post.metadata:
        print(f"[SKIP] {post_path} - geen 'slug' in front matter.")
        return

    # Huidige hash van de NL-file
    current_hash = compute_hash_of_file(post_path)

    # Bepaal pad voor de doeltaal, bv. index.en.md
    target_file = post_path.parent / f"index.{target_lang}.md"

    # Als het bestand al bestaat, checken we de hash
    existing_post = None
    if target_file.exists():
        with open(target_file, "r", encoding="utf-8") as ef:
            existing_post = load(ef)
        old_hash = existing_post.metadata.get("source_hash")
        if old_hash == current_hash:
            print(f"[SKIP] {post_path} -> {target_file} (niet gewijzigd)")
            return
        else:
            print(f"[UPDATE] {post_path} -> {target_file} (bron is gewijzigd)")
    else:
        print(f"[NEW] {post_path} -> {target_file}")

    # 2) Vertaal front matter
    new_metadata = dict(existing_post.metadata) if existing_post else {}
    # Als er al front matter bestaat in de .en.md, kopiëren we die als start.
    # Daarna overschrijven we alleen de velden die we opnieuw vertalen.

    for field in FIELDS_TO_TRANSLATE:
        if field in nl_post.metadata:
            # Bij elke wijziging in de NL-file vertalen we dit veld opnieuw.
            new_metadata[field] = translate_text(nl_post.metadata[field], target_lang=target_lang)

    # 3) Slug: bij eerste aanmaak genereren (of overnemen), daarna behouden
    if existing_post and 'slug' in existing_post.metadata:
        # Slug laten zoals die is
        pass
    else:
        # Slug overnemen uit NL of op basis van vertaalde titel
        # - Overnemen NL: new_metadata["slug"] = nl_post.metadata["slug"]
        # - Op basis van (vertaalde) title: new_metadata["slug"] = slugify(new_metadata["title"])
        # Kies hieronder wat jij wil (hier nemen we de slugify van de EN-title):
        if 'title' in new_metadata:
            new_metadata["slug"] = slugify(new_metadata["title"])
        else:
            # Fallback: neem de NL-slug over
            new_metadata["slug"] = nl_post.metadata["slug"]

    # 4) Bodytekst: shortcodes er uithalen => vertalen => shortcodes terug
    nl_content = nl_post.content
    nl_content_no_sc, sc_map = extract_shortcodes(nl_content)

    translated_content_no_sc = translate_text(nl_content_no_sc, target_lang=target_lang)
    final_content = reinsert_shortcodes(translated_content_no_sc, sc_map)

    # 5) Sla de MD5-hash van de NL-bron op in de EN-front matter
    new_metadata["source_hash"] = current_hash

    # 6) Schrijf de EN-file weg
    en_post = Post(content=final_content, **new_metadata)
    with open(target_file, "w", encoding="utf-8") as f:
        dump(en_post, f)

    print(f"   -> Opgeslagen: {target_file}")

##############################################################################
# main()
##############################################################################

def main():
    # We gaan op zoek naar ALLE 'index.md' files in 'content' (recursief).
    # Pas 'content' aan als je een andere mapstructuur hebt, of geef het 
    # script via command line argumenten.
    content_dir = Path("content")
    if not content_dir.is_dir():
        print(f"Map '{content_dir}' bestaat niet.")
        return

    for path in content_dir.rglob("index.md"):
        process_post(path, target_lang="en")

if __name__ == "__main__":
    main()

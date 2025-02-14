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
# 1) Laad environment variables (.env met DEEPL_API_KEY en DEEPL_API_URL)
##############################################################################
load_dotenv()
DEEPL_API_KEY = os.getenv("DEEPL_API_KEY")
DEEPL_API_URL = os.getenv("DEEPL_API_URL", "https://api-free.deepl.com/v2/translate")

if not DEEPL_API_KEY:
    raise RuntimeError("Geen DEEPL_API_KEY gevonden in .env")

##############################################################################
# 2) Regex voor shortcode extractie
##############################################################################
# Deze regex vangt block-shortcodes zoals {{<photos>}} ... {{</photos>}}
SHORTCODE_BLOCK_REGEX = re.compile(
    r'(\{\{<\s*photos\b[^>]*>\}\}[\s\S]*?\{\{</\s*photos\s*>\}\})',
    re.IGNORECASE
)
# Deze regex vangt enkele shortcodes zoals ref, figure of gallery
SINGLE_TAG_REGEX = re.compile(
    r'(\{\{<\s*(?:ref|figure|gallery)\s+[^>]+>\}\})',
    re.IGNORECASE
)

##############################################################################
# 3) Functies voor het extraheren en herinzetten van shortcodes
##############################################################################
def extract_shortcodes(text: str):
    """
    Vervangt shortcodes door placeholders ingekapseld in <notranslate> tags.
    Dit zorgt ervoor dat DeepL de inhoud tussen deze tags niet aanpast.
    Retourneert een tuple: (aangepaste tekst, dict met {placeholder: originele_shortcode}).
    """
    shortcodes = {}
    placeholder_index = 0

    def replacer(match):
        nonlocal placeholder_index
        token = f"SHORTCODE_{placeholder_index}"
        # Kapsel de placeholder in <notranslate> zodat DeepL dit overslaat.
        placeholder = f"<notranslate>{token}</notranslate>"
        shortcodes[placeholder] = match.group(0)
        placeholder_index += 1
        return placeholder

    # Eerst block-shortcodes vervangen
    text = SHORTCODE_BLOCK_REGEX.sub(replacer, text)
    # Daarna single-tag shortcodes
    text = SINGLE_TAG_REGEX.sub(replacer, text)
    return text, shortcodes

def reinsert_shortcodes(text: str, shortcodes: dict) -> str:
    """
    Vervangt de placeholders in de tekst door de originele shortcode-content.
    """
    for placeholder, original in shortcodes.items():
        text = text.replace(placeholder, original)
    return text

##############################################################################
# 4) DeepL integratie: functie om tekst te vertalen
##############################################################################
def translate_text_deepl(text: str, source_lang="NL", target_lang="EN") -> str:
    """
    Verstuur de tekst naar de DeepL API en retourneer de vertaling.
    De DeepL-aanroep krijgt instructies om de inhoud binnen <notranslate> tags over te slaan.
    """
    if not text.strip():
        return text

    params = {
        "auth_key": DEEPL_API_KEY,
        "text": text,
        "source_lang": source_lang,
        "target_lang": target_lang,
        "preserve_formatting": "1",
        "tag_handling": "xml",
        "ignore_tags": "notranslate"
    }
    
    try:
        resp = requests.post(DEEPL_API_URL, data=params, timeout=30)
        resp.raise_for_status()
        return resp.json()["translations"][0]["text"]
    except Exception as e:
        print(f"[Fout] Vertaling mislukt: {e}")
        return text

##############################################################################
# 5) Post verwerking: vertaal front matter en content, behoud shortcodes
##############################################################################
# Velden in de front matter die vertaald moeten worden
FIELDS_TO_TRANSLATE = ["title", "subtitle", "description", "summary", "menuTitle", "slug"]

def process_post(post_path: Path, target_lang="en"):
    # Lees het bronbestand (Nederlandse versie)
    with open(post_path, "r", encoding="utf-8") as f:
        nl_post = load(f)

    if "slug" not in nl_post.metadata:
        print(f"[Sla over] {post_path} - geen slug gevonden")
        return

    # Bereken de hash van het bronbestand om te controleren op wijzigingen
    current_hash = hashlib.md5(post_path.read_bytes()).hexdigest()
    # Bepaal het doelbestand (bijvoorbeeld: index.en.md)
    target_file = post_path.with_name(f"{post_path.stem}.{target_lang}.md")
    
    existing_post = load(target_file) if target_file.exists() else None
    if existing_post and existing_post.metadata.get("source_hash") == current_hash:
        print(f"[Sla over] {target_file} - ongewijzigd")
        return

    # Vertaal de front matter-velden
    new_metadata = dict(nl_post.metadata)
    for field in FIELDS_TO_TRANSLATE:
        if field in new_metadata and isinstance(new_metadata[field], str):
            new_metadata[field] = translate_text_deepl(
                new_metadata[field], 
                target_lang=target_lang.upper()
            )


    # Verwerk de content:
    # 1. Vervang shortcodes door placeholders (houdt de originele inhoud inclusief alle pipes)
    content, shortcodes = extract_shortcodes(nl_post.content)
    # 2. Vertaal de overige content via DeepL
    translated_content = translate_text_deepl(content, target_lang=target_lang.upper())
    # 3. Zet de originele shortcodes weer terug
    final_content = reinsert_shortcodes(translated_content, shortcodes)

    # Schrijf de vertaalde post weg
    target_file.write_text(dumps(Post(final_content, **new_metadata), sort_keys=False))
    print(f"[Success] {target_file} opgeslagen")

##############################################################################
# 6) Hoofdfunctie: doorloop de content-map en verwerk alle index.md bestanden
##############################################################################
def main():
    content_dir = Path("content")
    for md_file in content_dir.rglob("*index.md"):
        process_post(md_file)

if __name__ == "__main__":
    main()
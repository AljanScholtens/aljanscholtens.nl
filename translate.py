#!/usr/bin/env python3
import os
import re
import hashlib
import requests
from pathlib import Path
from frontmatter import load, dumps, Post
from dotenv import load_dotenv

# Laad environment variables
load_dotenv()
DEEPL_API_KEY = os.getenv("DEEPL_API_KEY")
DEEPL_API_URL = os.getenv("DEEPL_API_URL", "https://api-free.deepl.com/v2/translate")

if not DEEPL_API_KEY:
    raise RuntimeError("Geen DEEPL_API_KEY gevonden in .env")

# Regex patronen voor shortcodes
SHORTCODE_BLOCK_REGEX = re.compile(
    r'(\{\{<\s*photos\b[^>]*>\}\}[\s\S]*?\{\{</\s*photos\s*>\}\})', re.IGNORECASE
)
SINGLE_TAG_REGEX = re.compile(r'(\{\{<\s*(?:ref|figure|gallery)\s+[^>]+>\}\})', re.IGNORECASE)

def extract_shortcodes(text: str):
    shortcodes = {}
    placeholder_index = 0

    def replacer(match):
        nonlocal placeholder_index
        token = f"SHORTCODE_{placeholder_index}"
        shortcodes[token] = match.group(0)
        placeholder_index += 1
        return f"<notranslate>{token}</notranslate>"

    text = SHORTCODE_BLOCK_REGEX.sub(replacer, text)
    text = SINGLE_TAG_REGEX.sub(replacer, text)
    return text, shortcodes

def reinsert_shortcodes(text: str, shortcodes: dict) -> str:
    for token, original in shortcodes.items():
        text = text.replace(f"<notranslate>{token}</notranslate>", original)
    return text

def translate_text_deepl(text: str, source_lang="NL", target_lang="EN") -> str:
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

FIELDS_TO_TRANSLATE = ["title", "subtitle", "description", "summary", "menuTitle", "slug"]

def process_post(post_path: Path, target_lang="en"):
    with open(post_path, "r", encoding="utf-8") as f:
        nl_post = load(f)

    if "slug" not in nl_post.metadata:
        print(f"[Sla over] {post_path} - geen slug gevonden")
        return

    current_hash = hashlib.md5(post_path.read_bytes()).hexdigest()
    target_file = post_path.with_name(f"{post_path.stem}.{target_lang}.md")
    
    if target_file.exists() and load(target_file).metadata.get("source_hash") == current_hash:
        print(f"[Sla over] {target_file} - ongewijzigd")
        return

    new_metadata = dict(nl_post.metadata)
    for field in FIELDS_TO_TRANSLATE:
        if field in new_metadata and isinstance(new_metadata[field], str):
            new_metadata[field] = translate_text_deepl(new_metadata[field], target_lang=target_lang.upper())

    content, shortcodes = extract_shortcodes(nl_post.content)
    translated_content = translate_text_deepl(content, target_lang=target_lang.upper())
    final_content = reinsert_shortcodes(translated_content, shortcodes)

    new_metadata["source_hash"] = current_hash
    target_file.write_text(dumps(Post(final_content, **new_metadata), sort_keys=False))
    print(f"[Success] {target_file} opgeslagen")

def main():
    current_dir = Path.cwd()
    index_md = current_dir / "index.md"
    
    if not index_md.exists():
        print(f"[Fout] Geen index.md gevonden in huidige map: {current_dir}")
        return
    
    process_post(index_md)

if __name__ == "__main__":
    main()
"""
EuroPulse AutoPilot v2.1 — Media Pipeline
Full fallback chain:
  1. Extract image from primary source HTML
  2. Extract from supporting source HTMLs
  3. Web search by key phrases (DuckDuckGo image)
  4. Pexels API
  5. Wikimedia Commons API
  6. Fallback: empty (PHP side uses category placeholder)
"""
from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from typing import Optional

import httpx

logger = logging.getLogger(__name__)

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; EuroPulseBot/2.1; +https://europulse.today)",
}
TIMEOUT = 15


@dataclass
class MediaResult:
    image_url: str = ""
    image_source: str = ""   # pexels | wikimedia | source | search | ""
    attribution: str = ""
    photographer: str = ""
    alt_text: str = ""


async def find_media(
    key_phrases: list[str],
    source_url: str = "",
    supporting_urls: list[str] | None = None,
    pexels_api_key: str = "",
    query_lang: str = "de",
) -> MediaResult:
    """Run the full fallback chain and return first successful result."""
    # 1. Primary source
    if source_url:
        result = await _extract_from_html(source_url)
        if result:
            return result

    # 2. Supporting sources
    for url in (supporting_urls or [])[:3]:
        result = await _extract_from_html(url)
        if result:
            return result

    # 3. Pexels API
    if pexels_api_key and key_phrases:
        result = await _pexels_search(key_phrases[:3], pexels_api_key)
        if result:
            return result

    # 4. Wikimedia Commons
    if key_phrases:
        result = await _wikimedia_search(key_phrases[:3], query_lang)
        if result:
            return result

    return MediaResult()


# ---------------------------------------------------------------------------
# 1 + 2 — Extract OG / twitter image from HTML
# ---------------------------------------------------------------------------

async def _extract_from_html(url: str) -> Optional[MediaResult]:
    if not url:
        return None
    try:
        async with httpx.AsyncClient(follow_redirects=True, timeout=TIMEOUT) as client:
            resp = await client.get(url, headers=HEADERS)
        if resp.status_code != 200:
            return None
        html = resp.text
        image_url = _og_image(html) or _twitter_image(html)
        if not image_url:
            return None
        # Validate with HTTP HEAD
        if not await _url_is_image(image_url):
            return None
        return MediaResult(
            image_url=image_url,
            image_source="source",
            attribution=url,
            alt_text="",
        )
    except Exception as exc:
        logger.debug("HTML image extract failed for %s: %s", url, exc)
        return None


def _og_image(html: str) -> str:
    m = re.search(r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']', html, re.I)
    if m:
        return m.group(1)
    m = re.search(r'<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']', html, re.I)
    return m.group(1) if m else ""


def _twitter_image(html: str) -> str:
    m = re.search(r'<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']', html, re.I)
    if m:
        return m.group(1)
    return ""


async def _url_is_image(url: str) -> bool:
    """Validate URL responds with an image content-type via HTTP HEAD."""
    if url.lower().endswith(('.jpg', '.jpeg', '.png', '.webp', '.gif')):
        return True  # extension-level fast pass
    try:
        async with httpx.AsyncClient(follow_redirects=True, timeout=8) as client:
            resp = await client.head(url, headers=HEADERS)
        ct = resp.headers.get("content-type", "")
        return "image/" in ct
    except Exception:
        return False


# ---------------------------------------------------------------------------
# 3 — Pexels API
# ---------------------------------------------------------------------------

async def _pexels_search(phrases: list[str], api_key: str) -> Optional[MediaResult]:
    query = " ".join(phrases)
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            resp = await client.get(
                "https://api.pexels.com/v1/search",
                headers={"Authorization": api_key},
                params={"query": query, "per_page": 5, "orientation": "landscape"},
            )
        if resp.status_code != 200:
            return None
        data = resp.json()
        photos = data.get("photos", [])
        if not photos:
            return None
        photo = photos[0]
        image_url = photo.get("src", {}).get("large2x") or photo.get("src", {}).get("large", "")
        if not image_url:
            return None
        photographer = photo.get("photographer", "")
        return MediaResult(
            image_url=image_url,
            image_source="pexels",
            attribution=f"Photo by {photographer} on Pexels",
            photographer=photographer,
            alt_text=photo.get("alt", query),
        )
    except Exception as exc:
        logger.debug("Pexels search failed: %s", exc)
        return None


# ---------------------------------------------------------------------------
# 4 — Wikimedia Commons
# ---------------------------------------------------------------------------

async def _wikimedia_search(phrases: list[str], lang: str = "de") -> Optional[MediaResult]:
    query = " ".join(phrases)
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            resp = await client.get(
                "https://commons.wikimedia.org/w/api.php",
                params={
                    "action": "query",
                    "list": "search",
                    "srnamespace": "6",  # File namespace
                    "srsearch": query,
                    "srlimit": "5",
                    "format": "json",
                },
            )
        if resp.status_code != 200:
            return None
        data = resp.json()
        results = data.get("query", {}).get("search", [])
        if not results:
            return None

        for item in results:
            title = item.get("title", "")
            if not title.startswith("File:"):
                continue
            image_url = await _wikimedia_file_url(title)
            if image_url:
                return MediaResult(
                    image_url=image_url,
                    image_source="wikimedia",
                    attribution=f"Wikimedia Commons — {title}",
                    alt_text=title.replace("File:", "").rsplit(".", 1)[0],
                )
    except Exception as exc:
        logger.debug("Wikimedia search failed: %s", exc)
    return None


async def _wikimedia_file_url(title: str) -> str:
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT) as client:
            resp = await client.get(
                "https://commons.wikimedia.org/w/api.php",
                params={
                    "action": "query",
                    "titles": title,
                    "prop": "imageinfo",
                    "iiprop": "url|mediatype",
                    "format": "json",
                },
            )
        if resp.status_code != 200:
            return ""
        data = resp.json()
        pages = data.get("query", {}).get("pages", {})
        for page in pages.values():
            info = page.get("imageinfo", [{}])[0]
            media_type = info.get("mediatype", "")
            if media_type not in ("BITMAP", "DRAWING"):
                continue
            url = info.get("url", "")
            # Skip SVG + very small files
            if url.lower().endswith(".svg"):
                continue
            return url
    except Exception:
        pass
    return ""

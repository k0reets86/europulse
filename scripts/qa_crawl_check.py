#!/usr/bin/env python3
import json
import re
import sys
from collections import OrderedDict
from html import unescape
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen

PUBLIC_BASE = "http://204.168.148.47"
BASE = PUBLIC_BASE

SEEDS = [
    "/",
    "/?lang=uk",
    "/?lang=en",
    "/?cat=14",
    "/?cat=16",
    "/?cat=46",
    "/?cat=184&lang=uk",
    "/?cat=191&lang=uk",
    "/?cat=212&lang=uk",
    "/?cat=151&lang=en",
    "/?cat=16&lang=en",
    "/?cat=46&lang=en",
    "/?p=398",
    "/?p=399",
    "/?p=400",
]

HREF_RE = re.compile(r'href=["\\\']([^"\\\']+)["\\\']', re.I)
CANON_RE = re.compile(r'<link[^>]+rel=["\\\']canonical["\\\'][^>]+href=["\\\']([^"\\\']+)["\\\']', re.I)
LINK_TAG_RE = re.compile(r'<link\b[^>]*>', re.I)
ATTR_RE = re.compile(r'([a-zA-Z:-]+)=["\\\']([^"\\\']+)["\\\']')


def fetch(path: str):
    url = path if path.startswith("http") else BASE + path
    req = Request(url, headers={"User-Agent": "EuroPulse-QA/1.0"})
    with urlopen(req, timeout=20) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        return resp.getcode(), dict(resp.headers), body, url


def normalize_link(raw: str):
    raw = unescape(raw.strip())
    if not raw or raw.startswith("#") or raw.startswith("mailto:") or raw.startswith("tel:") or raw.startswith("javascript:"):
        return None
    if raw.startswith(PUBLIC_BASE):
        raw = BASE + raw[len(PUBLIC_BASE):]
    elif raw.startswith("http://127.0.0.1"):
        raw = PUBLIC_BASE + raw[len("http://127.0.0.1"):]
    elif raw.startswith("/"):
        raw = BASE + raw
    else:
        raw = urljoin(BASE + "/", raw)
    parsed = urlparse(raw)
    if parsed.netloc not in {"127.0.0.1", "204.168.148.47"}:
        return None
    parsed = parsed._replace(netloc=urlparse(PUBLIC_BASE).netloc, fragment="")
    return parsed.geturl()


def main():
    report = OrderedDict()
    broken = []
    sampled_links = OrderedDict()

    for seed in SEEDS:
        status, headers, body, final_url = fetch(seed)
        canonical = CANON_RE.search(body)
        hreflangs = []
        for tag in LINK_TAG_RE.findall(body):
            attrs = {k.lower(): v for k, v in ATTR_RE.findall(tag)}
            if 'hreflang' in attrs and 'href' in attrs:
                hreflangs.append((attrs['hreflang'], attrs['href']))
        hrefs = []
        for raw in HREF_RE.findall(body):
            link = normalize_link(raw)
            if link:
                hrefs.append(link)
                sampled_links.setdefault(link, seed)
        report[seed] = {
            "status": status,
            "canonical": canonical.group(1) if canonical else "",
            "hreflang_count": len(hreflangs),
            "internal_link_count": len(set(hrefs)),
            "x_fastcgi_cache": headers.get("X-FastCGI-Cache", ""),
        }

    # Limit link crawl to keep it practical.
    links_to_check = list(sampled_links.keys())[:120]
    for link in links_to_check:
        try:
            status, _, _, _ = fetch(link)
            if status >= 400:
                broken.append({"url": link, "status": status, "source": sampled_links[link]})
        except Exception as exc:
            broken.append({"url": link, "status": "ERR", "source": sampled_links[link], "error": str(exc)})

    output = {
        "seeds_checked": len(SEEDS),
        "sampled_internal_links": len(links_to_check),
        "broken_links": broken,
        "pages": report,
    }
    json.dump(output, sys.stdout, ensure_ascii=False, indent=2)


if __name__ == "__main__":
    main()

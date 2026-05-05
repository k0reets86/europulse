"""
EuroPulse AutoPilot v2.1 — Semantic Analyzer
Performs language detection, key phrase extraction (TF-IDF), content quality scoring
and content type classification.
"""
from __future__ import annotations

import logging
import math
import re
import string
from dataclasses import dataclass, field
from typing import Optional

try:
    from langdetect import detect as _lang_detect, LangDetectException
    _LANGDETECT_OK = True
except ImportError:
    _LANGDETECT_OK = False

try:
    import nltk
    from nltk.corpus import stopwords as _nltk_sw
    _NLTK_OK = True
except ImportError:
    _NLTK_OK = False

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# NLTK bootstrap (downloads stopwords on first run if missing)
# ---------------------------------------------------------------------------

def _ensure_nltk_data() -> None:
    if not _NLTK_OK:
        return
    import os
    nltk_data_dir = os.path.expanduser("~/nltk_data")
    try:
        nltk.data.find("corpora/stopwords")
    except LookupError:
        try:
            nltk.download("stopwords", quiet=True, download_dir=nltk_data_dir)
        except Exception:
            pass

_ensure_nltk_data()

# ---------------------------------------------------------------------------
# Stopword sets
# ---------------------------------------------------------------------------

_FALLBACK_STOPWORDS: dict[str, set[str]] = {
    "de": {
        "der","die","das","ein","eine","und","oder","aber","wenn","weil","da","wie",
        "an","in","auf","von","mit","zu","für","bei","nach","über","unter","aus",
        "ist","sind","war","wurde","werden","hat","haben","kann","wird","soll","muss",
        "also","noch","auch","schon","nur","bereits","wieder","immer","mehr","sehr",
        "dieser","diese","dieses","jetzt","dann","so","sie","er","es","wir","ich","du",
        "dem","den","des","am","im","zum","zur","als","bis","seit","durch","dabei",
        "doch","denn","sondern","dass","sich","uns","ihnen","ihm","ihr","ihm","einen",
    },
    "uk": {
        "і","й","та","або","але","якщо","тому","що","як","на","в","з","до","від",
        "для","при","після","це","він","вона","вони","ми","ви","я","у","за","по",
        "про","між","над","під","так","ні","вже","ще","тільки","більш","дуже",
        "цього","цьому","цих","які","який","яка","яке","яких","якому","якій",
        "був","була","були","буде","будуть","є","бути","може","мають","має",
    },
    "en": {
        "the","a","an","and","or","but","if","because","as","how","at","in","on",
        "of","with","to","for","by","after","over","under","from","is","are","was",
        "were","has","have","can","will","shall","must","also","still","yet","just",
        "this","that","these","those","their","its","our","your","his","her","them",
        "we","he","she","it","i","you","so","then","now","more","very","already",
        "when","where","which","who","what","about","than","into","not","be","been",
    },
}


def _stopwords_for(lang: str) -> set[str]:
    if _NLTK_OK:
        try:
            _ensure_nltk_data()
            lang_map = {"de": "german", "uk": "ukrainian", "en": "english",
                        "fr": "french", "ru": "russian", "pl": "polish"}
            nltk_lang = lang_map.get(lang, "english")
            return set(_nltk_sw.words(nltk_lang))
        except Exception:
            pass
    return _fallback_sw_for(lang)


def _fallback_sw_for(lang: str) -> set[str]:
    return _FALLBACK_STOPWORDS.get(lang, _FALLBACK_STOPWORDS["en"])


# ---------------------------------------------------------------------------
# Data types
# ---------------------------------------------------------------------------

@dataclass
class SemanticResult:
    detected_language: str = "de"
    key_phrases: list[str] = field(default_factory=list)
    quality_score: float = 0.0          # 0.0 – 1.0
    content_type: str = "news"          # news | service | sport | kultur | community
    needs_enrichment: bool = False      # True if weak article needs supporting sources
    word_count: int = 0
    sentence_count: int = 0


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

def analyze(title: str, content: str, hint_lang: str = "") -> SemanticResult:
    """Run full semantic analysis on article text."""
    text_full = f"{title}\n{content}"
    lang = _detect_language(text_full, hint_lang)
    wc = _word_count(content)
    sc = _sentence_count(content)
    phrases = _extract_keyphrases(title, content, lang, top_n=10)
    quality = _quality_score(content, wc, sc)
    ctype = _classify_content_type(title, content, lang)
    needs_enrich = quality < 0.40 or wc < 150

    return SemanticResult(
        detected_language=lang,
        key_phrases=phrases,
        quality_score=round(quality, 3),
        content_type=ctype,
        needs_enrichment=needs_enrich,
        word_count=wc,
        sentence_count=sc,
    )


# ---------------------------------------------------------------------------
# Language detection
# ---------------------------------------------------------------------------

def _detect_language(text: str, hint: str = "") -> str:
    if hint and len(hint) == 2:
        return hint.lower()
    if not _LANGDETECT_OK:
        return "de"
    try:
        return _lang_detect(text[:2000]) or "de"
    except Exception:
        return "de"


# ---------------------------------------------------------------------------
# Key phrase extraction (TF-IDF style, unigrams + bigrams)
# ---------------------------------------------------------------------------

def _tokenize(text: str) -> list[str]:
    text = text.lower()
    text = re.sub(r"[^\w\s\-äöüßàáâèéêìíîòóôùúûæœ]", " ", text)
    return [t.strip("-") for t in text.split() if len(t.strip("-")) > 2]


def _extract_keyphrases(title: str, content: str, lang: str, top_n: int = 7) -> list[str]:
    stop = _stopwords_for(lang)
    title_tokens = [t for t in _tokenize(title) if t not in stop]
    body_tokens  = [t for t in _tokenize(content) if t not in stop]

    all_tokens = title_tokens + body_tokens
    if not all_tokens:
        return []

    # TF (term frequency in combined text)
    tf: dict[str, float] = {}
    total = len(all_tokens)
    for t in all_tokens:
        tf[t] = tf.get(t, 0) + 1
    for t in tf:
        tf[t] /= total

    # Boost tokens that appear in the title
    title_set = set(title_tokens)
    for t in title_set:
        if t in tf:
            tf[t] *= 2.5

    # IDF approximation: rare in stop-words → log(1 / tf_raw)
    idf: dict[str, float] = {t: math.log(1 + 1.0 / tf[t]) for t in tf}
    tfidf = {t: tf[t] * idf[t] for t in tf}

    ranked = sorted(tfidf.items(), key=lambda x: x[1], reverse=True)
    phrases = [t for t, _ in ranked[:top_n]]
    return phrases


# ---------------------------------------------------------------------------
# Quality scoring
# ---------------------------------------------------------------------------

def _word_count(text: str) -> int:
    return len(text.split())


def _sentence_count(text: str) -> int:
    sents = re.split(r"[.!?…]+", text)
    return max(1, len([s for s in sents if len(s.strip()) > 10]))


def _quality_score(content: str, wc: int, sc: int) -> float:
    score = 0.0

    # Length component (0–0.4)
    if wc >= 400:
        score += 0.4
    elif wc >= 200:
        score += 0.25
    elif wc >= 100:
        score += 0.1

    # Quote detection (0–0.2)
    quotes = len(re.findall(r'[„"«»"\'"].{10,200}[„"«»"\'"]', content))
    score += min(0.2, quotes * 0.07)

    # Numeric facts (0–0.2)
    facts = len(re.findall(r'\b\d[\d.,]*\s*(?:%|Prozent|Euro|Mio|Mrd|km|kg|MW|GW|Tonnen)?\b', content))
    score += min(0.2, facts * 0.04)

    # Named entity heuristic: capitalized words (non-sentence start) (0–0.2)
    named = len(re.findall(r'(?<=[.!?]\s)\s*[A-ZÄÖÜ][a-zäöü]+|(?<=\s)[A-ZÄÖÜ][a-zäöü]{2,}', content))
    score += min(0.2, named * 0.01)

    return min(1.0, score)


# ---------------------------------------------------------------------------
# Content type classification
# ---------------------------------------------------------------------------

_TYPE_KEYWORDS: dict[str, list[str]] = {
    "sport": [
        # DE
        "fußball","bundesliga","fc","sport","liga","spieler","tor","sieg","niederlage",
        "turnier","tennis","formel","olympia","handball","basketball","hockey","radrennen",
        # EN
        "football","soccer","championship","match","goal","league","athlete","tournament",
        "olympic","nba","nhl","uefa","fifa",
        # UK
        "футбол","ліга","матч","гол","турнір","чемпіонат","спорт","олімпіада",
    ],
    "kultur": [
        # DE
        "theater","konzert","museum","film","kunst","kultur","festival","ausstellung",
        "musik","literatur","kino","oper","ballet","galerie","künstler",
        # EN
        "theatre","concert","museum","film","art","culture","festival","exhibition",
        "music","literature","cinema","opera","ballet","gallery","artist",
        # UK
        "театр","концерт","музей","фільм","мистецтво","культура","фестиваль","виставка",
        "музика","кіно","опера","балет","галерея",
    ],
    "service": [
        # DE
        "tipp","ratgeber","anleitung","wie man","schritt","information","hilfe","service",
        "wichtig zu wissen","verbraucher","steuer","rente","krankenversicherung",
        # EN
        "guide","tips","how to","tutorial","step","advice","consumer","insurance","tax",
        # UK
        "порада","путівник","як","крок","допомога","споживач","страхування",
    ],
    "community": [
        # DE
        "bürger","verein","ehrenamt","gemeinde","initiative","sozial","projekt",
        "veranstaltung","nachbarschaft","freiwillig","charity",
        # EN
        "community","volunteer","charity","neighborhood","civic","local","nonprofit",
        # UK
        "громада","волонтер","благодійність","сусідство","місцевий","ініціатива",
    ],
}


def _classify_content_type(title: str, content: str, lang: str) -> str:
    combined = (title + " " + content[:2000]).lower()
    best = "news"
    best_count = 0
    for ctype, kws in _TYPE_KEYWORDS.items():
        count = sum(1 for kw in kws if kw in combined)
        # Require at least 2 keyword matches to override 'news', so that a single
        # coincidental term doesn't mis-categorize a general news article.
        if count >= 2 and count > best_count:
            best_count = count
            best = ctype
    return best

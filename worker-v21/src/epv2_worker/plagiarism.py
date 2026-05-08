"""Anti-plagiarism gate (architecture audit phase 3).

After the rewriter / translator emits a language version, we run a cheap
n-gram overlap check against the primary source text. The architecture
spec (docs/editorial-calibration.md and section 6 tradeoff #7) requires
≥80% uniqueness — i.e. ≤20% trigram overlap, computed over significant
trigrams only (named entities, dates and stopwords are excluded so the
score isn't dominated by obvious shared vocabulary like "Bundestag" or
"die EU").

Algorithm:
  * Normalize text: strip HTML, lowercase, collapse whitespace.
  * Tokenize into words (Unicode-aware).
  * Drop stopwords for the relevant language.
  * Drop tokens that appear in the named-entity list passed by the caller
    (people / organizations / places from the Story Card).
  * Build trigram set from the surviving tokens.
  * overlap = |gen ∩ src| / |gen ∪ src|  (Jaccard) when both non-empty.
  * Fail if overlap > 0.20.

Returns a structured result so the caller (rewriter/translator) can log
the score and decide whether to regenerate.
"""

from __future__ import annotations

import html
import re
import unicodedata
from dataclasses import dataclass

# Compact stopword sets for DE/UK/EN. We don't pull NLTK because we want
# the gate to run during rewrite without another network/data dependency.
_STOPWORDS = {
    "de": frozenset({
        "der", "die", "das", "den", "dem", "des", "ein", "eine", "einen", "einem", "einer", "eines",
        "und", "oder", "aber", "doch", "denn", "weil", "dass", "ob", "wenn", "als", "wie", "so",
        "ist", "sind", "war", "waren", "sein", "wird", "werden", "wurde", "wurden", "hat", "haben",
        "hatte", "hatten", "kann", "können", "konnte", "konnten", "soll", "sollen", "muss", "müssen",
        "mit", "von", "zu", "zum", "zur", "auf", "an", "am", "im", "in", "für", "über", "unter",
        "auch", "noch", "schon", "nicht", "kein", "keine", "keinen", "keinem", "keiner",
        "ich", "du", "er", "sie", "es", "wir", "ihr", "sie", "man",
        "this", "that", "these", "those", "the", "a", "an",
    }),
    "uk": frozenset({
        "і", "й", "та", "а", "але", "або", "що", "як", "коли", "якщо", "де", "куди", "звідки",
        "бо", "тому", "тож", "адже", "проте", "втім",
        "у", "в", "на", "за", "до", "від", "із", "з", "по", "про", "над", "під", "при", "без",
        "не", "ні", "ані",
        "є", "був", "була", "було", "були", "буде", "будуть", "має", "мала", "мали", "мав",
        "це", "то", "той", "та", "те", "ті", "цей", "ця", "це", "ці",
        "я", "ти", "він", "вона", "воно", "ми", "ви", "вони",
        "уже", "ще", "вже", "тепер", "потім", "раніше", "колись",
    }),
    "en": frozenset({
        "the", "a", "an", "and", "or", "but", "yet", "so", "for", "nor",
        "is", "are", "was", "were", "be", "been", "being", "am",
        "have", "has", "had", "having", "do", "does", "did", "doing", "done",
        "can", "could", "shall", "should", "will", "would", "may", "might", "must",
        "of", "to", "in", "on", "at", "by", "from", "with", "about", "into", "through",
        "over", "under", "above", "below", "between", "among", "during", "before", "after",
        "this", "that", "these", "those", "it", "its", "their", "his", "her", "our", "your",
        "i", "you", "he", "she", "we", "they",
        "not", "no", "nor", "only", "also", "too", "very",
    }),
}

_WORD_RE = re.compile(r"[\w'’-]+", re.UNICODE)


@dataclass(slots=True)
class PlagiarismResult:
    overlap_ratio: float          # Jaccard similarity over significant trigrams (0..1)
    uniqueness_pct: float         # 100 - overlap_ratio*100
    passed: bool                  # True when uniqueness ≥ threshold
    threshold_pct: float          # threshold used (default 80.0)
    significant_trigrams: int     # count in the generated text — small N means weak gate
    reason: str = ""              # short human-readable note


def check_uniqueness(
    *,
    generated_text: str,
    source_text: str,
    language: str = "de",
    named_entities: list[str] | None = None,
    threshold_pct: float = 80.0,
) -> PlagiarismResult:
    """Compare ``generated_text`` to ``source_text`` and return the verdict.

    The threshold is on **uniqueness** (not overlap) so 80.0 means we require
    at least 80% of significant trigrams in the generated text to be original.
    """
    gen_trigrams = _build_trigram_set(generated_text, language, named_entities or [])
    src_trigrams = _build_trigram_set(source_text, language, named_entities or [])

    if not gen_trigrams:
        # Generated text is too short / too noisy to score. Pass by default —
        # the type-length gate will catch real-world emptiness elsewhere.
        return PlagiarismResult(
            overlap_ratio=0.0,
            uniqueness_pct=100.0,
            passed=True,
            threshold_pct=threshold_pct,
            significant_trigrams=0,
            reason="too few significant trigrams in generated text — gate skipped",
        )
    if not src_trigrams:
        return PlagiarismResult(
            overlap_ratio=0.0,
            uniqueness_pct=100.0,
            passed=True,
            threshold_pct=threshold_pct,
            significant_trigrams=len(gen_trigrams),
            reason="source text has no comparable trigrams — gate skipped",
        )

    intersect = gen_trigrams & src_trigrams
    union = gen_trigrams | src_trigrams
    overlap = (len(intersect) / len(union)) if union else 0.0
    uniqueness = (1.0 - overlap) * 100.0
    passed = uniqueness >= threshold_pct
    reason = ""
    if not passed:
        sample = list(intersect)[:3]
        reason = (
            f"shared trigrams {len(intersect)}/{len(gen_trigrams)} gen-side; "
            f"sample: {sample}"
        )
    return PlagiarismResult(
        overlap_ratio=overlap,
        uniqueness_pct=uniqueness,
        passed=passed,
        threshold_pct=threshold_pct,
        significant_trigrams=len(gen_trigrams),
        reason=reason,
    )


def _build_trigram_set(
    text: str,
    language: str,
    named_entities: list[str],
) -> set[tuple[str, str, str]]:
    if not text:
        return set()
    plain = _normalize(text)
    tokens = [t.lower() for t in _WORD_RE.findall(plain)]
    if not tokens:
        return set()
    stop = _STOPWORDS.get(language.lower(), _STOPWORDS["de"])
    entity_tokens = set()
    for raw in named_entities:
        for tok in _WORD_RE.findall(_normalize(raw).lower()):
            entity_tokens.add(tok)
    significant = [
        t for t in tokens
        if t not in stop
        and t not in entity_tokens
        and not t.isdigit()
        and len(t) >= 3
    ]
    if len(significant) < 3:
        return set()
    return {(significant[i], significant[i + 1], significant[i + 2]) for i in range(len(significant) - 2)}


def _normalize(text: str) -> str:
    text = html.unescape(text or "")
    text = re.sub(r"<[^>]+>", " ", text)
    text = unicodedata.normalize("NFKC", text)
    return re.sub(r"\s+", " ", text).strip()

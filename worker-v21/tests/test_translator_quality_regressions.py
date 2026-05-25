from __future__ import annotations

import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from epv2_worker.translator import (  # noqa: E402
    _fix_latin_cyrillic_hybrid_words,
    _fix_ukrainian_gender_agreement,
    _move_ukrainian_source_attribution,
    _normalize_ukrainian_grammar,
    _normalize_ukrainian_names,
    _normalize_ukrainian_style,
)


def normalize_ukrainian_text(text: str) -> str:
    text = _normalize_ukrainian_names(text)
    text = _normalize_ukrainian_style(text)
    text = _move_ukrainian_source_attribution(text)
    text = _fix_ukrainian_gender_agreement(text)
    text = _normalize_ukrainian_grammar(text)
    return _fix_latin_cyrillic_hybrid_words(text)


class TranslatorQualityRegressionTest(unittest.TestCase):
    def test_ukrainian_normalizer_repairs_observed_rendered_defects(self) -> None:
        raw = (
            "<p>ОйроПулсе повідомляв "
            "(гттп://204.168.148.47/соедер-реформен-соммерпаусе/). "
            "Як повідомляє Дойтшландфунк, Українска Правда і Тагесспігел, "
            "тест.Після цього 24тв згадує ПроСібен.</p>"
        )

        fixed = normalize_ukrainian_text(raw)

        self.assertIn("<p>", fixed)
        self.assertIn("</p>", fixed)
        self.assertIn("EuroPulse", fixed)
        self.assertIn("Deutschlandfunk", fixed)
        self.assertIn("Українська правда", fixed)
        self.assertIn("Tagesspiegel", fixed)
        self.assertIn("24tv", fixed)
        self.assertIn("ProSieben", fixed)
        self.assertIn("тест. Після", fixed)
        self.assertNotIn("<п>", fixed)
        self.assertNotIn("ОйроПулсе", fixed)
        self.assertNotIn("Дойтшландфунк", fixed)
        self.assertNotIn("Українска Правда", fixed)
        self.assertNotIn("Тагесспігел", fixed)
        self.assertNotIn("24тв", fixed)
        self.assertNotIn("гттп://", fixed)

    def test_hybrid_repair_preserves_real_urls_in_cyrillic_context(self) -> None:
        raw = "<p>EuroPulse повідомляв http://204.168.148.47/soeder-reformen-sommerpause/.</p>"

        fixed = _fix_latin_cyrillic_hybrid_words(raw)

        self.assertIn("<p>", fixed)
        self.assertIn("</p>", fixed)
        self.assertIn("EuroPulse", fixed)
        self.assertIn("http://204.168.148.47/soeder-reformen-sommerpause/", fixed)
        self.assertNotIn("<п>", fixed)
        self.assertNotIn("ОйроПулсе", fixed)
        self.assertNotIn("гттп://", fixed)

    def test_ukrainian_normalizer_preserves_latin_source_names_and_block_spacing(self) -> None:
        raw = (
            "<p>Kyivpost повідомляє про зміни для регіонів.</p><h2>Громадські моделі</h2>"
            "<p>Киівпост додає нові деталі.</p>"
        )

        fixed = normalize_ukrainian_text(raw)

        self.assertIn("Kyiv Post", fixed)
        self.assertIn("</p>\n\n<h2>", fixed)
        self.assertIn("</h2>\n\n<p>", fixed)
        self.assertNotIn("Kyivpost", fixed)
        self.assertNotIn("Киівпост", fixed)
        self.assertNotIn("Київпост", fixed)

    def test_ukrainian_normalizer_preserves_global_media_and_market_names(self) -> None:
        raw = (
            "<p>ОйроПулсе пише, що Волл-стріт відреагувала на матеріал "
            "Нью-Йорк Таймс, а Рейтерс і Блумберг дали додатковий контекст.</p>"
        )

        fixed = normalize_ukrainian_text(raw)

        self.assertIn("EuroPulse", fixed)
        self.assertIn("Wall Street", fixed)
        self.assertIn("The New York Times", fixed)
        self.assertIn("Reuters", fixed)
        self.assertIn("Bloomberg", fixed)
        self.assertNotIn("ОйроПулсе", fixed)
        self.assertNotIn("Волл-стріт", fixed)
        self.assertNotIn("Нью-Йорк Таймс", fixed)
        self.assertNotIn("Рейтерс", fixed)
        self.assertNotIn("Блумберг", fixed)

    def test_ukrainian_gender_normalizer_repairs_personal_agreement(self) -> None:
        raw = (
            "Росія рекомендувала іноземним державам евакуювати "
            "своє дипломатичне персонал і громадян із Києва."
        )

        fixed = normalize_ukrainian_text(raw)

        self.assertIn("свій дипломатичний персонал", fixed)
        self.assertNotIn("своє дипломатичне персонал", fixed)
        self.assertNotIn("своє дипломатичний персонал", fixed)


if __name__ == "__main__":
    unittest.main()

from __future__ import annotations

import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from epv2_worker.contracts import WorkerRequest  # noqa: E402
from epv2_worker.pipeline import PipelineContext, _augment_section_categories  # noqa: E402
from epv2_worker.story_card import _coerce_card  # noqa: E402


class PipelineSectionCategoriesTest(unittest.TestCase):
    def _ctx(self, primary: str, proposed: str = "community") -> PipelineContext:
        return PipelineContext(
            request=WorkerRequest(
                queue_id=1,
                category_proposed=proposed,
                original_title="",
                original_excerpt="",
                original_content="",
                original_url="https://example.test/item",
            ),
            categories=[primary] if primary else [],
        )

    def test_opinion_adds_meinung_as_secondary(self) -> None:
        ctx = self._ctx("politik", proposed="politik")
        _augment_section_categories(
            ctx,
            {"kind": "opinion", "category": {"primary": "politik", "confidence": 0.9}},
            "Der Gastbeitrag formuliert eine begruendete politische These.",
        )

        self.assertEqual(ctx.categories, ["politik", "meinung"])

    def test_event_announcement_adds_veranstaltungen(self) -> None:
        ctx = self._ctx("kultur", proposed="kultur")
        _augment_section_categories(
            ctx,
            {"kind": "community_event", "category": {"primary": "kultur", "confidence": 0.9}},
            "Das Konzert findet am 12.06. um 19:00 Uhr statt. Anmeldung und Tickets sind erforderlich.",
        )

        self.assertEqual(ctx.categories, ["kultur", "veranstaltungen"])

    def test_ukrainian_named_initiative_adds_section_tags(self) -> None:
        ctx = self._ctx("community")
        _augment_section_categories(
            ctx,
            {
                "kind": "news",
                "entities": {"organizations": [{"name": "Ukrainische Hilfe Muenchen", "kind": "ngo"}]},
            },
            "Die ukrainische Initiative startet ein neues ehrenamtliches Projekt fuer Gefluechtete.",
        )

        self.assertEqual(ctx.categories, ["community", "ukrainische-initiativen", "vereine-projekte"])

    def test_section_primary_keeps_source_parent_first(self) -> None:
        ctx = self._ctx("veranstaltungen", proposed="community")
        _augment_section_categories(
            ctx,
            {"kind": "community_event"},
            "Netzwerktreffen am 15. Juni um 18:00 Uhr mit Anmeldung.",
        )

        self.assertEqual(ctx.categories, ["community", "veranstaltungen", "treffen-networking"])

    def test_story_card_accepts_section_secondary(self) -> None:
        card = _coerce_card({
            "category": {
                "primary": "community",
                "confidence": 0.9,
                "secondary": "veranstaltungen",
                "secondary_confidence": 0.8,
            }
        })

        self.assertEqual(card.category.primary, "community")
        self.assertEqual(card.category.secondary, "veranstaltungen")
        self.assertEqual(card.category.secondary_confidence, 0.8)


if __name__ == "__main__":
    unittest.main()

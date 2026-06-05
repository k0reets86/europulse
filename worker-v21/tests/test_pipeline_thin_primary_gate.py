from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from epv2_worker import pipeline  # noqa: E402
from epv2_worker.contracts import WorkerRequest  # noqa: E402
from epv2_worker.media import MediaResult  # noqa: E402
from epv2_worker.rewriter import RewriteResult  # noqa: E402
from epv2_worker.semantic import SemanticResult  # noqa: E402
from epv2_worker.seo import SEOResult  # noqa: E402
from epv2_worker.translator import TranslationResult  # noqa: E402


class PipelineThinPrimaryGateTest(unittest.IsolatedAsyncioTestCase):
    async def _run(self, *, story_card: dict | None = None, supporting: list[dict[str, str]] | None = None):
        async def fake_supporting(*args, **kwargs):
            return supporting or []

        async def fake_rewrite(*args, **kwargs):
            return RewriteResult(
                title_de="Kurzer Bericht mit gesichertem Kontext",
                lead_de="Der Bericht fasst den gesicherten Kontext knapp zusammen.",
                body_de="Der Bericht fasst den gesicherten Kontext knapp zusammen und bleibt bei den belegten Fakten.",
                card_lead_de="Gesicherter Kontext wird knapp zusammengefasst.",
                success=True,
                provider="test",
                model="test-model",
            )

        async def fake_translate(*args, target_lang: str = "", **kwargs):
            return TranslationResult(
                title=f"{target_lang} title",
                lead=f"{target_lang} lead",
                body=f"{target_lang} body with complete factual text.",
                card_lead=f"{target_lang} card lead",
                success=True,
                provider="test",
                model="test-model",
            )

        async def fake_media(*args, **kwargs):
            return MediaResult(image_url="https://example.test/image.jpg", image_source="test")

        async def fake_seo(*args, **kwargs):
            return SEOResult(
                seo_title="Kurzer Bericht mit Kontext",
                meta_description="Kurzer Bericht mit gesichertem Kontext.",
                slug="kurzer-bericht-kontext",
                keywords=["Kontext"],
                success=True,
                provider="test",
                model="test-model",
            )

        request = WorkerRequest(
            queue_id=1,
            original_title="Kurze Meldung",
            original_content="Kurze Meldung mit wenigen Worten.",
            original_url="https://example.test/news",
            source_language="de",
            category_proposed="politik",
            editorial_flags={"openai_api_key": "test-key"},
            existing_payload={"_meta": {"story_card": story_card or {}}},
        )

        with (
            patch.object(
                pipeline,
                "semantic_analyze",
                return_value=SemanticResult(
                    detected_language="de",
                    key_phrases=["Politik"],
                    content_type="news",
                    needs_enrichment=True,
                ),
            ),
            patch.object(pipeline, "_search_supporting_sources_rich", new=fake_supporting),
            patch.object(pipeline, "rewrite_to_german", new=fake_rewrite),
            patch.object(pipeline, "translate_from_german", new=fake_translate),
            patch.object(pipeline, "find_media", new=fake_media),
            patch.object(pipeline, "generate_seo", new=fake_seo),
        ):
            return await pipeline.run_pipeline(request)

    async def test_thin_primary_with_story_card_facts_does_not_block(self) -> None:
        response = await self._run(
            story_card={
                "key_facts": ["Fakt eins", "Fakt zwei", "Fakt drei"],
                "category": {"primary": "politik", "confidence": 0.9},
            }
        )

        self.assertEqual(response.outcome, "ready_publish")
        self.assertNotIn("Primary source too thin for autopublish", response.blockers)

    async def test_thin_primary_with_loaded_supporting_sources_does_not_block(self) -> None:
        response = await self._run(
            supporting=[
                {"title": "A", "url": "https://a.test", "content": " ".join(["eins"] * 30)},
                {"title": "B", "url": "https://b.test", "content": " ".join(["zwei"] * 30)},
            ]
        )

        self.assertEqual(response.outcome, "ready_publish")
        self.assertNotIn("Primary source too thin for autopublish", response.blockers)

    async def test_thin_primary_without_context_still_blocks(self) -> None:
        response = await self._run()

        self.assertEqual(response.outcome, "ready_review")
        self.assertIn("Primary source too thin for autopublish", response.blockers)


if __name__ == "__main__":
    unittest.main()

from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from epv2_worker import rewriter  # noqa: E402


class RewriterThinSourceTest(unittest.IsolatedAsyncioTestCase):
    async def test_thin_source_forces_source_bound_news_brief_prompt(self) -> None:
        captured: dict[str, object] = {}
        thin_body = " ".join(
            [
                "Die",
                "Quelle",
                "meldet",
                "eine",
                "kurze",
                "politische",
                "Aussage",
                "ohne",
                "weitere",
                "Details.",
            ]
            * 8
        )

        async def fake_openai(
            user_prompt: str,
            api_key: str,
            source_text: str,
            max_tokens: int = 1536,
            model: str = "gpt-4o-mini",
            story_card: dict | None = None,
            dossier_block: str = "",
        ) -> rewriter.RewriteResult:
            captured["user_prompt"] = user_prompt
            captured["max_tokens"] = max_tokens
            captured["model"] = model
            return rewriter.RewriteResult(
                title_de="Kurze Meldung bleibt quellengebunden",
                lead_de="Die Quelle meldet eine kurze politische Aussage.",
                body_de="Die Quelle meldet eine kurze politische Aussage ohne weitere Details.",
                success=True,
            )

        with (
            patch.object(rewriter, "provider_available", return_value=True),
            patch.object(rewriter, "register_provider_success"),
            patch.object(rewriter, "_call_openai", new=fake_openai),
        ):
            result = await rewriter.rewrite_to_german(
                original_title="Quelle meldet kurze Aussage",
                original_content=thin_body,
                source_language="de",
                content_type="news",
                key_phrases=["Politik"],
                openai_api_key="test-key",
                deepseek_api_key="",
                provider_order=[("openai", "test-key", "gpt-4o-mini")],
                length_profile="standard",
                source_url="https://example.com/story",
                kind="news_article",
                rubric_slug="politik",
                dossier_block="SUPPORTING: https://support.example/story",
            )

        self.assertTrue(result.success)
        prompt = str(captured["user_prompt"])
        self.assertIn("TYP: news_brief", prompt)
        self.assertIn("SOURCE-BOUND BRIEF", prompt)
        self.assertIn("Supporting-Links ohne geladenen Excerpt oder Body", prompt)
        self.assertLessEqual(int(captured["max_tokens"]), 1024)


if __name__ == "__main__":
    unittest.main()

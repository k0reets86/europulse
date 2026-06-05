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

    async def test_uniqueness_failure_tries_fallback_provider(self) -> None:
        calls: list[str] = []

        async def fake_deepseek(
            user_prompt: str,
            api_key: str,
            source_text: str,
            max_tokens: int = 1536,
            model: str = "deepseek-chat",
            story_card: dict | None = None,
            dossier_block: str = "",
        ) -> rewriter.RewriteResult:
            calls.append("deepseek")
            return rewriter.RewriteResult(
                title_de="DeepSeek near copy",
                lead_de="DeepSeek lead",
                body_de="DeepSeek body",
                success=True,
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
            calls.append("openai")
            return rewriter.RewriteResult(
                title_de="OpenAI safe rewrite",
                lead_de="OpenAI lead",
                body_de="OpenAI body",
                success=True,
            )

        def fake_uniqueness(result: rewriter.RewriteResult, **kwargs) -> None:
            if result.provider == "deepseek":
                result.uniqueness_passed = False
                result.uniqueness_pct = 72.0
                result.uniqueness_reason = "test near-copy"
            else:
                result.uniqueness_passed = True
                result.uniqueness_pct = 96.0

        with (
            patch.object(rewriter, "provider_available", return_value=True),
            patch.object(rewriter, "register_provider_success"),
            patch.object(rewriter, "_call_deepseek", new=fake_deepseek),
            patch.object(rewriter, "_call_openai", new=fake_openai),
            patch.object(rewriter, "_annotate_uniqueness", new=fake_uniqueness),
        ):
            result = await rewriter.rewrite_to_german(
                original_title="Quelle meldet politische Aussage",
                original_content=" ".join(["Die Quelle meldet eine politische Aussage mit weiteren Details."] * 8),
                source_language="de",
                content_type="news",
                key_phrases=["Politik"],
                openai_api_key="openai-key",
                deepseek_api_key="deepseek-key",
                provider_order=[
                    ("deepseek", "deepseek-key", "deepseek-chat"),
                    ("openai", "openai-key", "gpt-4o-mini"),
                ],
                length_profile="standard",
                source_url="https://example.com/story",
            )

        self.assertTrue(result.success)
        self.assertEqual(result.provider, "openai")
        self.assertEqual(calls.count("deepseek"), 2)
        self.assertEqual(calls.count("openai"), 1)


if __name__ == "__main__":
    unittest.main()

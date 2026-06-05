from __future__ import annotations

import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from epv2_worker.contracts import LanguagePackage  # noqa: E402
from epv2_worker.pipeline import _english_translation_integrity_blockers  # noqa: E402


class PipelineTranslationIntegrityTest(unittest.TestCase):
    def test_blocks_bare_oblast(self) -> None:
        package = LanguagePackage(
            lang="en",
            title="Romania appoints new prime minister",
            excerpt="Eugen Tomac was born near the Ukrainian city of Izmail in Oblast.",
            content="<p>He later moved to Romania.</p>",
        )

        self.assertIn(
            "translation_integrity_en: bare administrative area name",
            _english_translation_integrity_blockers(package),
        )

    def test_allows_named_oblast(self) -> None:
        package = LanguagePackage(
            lang="en",
            title="Romania appoints new prime minister",
            excerpt="Eugen Tomac was born near the Ukrainian city of Izmail in Odesa Oblast.",
            content="<p>He later moved to Romania.</p>",
        )

        self.assertEqual(_english_translation_integrity_blockers(package), [])

    def test_allows_common_lowercase_region(self) -> None:
        package = LanguagePackage(
            lang="en",
            title="Storm warning",
            excerpt="Authorities expect stronger winds in the region overnight.",
            content="<p>The warning remains in force until Friday.</p>",
        )

        self.assertEqual(_english_translation_integrity_blockers(package), [])


if __name__ == "__main__":
    unittest.main()

# EPV2 External Reference Map

Date: 2026-03-19

This file separates two things:

1. open-source components that can realistically be embedded or adapted
2. commercial WordPress products that should be used only as pattern references

## Open-Source Components Worth Using

### Action Scheduler

Use for:

- queue discipline on the WP side
- recurring backstop jobs
- observable background execution pattern

Why it matters:

- it is a proven scalable queue pattern for WordPress plugins

Source:

- https://actionscheduler.org/usage/
- https://actionscheduler.org/api/

### Trafilatura

Use for:

- article text extraction
- metadata extraction
- cleaner primary/supporting source parsing

Why it matters:

- it is already battle-tested for web article extraction
- much stronger than hand-written ad hoc extraction

Sources:

- https://trafilatura.readthedocs.io/en/latest/corefunctions.html
- https://github.com/adbar/trafilatura

### Unstructured

Use for:

- structured partitioning of messy HTML
- fallback parsing of noisy pages where simple article extraction fails

Sources:

- https://docs.unstructured.io/open-source/core-functionality/partitioning
- https://github.com/Unstructured-IO/unstructured

### spaCy

Use for:

- rule-based entity patterns
- event/entity hints for category/media checks
- person/place/institution extraction

Best fit in this project:

- `EntityRuler`
- rule-based matching for German politics, transport, sport, community signals

Sources:

- https://spacy.io/api/entityruler
- https://spacy.io/usage/rule-based-matching/
- https://github.com/explosion/spaCy

### dateparser

Use for:

- multilingual event date extraction
- parsing mixed-language date strings from source pages

Source:

- https://dateparser.readthedocs.io/en/latest/

### Haystack DocumentLanguageClassifier

Use for:

- quick source language routing before deeper processing

Why it matters:

- source language can be anything, but the pipeline must still become DE-first

Source:

- https://docs.haystack.deepset.ai/docs/documentlanguageclassifier

### Celery / Python RQ

Use for:

- bounded retries
- explicit failed job handling
- stage-level retries outside PHP request lifecycle

Recommendation:

- start with simpler CLI worker contract
- if needed, move to RQ first
- use Celery only if concurrency/retry routing grows more complex

Sources:

- https://docs.celeryq.dev/en/stable/userguide/tasks.html#automatic-retry-for-known-exceptions
- https://python-rq.org/docs/exceptions/#retrying-failed-jobs

## Commercial / Closed Products To Borrow Patterns From

Do not copy code from these. Use them as product references only.

### Content Egg

Borrow:

- multi-source architecture
- modular source connectors
- productized source-management UI ideas

Source:

- https://www.keywordrush.com/manuals/content_egg_manual.pdf

### TaxoPress

Borrow:

- content-driven term extraction workflow
- stronger post-processing around tags/categories

Source:

- https://taxopress.com/automatically-add-terms-wordpress-content/

### Rank Math Content AI

Borrow:

- tight SEO-field integration
- modular AI assistance around title/meta/content
- keeping AI layer optional and modular

Sources:

- https://rankmath.com/content-ai/
- https://rankmath.com/kb/configure-content-ai-global-settings/
- https://rankmath.com/kb/different-meta-title-and-description/

### Feedzy / WP Robot / AutoBlogging Pro

Status:

- still useful as product references
- not selected as code donors here

Borrow only:

- source management UX
- keyword filtering ideas
- import scheduling patterns

## Practical Recommendation For EPV2

### Use As Code

- Trafilatura
- Unstructured
- spaCy
- dateparser
- optionally Haystack language classifier

### Use As Operational Pattern

- Action Scheduler on the WP side
- RQ/Celery style bounded retry on the worker side

### Use Only As Product Inspiration

- Content Egg
- TaxoPress
- Rank Math Content AI
- Feedzy
- WP Robot
- AutoBlogging Pro

## Resulting Hybrid Architecture

WP side:

- queue
- dashboard
- review
- publish
- taxonomy/meta sync

Worker side:

- parse source
- enrich sources
- extract entities/events/dates
- build DE master
- validate DE
- translate to UK/EN
- validate bundle
- rank media candidates

This is the cleanest way to keep WordPress light while still preserving all editorial rules discussed for EPV2.

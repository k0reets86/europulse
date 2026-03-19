# EPV2 Worker

This directory is the first extraction point for the heavy EPV2 content pipeline that should live outside WordPress.

## Purpose

The worker is responsible for:

- context understanding
- source enrichment
- event extraction
- German master bundle generation
- multilingual translation from German master
- semantic validation
- shared media candidate selection

WordPress should keep:

- queue storage
- admin review
- publish scheduling
- final post creation
- taxonomy and SEO writes
- attachment sideload / thumbnail assignment

## Current State

This is a scaffold, not the full migration.

It already defines:

- the request/response contract
- the DE-first pipeline order
- stage boundaries that match the current WP plugin
- one runnable local entrypoint
- story-type aware length policy hooks

## Run

```bash
python3 -m epv2_worker --input /path/to/request.json
```

## Request Shape

See `src/epv2_worker/contracts.py`.

The worker accepts one queue item payload and returns one structured bundle result with one of these outcomes:

- `ready_publish`
- `ready_review`
- `retry_process`
- `dead_letter`

## Why CLI First

CLI is the safest first cut:

- no extra server dependency
- easy to invoke from cron or PHP `proc_open`
- easier to test deterministically

Once stable, this can be wrapped by a lightweight HTTP service without changing the internal pipeline contract.

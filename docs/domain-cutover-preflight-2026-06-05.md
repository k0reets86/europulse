# Domain Cutover Preflight - 2026-06-05

## Current State

- Live site currently uses `http://204.168.148.47` as both `home` and `siteurl`.
- Backup before domain work:
  - `/root/backups/europulse-pre-domain-20260605-212748/db.sql.gz`
  - `/root/backups/europulse-pre-domain-20260605-212748/live-code.tar.gz`
  - `/root/backups/europulse-pre-domain-20260605-212748/output-artifacts.tar.gz`
  - `/root/backups/europulse-pre-domain-20260605-212748/repo-working-tree.patch`
  - `/root/backups/europulse-pre-domain-20260605-212748/repo-untracked-code.tar.gz`
- Runtime/audit artifacts removed from the repo working tree after archiving.

## Blocking Items Before Public Domain Indexing

- Exact target domain is still needed before DB URL replacement.
- Published static/legal pages still contain launch placeholders and working-version wording.
  - Current placeholder page count: `17`.
  - Affected areas include DE/UK/EN contact, advertising/community submission, corrections, imprint, privacy/data protection, and terms pages.
- Existing DB references to `204.168.148.47` before cutover:
  - `ep_posts.post_content`: `1247`
  - `ep_posts.guid`: `15579`
  - `ep_posts.post_excerpt`: `18`
  - `ep_postmeta.meta_value`: `114`
  - `ep_options.option_value`: `5`
  - `ep_epv2_queue` payload/source columns: `28`
  - `ep_epv2_log`: `0`

## Safe Cutover Sequence

1. Point DNS A/AAAA to the server and add nginx server names for the final domain.
2. Issue/verify TLS certificate.
3. Put the site into a short maintenance window or stop writers briefly.
4. Export a fresh DB backup.
5. Dry-run search/replace:

```bash
sudo -u www-data wp search-replace 'http://204.168.148.47' 'https://DOMAIN.TLD' --all-tables --precise --recurse-objects --dry-run --path=/var/www/europulse/public
```

6. Execute search/replace only after the dry-run count looks sane:

```bash
sudo -u www-data wp search-replace 'http://204.168.148.47' 'https://DOMAIN.TLD' --all-tables --precise --recurse-objects --path=/var/www/europulse/public
```

7. Confirm `home` and `siteurl`:

```bash
sudo -u www-data wp option get home --path=/var/www/europulse/public
sudo -u www-data wp option get siteurl --path=/var/www/europulse/public
```

8. Purge FastCGI/object caches and regenerate SEO sitemap if needed.
9. Smoke-test:
  - `/`
  - `/nachrichten/`
  - `/en/news/`
  - `/uk/новини/`
  - key categories
  - one DE/UK/EN article translation cluster
  - RSS/sitemap/canonical/OpenGraph URLs
10. Redirect or de-index direct IP access to avoid duplicate indexing.

## Notes

- Do not invent legal/operator details. Static/legal pages need real operator data before full public launch.
- Empty niche rubrics are not a cutover blocker; product direction is to fill them via source/parser fixes and secondary categories.
- Old internal IP links should be handled by the domain search/replace after the final domain is known.

# EuroPulse Site Quality Audit

Date: 2026-03-14

## What Was Tested

- smoke checks with Playwright:
  - DE home desktop
  - DE home mobile
  - UK home mobile
  - EN home desktop
  - DE single post
- Lighthouse:
  - DE home desktop
  - DE home mobile
  - DE single desktop
- axe accessibility:
  - the same key pages above

## What Was Fixed During Audit

- `Ukraine` and `Community` sections in DE home no longer render `Startseite` links
- final CSS normalization moved into a dedicated layer:
  - `/var/www/europulse/public/wp-content/mu-plugins/europulse-normalize.css`
- back-to-top button added and kept visible on mobile with safer offsets
- accessibility issues fixed:
  - duplicate `aria-hidden` ticker no longer exposes focusable links
  - footer copyright contrast fixed

## Smoke Check Status

All current smoke checks passed.

Checked rules:
- ticker present
- key homepage sections populated
- latest cards equalized
- DE / UK / EN major pages render
- back-to-top button exists

Script:
- `/root/projects/europulse/scripts/smoke_check.js`

## Accessibility Status

Current axe result for the key pages: no violations in the tested set.

Script:
- `/root/projects/europulse/scripts/qa_accessibility.js`

## Lighthouse Summary

### DE home desktop

- Performance: `86`
- Accessibility: `87`
- Best Practices: `74`
- SEO: `100`
- LCP: `2.5s`

### DE home mobile

- Performance: `71`
- Accessibility: `87`
- Best Practices: `75`
- SEO: `100`
- LCP: `10.2s`
- TTI: `10.4s`

### DE single desktop

- Performance: `78`
- Accessibility: `90`
- Best Practices: `74`
- SEO: `100`
- LCP: `3.9s`

Raw reports:
- `/root/projects/europulse/qa-reports/lighthouse-de-home-desktop.json`
- `/root/projects/europulse/qa-reports/lighthouse-de-home-mobile.json`
- `/root/projects/europulse/qa-reports/lighthouse-de-post-desktop.json`

## Main Weak Spots Found

### 1. Mobile LCP is the main production weakness

Symptoms:
- home mobile LCP about `10.2s`
- TTI about `10.4s`

Likely causes:
- heavy hero and homepage images
- large image payloads
- CSS weight
- ad + hero + media-first first screen

### 2. Image delivery is still too heavy

Lighthouse flags:
- next-gen formats
- responsive sizing
- image encoding

Meaning:
- many images are still larger than necessary
- WebP / AVIF generation is not consistently used

### 3. CSS still has technical debt

Lighthouse flags:
- unused CSS
- unminified CSS

Meaning:
- legacy stylesheet remains large
- normalization improved control, but bytes are still too high

### 4. Static delivery is better, but not yet ideal

Lighthouse flags:
- text compression
- cache policy
- HTTP/2

Meaning:
- gzip is on, but text compression config is not fully tuned for all asset types
- some static assets still do not benefit from strong cache TTL
- site is still HTTP-only, so no real HTTP/2 browser path yet

### 5. DOM is still large on homepage

Lighthouse flags:
- DOM size close to `900` elements

Meaning:
- homepage is editorially rich, but markup weight is high

## Priority Improvement Order

1. Reduce mobile LCP:
   - optimize hero images
   - reduce homepage first-screen media weight
   - review header ad impact on first paint

2. Optimize images:
   - convert and serve WebP/AVIF where possible
   - generate more appropriate responsive sizes
   - avoid oversized originals in homepage and single templates

3. Reduce CSS payload:
   - continue migrating final rules out of legacy foundation CSS
   - remove dead selectors
   - minify production CSS

4. Improve static delivery:
   - expand `gzip_types`
   - after final domain: enable HTTPS + HTTP/2/3 + CDN
   - increase cache TTL for versioned assets

5. Keep QA automated:
   - keep smoke + axe
   - rerun Lighthouse after major layout/content changes

## Current QA Tooling Now In Repo

- smoke:
  - `/root/projects/europulse/scripts/smoke_check.js`
- accessibility:
  - `/root/projects/europulse/scripts/qa_accessibility.js`

## Notes

- `webhint` was prepared but not used as a final source of truth because the local connector on this box could not reliably detect a supported browser installation.
- For the current stage, Playwright + axe + Lighthouse already surfaced the actionable production issues.

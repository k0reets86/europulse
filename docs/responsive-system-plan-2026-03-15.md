# EuroPulse Responsive System Plan

Date: 2026-03-15

## Official references

- web.dev, Responsive web design basics  
  https://web.dev/articles/responsive-web-design-basics
- MDN, Responsive web design  
  https://developer.mozilla.org/en-US/docs/Learn_web_development/Core/CSS_layout/Responsive_Design
- W3C WCAG 2.2, Target Size (Minimum)  
  https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html

## Principles to enforce

1. One responsive system only.
2. Mobile-first rules.
3. Breakpoints based on layout pressure, not device brand names.
4. No separate hidden logic for mobile vs desktop when the same block can be expressed by one system.
5. Touch targets should stay large enough for real fingers.
6. Text budgets must be tied to each layout zone.

## Proposed breakpoint system

- `0–479px`
  - very small phones
- `480–767px`
  - phones
- `768–1023px`
  - tablets / small landscape devices
- `1024–1279px`
  - small laptops
- `1280–1599px`
  - normal desktop
- `1600px+`
  - wide desktop / possible outer ad rails

## Required unified rules

### Header

- utility bar must stay one-row on phones
- social icons never wrap if visible
- search available both in header and mobile menu
- logo line must not switch behavior by hidden legacy rules

### Ticker

- one animation model
- one label sizing model
- duplicated marquee groups for seamless loop
- same logic in all languages

### Hero slider

- fixed structural slots:
  - media
  - meta
  - chip
  - title
  - excerpt
- title and excerpt budgets per breakpoint
- chip aligned consistently, not reimplemented by each breakpoint
- no generic card meta system reused directly in hero

### Cards and sections

- one meta order:
  - date
  - categories
- one category dot model
- card heights controlled per zone, not by accident
- one-column collapse on narrow layouts must keep full story counts

### Footer

- same typography family as main navigation
- spacing scaled down consistently on narrow screens

### Ads

- ad slots adapt by slot type, not random container behavior
- no crop rules based on generic background hacks

## Content budgets for publishing automation

- hero title:
  - small phone: up to 4 lines
  - phone: up to 4 lines
  - desktop: up to 4 visual lines
- hero excerpt:
  - small phone: up to 4 lines
  - desktop: up to 3 lines
- latest card excerpt:
  - must fit fixed card height
- compact cards:
  - fixed headline/excerpt budgets by zone

## Refactor order

1. Keep `europulse-normalize.css` as the only authoritative responsive layer.
2. Continue removing overlapping responsive rules from `europulse-foundation.css`.
3. Move block-by-block:
   - header
   - ticker
   - hero
   - latest
   - section modules
   - single/article
   - footer
   - ads
4. Re-run smoke, accessibility, crawl, viewport screenshots after each block group.

## Current status

- responsive system is not yet fully unified
- normalization has started
- next safe step is migrating remaining responsive behavior into `europulse-normalize.css`

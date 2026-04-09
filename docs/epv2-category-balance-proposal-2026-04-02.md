# EPV2 Category Balance Proposal

Date: 2026-04-02

## Goal

Prevent category skew in automatic publishing while preserving newsworthiness and urgency.

This proposal is tuned for EuroPulse, not for a generic global newsroom. Large outlets keep hard-news sections dominant, but EuroPulse serves a narrower audience: Ukrainian readers in Germany who need a mix of world context, Germany policy, practical adaptation coverage, Ukraine war coverage, and a smaller amount of sport/culture/community content.

## Current Live Mix

Queue snapshot across `published + ready_publish + new`:

- `world`: 21
- `politik`: 13
- `sport`: 12
- `wirtschaft`: 7
- `deutschland`: 5
- `leben-in-deutschland`: 5
- `ukraine`: 5
- `community`: 2
- `kultur`: 2
- `bayern`: 1

Observed problem:

- too much `world + politik + sport`
- too little `leben-in-deutschland`, `deutschland`, `ukraine`, `community`, `kultur`

## External Editorial Anchors

The section architecture of major outlets shows a repeated pattern:

- AP keeps broad high-priority coverage in breaking news, sports, business, politics, health, science, entertainment and lifestyle.
  - Source: https://www.ap.org/content/topics/
- BBC mixes biggest moment-driven news with deeper sections such as Business, Sport, Culture, Innovation, Earth, Travel and Live.
  - Source: https://help.bbc.com/hc/en-us/articles/39027623773331-What-types-of-news-content-will-be-available
- Reuters formalizes coverage expertise around breaking news, politics, business & finance, climate, human interest, sports, science/technology and entertainment.
  - Source: https://reutersagency.com/
- Guardian fronts its journalism as news, sport, business, culture, comment and lifestyle.
  - Source: https://advertising.theguardian.com/advertise/digital

Common editorial pattern:

- hard news dominates
- business/politics/world remain core
- sport is visible but not allowed to swallow the whole feed
- culture/lifestyle/human-interest are important but secondary

## EuroPulse Target Mix

The mix below should be measured on a rolling 7-day window, not per single day.

### Proposed Share by Category

- `leben-in-deutschland`: 18%
- `world`: 16%
- `politik`: 14%
- `ukraine`: 14%
- `deutschland`: 12%
- `wirtschaft`: 10%
- `sport`: 6%
- `kultur`: 5%
- `community`: 3%
- `bayern`: 2%

Total: 100%

## Why This Mix

### More practical Germany coverage

`leben-in-deutschland + deutschland = 30%`

This is deliberate. EuroPulse should publish more practical and policy-relevant material for readers living in Germany:

- migration rules
- aid
- benefits
- school
- labor market
- housing
- integration
- public services

### Ukraine must stay structurally present

`ukraine = 14%`

This avoids burying Ukraine coverage under general world/politics flow while still keeping a mixed homepage.

### World and politics stay strong, but not overwhelming

`world + politik = 30%`

This keeps EuroPulse in the serious-news space without turning it into a generic foreign-affairs wire.

### Sport remains visible, but capped

`sport = 6%`

Sport should stay on the site, but it should not crowd out service/news relevance for the core audience.

### Community and culture stay intentionally small

- `community = 3%`
- `kultur = 5%`

These are valuable, but should be curated, not allowed to flood the queue.

## Operational Rules

Percentages alone are not enough. Use guardrails.

### Rolling Window

Primary balancing window:

- rolling 7 days for target share

Fast anti-skew window:

- rolling 48 hours for caps

### Caps per 48 Hours

- `sport`: max 2 auto-published items
- `community`: max 1 auto-published item
- `kultur`: max 2 auto-published items
- `bayern`: max 1 auto-published item

These caps should be soft for true breaking/priority items.

### Floors per 7 Days

- `leben-in-deutschland`: at least 8 items
- `deutschland`: at least 5 items
- `ukraine`: at least 5 items
- `wirtschaft`: at least 3 items

If a floor is not being met, borderline items in that category can be upgraded slightly at selection time.

## Urgency Override

This mix must not block urgent news.

Priority order that can override mix caps:

1. breaking safety/security/public-interest
2. major Germany policy change
3. major Ukraine war escalation
4. major economic shock affecting readers in Germany

## Telegram / Community Rule Triggered by Item 531

Item `531` exposed a structural problem:

- source was a Telegram post
- resulting article mentioned only "a Telegram channel"
- article did not clearly identify the channel in reader-facing form
- article did not provide a clear destination or link

### Proposed Rule

Community / Telegram / channel-promotion material must not auto-publish unless all of the following are true:

- the channel/account/project is named explicitly in the text
- the reader can identify where to find it
- the item has practical public-value beyond self-promotion
- the story is framed as service information, not opaque promotion

If those conditions are not met:

- reject from auto-publish
- or require structured manual review

### Preferred Default

For pure channel-promo stories:

- reject from auto-publish

For community utility stories:

- allow only with explicit naming and attribution

## Implementation Idea After Approval

If approved, implement in three layers:

1. selection weighting
2. anti-skew publish gate
3. Telegram/community promo gate

### Selection weighting

- increase score for underrepresented categories within the rolling window
- reduce score for overrepresented categories, especially sport/community

### Anti-skew publish gate

- final publish gate checks 48h caps and 7d share
- only urgent priority items bypass caps

### Telegram/community promo gate

- detect Telegram/community-origin stories
- require explicit entity naming and outbound attribution signal
- otherwise block auto-publish

## Recommendation

Approve the mix with the exact percentages above, then implement it with:

- rolling 7-day balancing
- 48-hour soft caps
- urgency override
- Telegram/community promo gate

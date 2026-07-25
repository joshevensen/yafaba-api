# Read

First HTTP surface. Read-only access to the produced dataset (Card Explorer, Meta & Standings, Find Your Class/Hero) plus the account/auth layer that gates and meters it. Social and decks live in Build.

Sequence: **Data → Enrichment → Curation → Read → Build → Play.**

## Scope
- Accounts + Sanctum auth: `users`, `personal_access_tokens` (Sanctum-provided)
- **API-consumer accounts** — every consumer (iOS app + third-party clients) signs up for a free account and authenticates with a token, so usage can be tracked and rate-limited
- Usage/rate-limiting: `api_usage` (aggregated); live limiting via Laravel's `RateLimiter` keyed per `users.tier`
- Read-only endpoints over the produced dataset (`cards`, `card_printings`, `card_explainers`, heroes/classes/talents/keywords, `combo_pairs`, `synergy_tags`, `meta_snapshots`, `staple_stats`) — see [`../data/schema.md`](../data/schema.md)
- Open question: does an admin-only endpoint need to exist here to trigger/monitor the Enrichment/Curation pipelines, or is a scheduled command enough?

Table definitions: [`schema.md`](./schema.md). Social (`friendships`/`notifications`) moved to [Build](../build/schema.md).

## Status
Planning

# Phase 2 — Read

Authentication plus read-only access to Phase 1's data: Card Explorer, Meta & Standings Explorer, Find Your Class. First phase with an HTTP API surface.

## Scope
- Sanctum auth, `users`
- `friendships`, `notifications` (needed by Build/Play later, introduced here alongside auth)
- Read-only endpoints over `cards`, `card_printings`, `card_explainers`, `combo_pairs`, `synergy_tags`, `meta_snapshots`, `staple_stats`
- Open question: does an admin-only endpoint need to exist here to trigger/monitor Phase 1's Enrichment job?

## Status
Planning

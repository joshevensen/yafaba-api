# Data

Ingesting and storing **all sourced data** — the deterministic ETL foundation. Fetch, parse, and store every fact an external source provides; no AI, no derivation (that's Enrichment and Curation). No user-facing API surface.

Sequence: **Data → Enrichment → Curation → Read → Build → Play.**

## Scope — populates (ingested, from a source)
- Card data: `cards` (ingested columns), `card_types`, `card_printings` (+ image mirror), classification names + joins (`classes`/`talents`/`keywords`, `card_classes`/`card_talents`/`card_keywords`), `card_legality`
- Heroes/precons (identity + structure; attributes filled by Curation): `heroes`, `hero_profiles`, `precons`, `precon_cards`
- Rules & errata (raw): `rules_text_versions`, `errata_bulletins`
- Meta/standings: `meta_snapshots`, `staple_stats`

> **Boundary rule: if a source publishes it, Data ingests it.** Derived/AI content is filled by later phases — explainers/combos/synergies/`kb_documents` → [Enrichment](../enrichment/README.md); class/hero attributes + guides → [Curation](../curation/README.md).

## Phase docs
- [`schema.md`](./schema.md) — the shared core-dataset definition (all tables)
- [`sources.md`](./sources.md) — every inbound source + fetch/caching

## Resolved (before migrations)
- **Embedding dimension** — Voyage AI `voyage-3.5` → `vector(1024)` (used in Enrichment); `kb_documents.embedding_model` records provenance
- **`card_type`** — reference table (`card_types`); `cards` stays a single unified table
- **Multi-format legality** — `card_legality`, row-per-(card, format)
- **Data / Enrichment / Curation split** — ingest (this phase) vs card-level AI (Enrichment) vs class/hero profiles + guides (Curation)

## Status
Planning

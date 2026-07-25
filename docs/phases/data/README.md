# Phase 1 — Data

Card data ingestion, storage, and enrichment. No user-facing API surface — a batch pipeline plus the tables it populates (see `docs/data-schema.md` sections 1–2: Card Data, Knowledge Base).

## Scope
- Enrichment pipeline (pull, tag, validate, publish) per `docs/app-design.md`
- `cards`, `card_printings`, `card_explainers`, `combo_pairs`, `synergy_tags`, `card_synergy_tags`
- `kb_documents`, `rules_text_versions`, `errata_bulletins`
- Open question: does Meta/Standings (section 3) belong here or in Phase 2?

## Open questions to resolve before migrations
- Embedding dimension for `kb_documents.embedding`
- `card_type` as text discriminator vs. per-type tables
- Multi-format legality as columns vs. a `card_legality` table

## Status
Planning

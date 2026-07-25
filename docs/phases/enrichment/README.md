# Enrichment

Card-level AI processing on top of the stored data: plain-English explainers, combo pairs, synergy tags, and the Knowledge Base — all grounded in the Comprehensive Rules, generated once on a schedule so runtime stays deterministic.

Split out from Data because it's a different animal: **Data is deterministic ETL; Enrichment is nondeterministic, expensive, batch AI** with its own validation gate and cadence (set release, not every pull).

Sequence: **Data → Enrichment → Curation → Read → Build → Play.**

## Scope
- Card explainers, combo tagging, synergy tagging (all write `draft`, promoted by validation)
- Knowledge Base build + Voyage embeddings (`kb_documents`)
- Self-validation (rules-grounding check, self-play) before publish
- Orchestration: manual/local Artisan command → scheduled + queued in production

Full spec: [`enrichment.md`](./enrichment.md). Reads from [Data](../data/sources.md); populates tables in [`../data/schema.md`](../data/schema.md).

## Status
Planning

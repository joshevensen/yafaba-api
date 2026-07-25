# Curation

Turns stored data + card explainers into what **no source provides**: derived class/hero attributes, guide prose, and the scoring behind **Find Your Class** and **Find Your Hero**.

Named for its output (profiles + guides), not "research" — the actual research is *input material* (the [`class-research/`](./class-research/) corpus), while this phase is the derivation and authoring that consumes it. It lands after Enrichment because guides pull from card explainers.

Sequence: **Data → Enrichment → Curation → Read → Build → Play.**

## Boundary with Data
**If a source publishes it, Data ingests it; Curation only produces what no source gives.** So rosters, ability text, legality, precons, win-rates, staples — and official playstyle tags *if* fabtcg.com/hero-selection exposes them — are Data. Curation is left with the derivations and authoring: complexity scoring, computed pitch lean, set-concentration, mechanical-theme synthesis, guide prose, and quiz design.

## Scope
- **Class track** → `classes` attributes + Class Guides + Find Your Class (Stage 1)
- **Hero track** → `heroes` lore + `hero_profiles` attributes + Hero Guides + Find Your Hero (Stage 2)
- The research → attribute → quiz/guide mapping, seeded by [`class-research/`](./class-research/)

Full spec: [`curation.md`](./curation.md). Reads from [Data](../data/schema.md) + [Enrichment](../enrichment/enrichment.md); populates `classes`, `heroes`, `hero_profiles` in [`../data/schema.md`](../data/schema.md).

## Status
Planning

# Curation (profiles & guides)

Produces the class/hero content **no source provides**: derived attributes that power the quizzes, plus guide prose. Lands after Enrichment (guides pull from card explainers). The `class-research/` corpus is this phase's input material and method template.

**Boundary rule:** *if a source publishes it, Data ingests it; Curation only derives or authors what no source gives.* Rosters, ability text, legality, precons, meta/staples — and official playstyle tags **if** fabtcg.com/hero-selection exposes them (see [`../data/sources.md`](../data/sources.md)) — are all Data. What's left for Curation is below.

## Inputs
- **Data** — rosters, ability text, legality, talents, precons, meta/staples, and any official playstyle/lore tags once ingested.
- **Enrichment** — validated card explainers (guides pull from these).
- **`class-research/`** — human-authored research per class: identity, roster, complexity scoring, acquisition, and *flagged open gaps*. Seed material + the method template to replicate.

## Two tracks

### Class track → `classes` + Class Guides + Find Your Class (Stage 1)
- **Derives/authors:** `mechanical_theme`, `complexity_pattern`, `resource_lean`, `description`, `notes`.
- **Produces:** Class Guides (prose) + the Stage 1 quiz scoring over classes.

### Hero track → `heroes` + `hero_profiles` + Hero Guides + Find Your Hero (Stage 2)
- **Derives/authors:** `heroes.lore`/`flavor`; `hero_profiles.complexity_score`/`rating`, `pattern_summary`, `pitch_lean`, and `playstyle_tags` *where no official source provides them*.
- **Produces:** Hero Guides + the Stage 2 quiz scoring over `hero_profiles`, within a chosen class.

## Two kinds of work (like Enrichment)
- **Deterministic derivation (no AI).** Complexity scoring rubric over ability text; pitch/color lean computed from `precon_cards` composition + `cards.pitch_value`; set-concentration from printing spread. These are computable and re-runnable.
- **Editorial / AI-assisted (grounded, human-confirmed).** Mechanical-theme synthesis; guide prose grounded in CR + validated explainers; playstyle interpretation where fabtcg.com doesn't provide it. Same flag/confirm discipline the research files already use.

## Quiz design (the mapping)
Attributes → deterministic scoring (no runtime AI, per `app-design.md` — the app renders a scorecard from DB filters/sorts):

**Find Your Class (Stage 1)** — mirrors the app-design steps:
1. Format & legality → filter (SAGE / CC as independent flags)
2. Playstyle → score against `classes`/`hero_profiles.playstyle_tags`
3. Complexity tolerance → bucket via `complexity_rating`
4. Cost & acquisition → score via precon availability + set spread
5. Win-rate / meta → sample-size-aware score from `meta_snapshots`
6. Color/pitch lean (optional) → `pitch_lean`

**Find Your Hero (Stage 2)** — same shape, scoped to a chosen class, over `hero_profiles` (and its variant-toggle question from the research files: distinct profiles vs. "advanced variant" under a base hero).

## Guide authoring
- Class/Hero guides: AI-assisted, grounded in CR + validated card explainers; human spot-check. Versioned prompt packages under `skills/curation/*` (matching the project's `**/skills/**` convention).
- Same trust discipline as Enrichment — `draft → validated`, only validated content published.

## Method (from `class-research/`)
Each research file establishes the template: identity, roster, ability text, complexity scoring, acquisition notes, and **open-gap flags**. Curation formalizes: research file → structured attributes (populate schema columns) → quiz scoring + guides, carrying the same flag/confirm discipline so unverified claims (win-rates, archetype tags) stay flagged until confirmed.

## Open questions
- **Playstyle source.** Confirm whether fabtcg.com/hero-selection exposes official playstyle/archetype tags. If yes → Data ingests them and Curation only fills gaps; if no → Curation interprets them.
- **Guide storage.** Schema has no guide table yet. Store prose on `classes`/`heroes` columns, or add `class_guides`/`hero_guides` tables? Deferred until the guide format firms up.
- **How-to-Play authoring.** The tiered How-to-Play curriculum is also CR-grounded — decide whether it's authored here or in Enrichment.
- **Variant handling in Stage 2.** Whether to surface hero-card variants as distinct quiz options or fold them under a base hero (flagged in the research files).

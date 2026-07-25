# Data — Sources (ingestion)

Every inbound data source: where it comes from, how it's fetched, its caveats, and which tables it lands in. This is the "getting the data" phase; [Enrichment](../enrichment/enrichment.md) and [Curation](../curation/curation.md) are the processing that follows, and [`schema.md`](./schema.md) defines the target tables.

**Ground rules for all sources:**
- **Server-side only.** Nothing here is fetched at request time — all ingestion runs inside the scheduled pipeline (see [Enrichment](../enrichment/enrichment.md) for orchestration).
- **Cache hard, idempotent.** Re-pulls skip unchanged records via `source_hash` / `cached_at`; a re-run is always safe.
- **Never hotlink.** Images are mirrored to our own storage; external meta/rules sites are cached, never queried live by the app.

---

## Overview

| Source | Provides | Fetch | Target tables | Key caveat |
|---|---|---|---|---|
| [the-fab-cube/flesh-and-blood-cards](https://github.com/the-fab-cube/flesh-and-blood-cards/tree/develop/json/english) | Card data (text, stats, types, class/talent, legality) | Git/raw JSON | `cards`, `card_types`, `card_classes/talents/keywords`, `heroes`/`hero_profiles` seed, `card_legality` | Community-maintained (volunteer-dependent) |
| [tcgcsv.com](https://tcgcsv.com/) | Pricing / product data | CSV download | `card_printings.price_cache` | — |
| `api.cardvault.fabtcg.com` | Printings, finishes, layout, images (~25k prints) | JSON API | `card_printings` (+ image mirror) | **Unofficial / reverse-engineered** — cache hard, build a fallback |
| [rules.fabtcg.com/txt/latest/en-fab-cr.txt](https://rules.fabtcg.com/txt/latest/en-fab-cr.txt) | Comprehensive Rules text | Plain-text fetch | `rules_text_versions`, `kb_documents` | Versioned snapshot every fetch (never overwrite) |
| [fabtcg.com errata bulletins](https://fabtcg.com/rules-and-policy-center/errata-bulletins/) | Functional errata | Scrape index + articles | `errata_bulletins`, `kb_documents` | No API; scrape once, never re-scrape |
| fabtcgmeta.com, fablazing.com | Tier lists / win rates | Scrape | `meta_snapshots` | **ToS check per source** before caching |
| [FABREC (fabrec.gg)](https://fabrec.gg/) / Spellvoid | Staple / inclusion rates | Scrape | `staple_stats` | No public API; **ToS check** |
| fabtcg.com/hero-selection *(candidate)* | Official playstyle/archetype tags, format flags | Scrape | `hero_profiles.playstyle_tags`, `card_legality` | Confirm it exposes structured data |

---

## Card data — the-fab-cube JSON
- **What:** the primary card-text source (same feed fabrary.net runs on) — per-set English JSON files.
- **Fetch:** clone/pull the repo (or fetch raw `json/english/*.json`); parse per card.
- **Populates:** `cards` (name, type, pitch, cost, power, defense, functional_text), `card_types` (upsert the closed set), the `card_classes` / `card_talents` / `card_keywords` joins (parsed from the card's type/talent/keyword fields), `card_legality` (per-format flags → rows), and the seed for `heroes` / `hero_profiles` (hero-type cards; profile grouping happens in enrichment).
- **Idempotency:** `cards.source_hash` = hash of the upstream record; unchanged → skip. New/changed → re-run downstream enrichment for that card.
- **Caveat:** volunteer-maintained; treat structure as stable but validate on parse (unknown type/talent → flag, don't silently drop).

## Pricing — tcgcsv.com
- **What:** market pricing/product data.
- **Fetch:** CSV download; join to printings by product identity.
- **Populates:** `card_printings.price_cache`, `price_updated_at`.
- **Cadence:** can refresh more often than the full card pull (prices move); keep it a cheap standalone step.

## Printings & images — cardvault (`api.cardvault.fabtcg.com`)
- **What:** LSS's official print database — print variations (images, finishes, layout), ~25k unique prints.
- **Fetch:** unofficial JSON API. **Keep strictly server-side and cache hard** — a popular public API amplifying traffic through this undocumented endpoint risks it getting noticed/changed by LSS.
- **Populates:** `card_printings` (set_code, rarity, finish, art_variant, cardvault_print_id).
- **Images:** during this step, download any new/changed art and **mirror to DigitalOcean Spaces** (resize/optimize — WebP, thumbnails); `card_printings.image_url` always points at our Spaces copy, never the source. See [Image mirroring](#image-mirroring).
- **Caveat:** reverse-engineered/undocumented — build with a fallback in mind (e.g. degrade to fab-cube imagery or last-known cache if the endpoint changes).

## Rules text — `en-fab-cr.txt`
- **What:** the Comprehensive Rules — numbered rules (e.g. `8.5.3b`) plus glossary.
- **Fetch:** direct plain-text fetch; parse by section-splitting. The HTML rules browser is **not** used (JS-rendered, brittle to scrape).
- **Populates:** `rules_text_versions` — a **versioned snapshot on every fetch** (never overwrite), with `diff_from_previous`. Chunks feed `kb_documents` (`source_type=cr_rules`, `trust_status=ground_truth`).
- **Why versioned:** protects against the source URL breaking, enables diffing to detect rules changes, and lets us flag explainers generated against superseded wording for re-validation.

## Errata bulletins — fabtcg.com
- **What:** functional card changes — FaB's equivalent of rulings/FAQs.
- **Fetch:** no API — scrape the bulletin index for anything not already cached, then scrape each new article.
- **Populates:** `errata_bulletins` (parse `affected_card_ids` from content); chunks feed `kb_documents` (`source_type=errata_bulletin`, `trust_status=ground_truth`).
- **Idempotency:** cached once, **never re-scraped** (`cached_at`); only the index is re-checked for new bulletins.

## Meta / win rates — fabtcgmeta.com, fablazing.com
- **What:** tier lists and win-rate data per hero/format.
- **Fetch:** scrape. **Check each source's terms of use before scraping/caching.**
- **Populates:** `meta_snapshots` (hero card, format, tier, win_rate, sample_size, source, fetched_at). The app queries only this cache — never the external sites live.

## Staples / inclusion — FABREC / Spellvoid
- **What:** decklist-derived staple/inclusion-rate stats (which cards show up most for a hero).
- **Fetch:** no public API found — scraping candidate. **ToS check required.**
- **Populates:** `staple_stats` (hero card, card, inclusion_rate, source, fetched_at).

## Hero metadata — fabtcg.com/hero-selection *(candidate)*
- **What:** LSS's official hero-selection tool — likely exposes per-hero **playstyle/archetype tags** and format flags that Curation currently infers by hand.
- **Why it matters:** if it publishes structured tags, `hero_profiles.playstyle_tags` becomes *ingested fact* instead of a Curation guess — moving work from Curation into Data. This is the main "pull more into Data" opportunity.
- **Fetch:** scrape; **confirm the data is structured/parseable** (may be JS-rendered) before committing.
- **Populates:** `hero_profiles.playstyle_tags`; possibly `card_legality` / lore.
- **Status:** candidate — investigate feasibility + ToS.

---

## Image mirroring
- All card art is served from **DigitalOcean Spaces** (our own copy), pulled during the cardvault/card-data step — not hotlinked.
- Resize/optimize for mobile (WebP, thumbnails) rather than serving source-resolution.
- Estimated ~7.5GB for all ~25k prints at ~300KB avg — comfortably inside the $5/mo Spaces tier.
- **LSS IP compliance (applies wherever images appear):** display the disclaimer *"YaFaBa is in no way affiliated with Legend Story Studios. Flesh and Blood™, and set names are trademarks of Legend Story Studios®."* and the *"© Legend Story Studios"* notice. No derogatory use; no direct monetization of the images.

## Idempotency & caching summary
| Source | Skip-if-unchanged key | Re-fetch policy |
|---|---|---|
| fab-cube cards | `cards.source_hash` | every run; skip unchanged |
| tcgcsv pricing | `price_updated_at` | frequent, cheap standalone |
| cardvault printings/images | `cardvault_print_id` + image hash | every run; mirror only new/changed art |
| CR rules text | new snapshot per fetch | every run; versioned, diffed |
| errata bulletins | `cached_at` (never re-scrape) | index re-checked for new only |
| meta / staples | `fetched_at` | scheduled refresh; overwrite latest |

## Sourced vs. derived (boundary with Curation)
**Data ingests every fact a source provides; [Curation](../curation/curation.md) derives/authors what none does.** Ingested here: rosters, ability text, talents, legality, rarity, precon membership, win-rate/tier, staples, and — candidate — official playstyle/lore tags. Left to Curation: complexity scoring, computed pitch lean, set-concentration, mechanical-theme synthesis, guides, and quiz design.

## Open questions
- **Meta/staples ToS.** Confirm each source (fabtcgmeta, fablazing, FABREC/Spellvoid) permits caching before building the scraper; some may need attribution or opt-out.
- **cardvault fallback.** Define the degraded path if the unofficial API changes/blocks (fab-cube imagery? last cache?).

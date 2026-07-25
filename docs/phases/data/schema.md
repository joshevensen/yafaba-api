# Schema (shared core dataset)

The card dataset, Knowledge Base, and Meta/Standings cache. Read-heavy, written only by the production phases.

This is **one** Postgres/pgvector database (per Tech Stack doc — no cross-database joins), split across phase docs for readability. The tables below are the shared core dataset — **populated** by the production phases and **consumed** read-only by the app phases:

```
Populate:  Data (ingest) → Enrichment (card AI) → Curation (class/hero profiles + guides)
Consume:   Read → Build → Play        (each also adds its own tables)
```

- Populate → [Data/sources](sources.md) · [Enrichment](../enrichment/enrichment.md) · [Curation](../curation/curation.md)
- Consume → [Read](../read/schema.md) (accounts/auth) · [Build](../build/schema.md) (decks/social) · [Play](../play/schema.md) (matches, draft)

Column types are indicative (Postgres). See [Resolved Decisions](#resolved-decisions) for the reasoning behind each design choice.

---

## 1. Card Data

Enrichment output — read-heavy, rarely written outside Enrichment runs.

### `cards`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| name | text | |
| card_type_id | int, FK → card_types | replaces the old free-text `card_type` |
| pitch_value | int, nullable | red/yellow/blue as 1/2/3, null for non-pitch cards |
| cost | text | raw cost text (can include variable costs) |
| power | int, nullable | |
| defense | int, nullable | |
| functional_text | text | current, post-errata "true text" — **exact per card**, including format-tuned numbers (e.g. `+4{p}` on an adult version vs `+2{p}` on the young). This is the verbatim text; the abstracted play pattern lives on `hero_profiles`. |
| hero_profile_id | uuid, nullable, FK → hero_profiles | set for hero-type cards only (null otherwise); links a hero card to its play pattern |
| age | text, nullable | `young` / `adult` — hero cards only. Derivable from the source `Hero - Young` vs `Hero` type; stored for convenience |
| hero_profile_match_confidence | numeric, nullable | deterministic text-similarity score (0–1) used when assigning this hero card to a `hero_profile` — see [Grouping hero cards into profiles](#grouping-hero-cards-into-profiles) |
| hero_profile_grouping_status | text, nullable | `auto` / `confirmed` / `manual` — how the profile assignment was made, for audit and re-runs |
| source_hash | text | hash of upstream fab-cube record, to detect changes on re-pull |
| updated_at | timestamptz | |

> Class, talents, and keywords are no longer columns here — they're join tables (below) so a card can carry more than one of each. Legality is no longer columns here — it's `card_legality` (below).

### `card_types`
Small closed reference set. A table (rather than an enum) so each type can carry its own description/notes and display ordering.

| Column | Type | Notes |
|---|---|---|
| id | int, PK | |
| name | text, unique | hero / weapon / equipment / action / instant / attack_reaction / defense_reaction / resource_token / other |
| description | text | |
| display_order | int | UI ordering |
| notes | text | |

### `card_printings`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| card_id | uuid, FK → cards | |
| set_code | text | |
| rarity | text | |
| finish | text | standard / foil / cold-foil / etc. |
| art_variant | text, nullable | |
| image_url | text | YaFaBa's own Spaces-hosted copy, mirrored during Enrichment, not hotlinked |
| cardvault_print_id | text, nullable | reference to unofficial API's ID, for re-sync |
| image_source_hash | text, nullable | sha256 of the upstream cardvault image URL; skip-if-unchanged key for image mirroring |
| price_cache | numeric, nullable | from tcgcsv.com |
| price_updated_at | timestamptz | |

### `card_explainers`
Plain-English "how this card works," LLM-generated during Enrichment and grounded in the CR text — distinct from `cards.functional_text` (the official rules text). Kept in its own table because it has an independent lifecycle (draft → validated) and its own grounding-check machinery.

| Column | Type | Notes |
|---|---|---|
| card_id | uuid, PK/FK → cards | one explainer per card |
| explainer_text | text | plain-English "how this works," grounded in CR |
| cited_rules | text[] | rule numbers referenced (e.g. "8.5.3b"), for the automated grounding check |
| status | text | `draft` / `validated` |
| generated_at | timestamptz | |
| validated_at | timestamptz, nullable | |

### Legality — `card_legality`
One row per (card, format). Replaces the old `sage_legal` / `sage_banned` / `cc_legal` / `ll_status` columns; extends to new formats without schema changes.

| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| card_id | uuid, FK → cards | |
| format | text | `SAGE` / `CC` / `Blitz` / `Commoner` |
| status | text | `legal` / `banned` / `restricted` / `living_legend` / `suspended` |
| effective_date | date, nullable | |
| notes | text | e.g. Living Legend cross-check note against the fabtcg.com leaderboard |
| | | unique (card_id, format) |

### Classification — `classes`, `talents`, `keywords` (+ joins)
Normalized so a card can carry several of each, and so each carries its own descriptive/notes data for Learn content.

**`classes`**
| Column | Type | Notes |
|---|---|---|
| id | int, PK | |
| name | text, unique | Warrior, Ninja, Wizard, Guardian, Brute, Runeblade, Ranger, Illusionist, Mechanologist, Necromancer, Assassin, Pirate, Generic, … |
| mechanical_theme | text | from class research |
| complexity_pattern | text | from class research |
| resource_lean | text | from class research |
| description | text | |
| notes | text | |

**`talents`**
| Column | Type | Notes |
|---|---|---|
| id | int, PK | |
| name | text, unique | Draconic, Light, Shadow, Elemental, Ice, Lightning, Earth, Royal, Mystic, Chaos, … |
| description | text | |
| notes | text | |

**`keywords`**
| Column | Type | Notes |
|---|---|---|
| id | int, PK | |
| name | text, unique | Battleworn, Blade Break, Temper, Go again, Combo, Dominate, Intimidate, Reprise, … |
| rules_text | text | official glossary text |
| explainer | text | plain-English, for the Card Explorer |
| cited_rules | text[] | CR references |
| notes | text | |

**Join tables**
| Table | Columns |
|---|---|
| `card_classes` | card_id → cards, class_id → classes |
| `card_talents` | card_id → cards, talent_id → talents |
| `card_keywords` | card_id → cards, keyword_id → keywords |

> Class/talent are authoritative at the **card** level. A hero's class/talents derive from its cards rather than being stored a second time on `heroes`/`hero_profiles`, to avoid two sources of truth drifting apart.

### Heroes — `heroes`, `hero_profiles`
Three levels: the lore **character** groups one or more **play patterns**, each play pattern is realized by one or more hero **cards** (differing by format/printing).

**`heroes`** — the character/persona. Thin; lore-and-grouping only.
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| name | text | character name, e.g. "Dorinthea" |
| lore | text | |
| flavor | text | story / family / mentor connections (e.g. Solana / Hand of Sol) — feeds the quiz's lore tiebreaker |
| notes | text | |

**`hero_profiles`** — one per distinct play pattern (distinct ability). Holds the format-independent gameplay identity. **No verbatim ability text** — that varies per card (format-tuned numbers) and lives on `cards.functional_text`.
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| hero_id | uuid, FK → heroes | |
| label | text | short name for the pattern |
| pattern_summary | text | plain-English abstraction of how it plays (not verbatim, no format-tuned numbers) |
| complexity_score | int | from the class-research scoring rubric |
| complexity_rating | text | `Simple` / `Moderate` / `Complex` |
| playstyle_tags | text[] | Aggro / Midrange / Control / Combo / Disruptive / Board Presence / Defensive (a hero can carry several) |
| pitch_lean | text, nullable | real decklist-verified red/yellow/blue lean |
| notes | text | |

Worked example — **Dorinthea** = 1 character → 2 profiles → 3 cards:

| hero_profiles (play pattern) | complexity | cards (via cards.hero_profile_id) | format (card_legality) |
|---|---|---|---|
| "weapon hits → attack again" | Simple | Dorinthea (young) | SAGE |
| | | Dorinthea Ironsong (adult) | CC |
| "Dawnblade Resplendent go-again" | Moderate | Dorinthea, Quicksilver Prodigy (young) | SAGE |

Heroes with genuinely different young/adult abilities (e.g. Boltyn vs Ser Boltyn) become **two profiles**; heroes whose young/adult text matches (e.g. Ira, Cindra, Fai) collapse to **one profile** spanning both cards. Win rate/tier stays per **card** (`meta_snapshots`), format stays per **card** (`card_legality`); only the shared, format-independent identity lives on the profile.

#### Grouping hero cards into profiles
Assigning a hero card to a `hero_profile` is an Enrichment/validation step, driven by a deterministic score — **not** a hard auto-merge on raw text (small edits like "2 or more" → "3 or more" change play but score ~98% similar; rewordings of an identical ability can score low). Volume is tiny (compare only *within a character*, ~2–3 candidates each), so the gray band is cheap to confirm by hand or via the self-play check.

```
normalize:  lowercase → mask digits to {N} → collapse whitespace → strip the hero's own name
score:      deterministic metric (token-set ratio or normalized Levenshtein) on normalized text
route:
  ≥ 95%   → auto-group          (cards.hero_profile_grouping_status = 'auto')
  80–95%  → flag, confirm        (→ 'confirmed' once a human / self-play signs off)
  < 80%   → separate profile     (or 'manual' if a human merges anyway)
```
The score and status persist on `cards` (`hero_profile_match_confidence`, `hero_profile_grouping_status`) so the assignment is auditable and re-runnable each Enrichment pass. Calibrate thresholds against known cases (Dorinthea/Ironsong should land in the auto band).

### Precons — `precons`, `precon_cards`
Reference data for the quiz's "Cost & acquisition path" step. A precon is deck-shaped but is enrichment/reference content (not user-owned like Build's `decks`), so it gets its own home.

**`precons`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| name | text | |
| set_code | text | |
| type | text | `chapter` / `armory` / `blitz` |
| hero_card_id | uuid, nullable, FK → cards | the specific hero card the precon is built around |
| release_date | date, nullable | |
| notes | text | |

**`precon_cards`** (join)
| Column | Type | Notes |
|---|---|---|
| precon_id | uuid, FK → precons | |
| card_id | uuid, FK → cards | |
| quantity | int | |

### Combos & synergies — `combo_pairs`, `synergy_tags`, `card_synergy_tags`
**`combo_pairs`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| card_id_a | uuid, FK → cards | |
| card_id_b | uuid, FK → cards | |
| description | text | why they combo |
| status | text | `draft` / `validated` |
| self_play_win_rate_delta | numeric, nullable | output of Self Validation's self-play check |

**`synergy_tags`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| name | text | e.g. "cares_about_instants_played" |
| description | text | |

**`card_synergy_tags`** (join)
| Column | Type | Notes |
|---|---|---|
| card_id | uuid, FK → cards | |
| synergy_tag_id | uuid, FK → synergy_tags | |
| status | text | `draft` / `validated` |

---

## 2. Knowledge Base
Enrichment-side memory, RAG source.

### `kb_documents`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| source_type | text | `cr_rules` / `errata_bulletin` / `prior_explainer` / `combo_reasoning` |
| source_ref | text | e.g. rule number, bulletin URL, card_id |
| content | text | the actual chunked text |
| embedding | vector(1024) | pgvector — dimension for Voyage AI `voyage-3.5` |
| embedding_model | text | model + version that produced this vector (e.g. `voyage-3.5`), so a model change is detectable and rows can be re-embedded |
| trust_status | text | `ground_truth` / `validated` / `draft` — so enrichment prompts weight/exclude appropriately |
| version | int | for CR text — supports versioned snapshots, not overwrite |
| effective_date | date, nullable | when this version became current |
| created_at | timestamptz | |

### `rules_text_versions`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| fetched_at | timestamptz | |
| full_text | text | complete snapshot of en-fab-cr.txt at fetch time |
| diff_from_previous | text, nullable | computed diff, to detect what changed |

### `errata_bulletins`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| bulletin_number | text | |
| url | text | |
| published_date | date | |
| content | text | |
| affected_card_ids | uuid[] | parsed from bulletin content, links to `cards` |
| cached_at | timestamptz | scraped once, never re-scraped |

---

## 3. Meta / Standings Cache
Batch-pulled during Enrichment (step 7); the app queries only this cache, never the external sites live. `hero_id` references the specific hero **card** (win rate is per format/printing, not per character).

### `meta_snapshots`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| hero_id | uuid, FK → cards | the hero card (format-specific) |
| format | text | SAGE / CC / Blitz |
| tier | text, nullable | |
| win_rate | numeric, nullable | |
| sample_size | int, nullable | needed to judge reliability of win_rate |
| source | text | fabtcgmeta / fablazing / FABREC |
| fetched_at | timestamptz | |

### `staple_stats`
| Column | Type | Notes |
|---|---|---|
| hero_id | uuid, FK → cards | the hero card |
| card_id | uuid, FK → cards | |
| inclusion_rate | numeric | % of sampled decks including this card |
| source | text | |
| fetched_at | timestamptz | |

---

## Resolved Decisions

- **Meta/Standings → Data phase.** No user-facing write surface; it's a batch pull (Enrichment step 7) consumed read-only by Read/Explore. Same shape as card explainers. `hero_id → cards` (hero card), since win rate is per format/printing.
- **`card_type` → reference table, unified `cards`.** A single `cards` table keeps combo/synergy joins simple; `card_type_id` FKs into `card_types`, which carries per-type description/notes. Not a per-type table split.
- **Multi-format legality → `card_legality` table.** Row-per-(card, format) collapses the old flag columns and extends to Blitz/Commoner/etc. without schema changes.
- **Class / talent / keyword → normalized lookups + joins.** Required for correctness, not just tidiness: cards can be dual-class (Emperor) and multi-talent (Cindra), which flat columns can't represent cleanly. Each lookup also holds the descriptive data Learn content needs.
- **Heroes → three levels.** `heroes` (lore character, thin) → `hero_profiles` (distinct play pattern, format-independent gameplay identity, no verbatim text) → `cards` (individual hero cards, exact `functional_text` + `age` + legality + win rate). The play-pattern grain is ability-driven, tolerant of format-tuned stat/wording differences; profile assignment uses a deterministic similarity score with a narrow human-confirmed band.
- **Precons → reference tables.** `precons` + `precon_cards`, keyed to the hero card, for the acquisition-path quiz step. Distinct from user-owned `decks` (Build).
- **`card_explainers` kept as its own table.** Distinct lifecycle (draft/validated) and grounding-check machinery (`cited_rules`) from the read-heavy `cards` data.
- **KB embeddings → Voyage AI `voyage-3.5`, `vector(1024)`.** Anthropic has no native embeddings endpoint and recommends Voyage. `embedding_model` column records provenance for re-embedding.

## Open / Deferred
- **Playstyle tags as a controlled-vocabulary table.** Currently `hero_profiles.playstyle_tags text[]`. Could normalize to `playstyle_tags` + `hero_profile_playstyles` if the quiz needs FK integrity/filtering; deferred until it bites.
- **Explainer versioning.** One explainer per card today (`card_explainers.card_id` PK). Add versioning only if re-generation history needs diffing (mirrors `rules_text_versions`).

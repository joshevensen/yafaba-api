# YaFaBa — Data Schema (First Pass)

Organized by domain. This is a starting structure to react to, not final DDL — column types are indicative (Postgres), and several items are flagged as open questions rather than guessed at.

Single Postgres/pgvector instance (per Tech Stack doc) — no cross-database joins needed.

---

## 1. Card Data (Enrichment output — read-heavy, rarely written outside Enrichment runs)

**`cards`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| name | text | |
| card_type | text | hero / weapon / equipment / action / instant / reaction / token_resource / other |
| pitch_value | int, nullable | red/yellow/blue as 1/2/3, null for non-pitch cards |
| cost | text | raw cost text (can include variable costs) |
| power | int, nullable | |
| defense | int, nullable | |
| class | text, nullable | derived per existing `types[types.index('Hero')-1]` extraction logic |
| talents | text[] | |
| functional_text | text | current, post-errata "true text" |
| sage_legal | boolean | |
| sage_banned | boolean | must check both flags together — legal AND NOT banned |
| cc_legal | boolean | |
| ll_status | text, nullable | Living Legend — affects CC only, not SAGE. **Cross-check live vs. fabtcg.com leaderboard, not just this flag** |
| source_hash | text | hash of upstream fab-cube record, to detect changes on re-pull |
| updated_at | timestamptz | |

**`card_printings`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| card_id | uuid, FK → cards | |
| set_code | text | |
| rarity | text | |
| finish | text | standard / foil / cold-foil / etc. |
| art_variant | text, nullable | |
| image_url | text | YaFaBa's own Spaces-hosted copy, not the source URL — mirrored during Enrichment, not hotlinked at request time |
| cardvault_print_id | text, nullable | reference to unofficial API's ID, for re-sync |
| price_cache | numeric, nullable | from tcgcsv.com |
| price_updated_at | timestamptz | |

**`card_explainers`**
| Column | Type | Notes |
|---|---|---|
| card_id | uuid, PK/FK → cards | one explainer per card |
| explainer_text | text | plain-English "how this works," grounded in CR |
| cited_rules | text[] | rule numbers referenced (e.g. "8.5.3b"), for the automated grounding check |
| status | text | `draft` / `validated` — see Self Validation |
| generated_at | timestamptz | |
| validated_at | timestamptz, nullable | |

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

**`card_synergy_tags`** (join table)
| Column | Type | Notes |
|---|---|---|
| card_id | uuid, FK → cards | |
| synergy_tag_id | uuid, FK → synergy_tags | |
| status | text | `draft` / `validated` |

---

## 2. Knowledge Base (Enrichment-side memory, RAG source)

**`kb_documents`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| source_type | text | `cr_rules` / `errata_bulletin` / `prior_explainer` / `combo_reasoning` |
| source_ref | text | e.g. rule number, bulletin URL, card_id |
| content | text | the actual chunked text |
| embedding | vector(n) | pgvector column, dimension per chosen embedding model |
| trust_status | text | `ground_truth` / `validated` / `draft` — critical distinction from earlier discussion, so future enrichment prompts weight/exclude appropriately |
| version | int | for CR text specifically — supports versioned snapshots, not overwrite |
| effective_date | date, nullable | when this version became current |
| created_at | timestamptz | |

**`rules_text_versions`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| fetched_at | timestamptz | |
| full_text | text | complete snapshot of en-fab-cr.txt at fetch time |
| diff_from_previous | text, nullable | computed diff, to detect what changed |

**`errata_bulletins`**
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

**`meta_snapshots`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| hero_id | uuid, FK → cards | |
| format | text | SAGE / CC / Blitz |
| tier | text, nullable | |
| win_rate | numeric, nullable | |
| sample_size | int, nullable | needed to judge reliability of win_rate |
| source | text | fabtcgmeta / fablazing / FABREC |
| fetched_at | timestamptz | |

**`staple_stats`**
| Column | Type | Notes |
|---|---|---|
| hero_id | uuid, FK → cards | |
| card_id | uuid, FK → cards | |
| inclusion_rate | numeric | % of sampled decks including this card |
| source | text | |
| fetched_at | timestamptz | |

---

## 4. Users & Social

**`users`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| apple_id | text, unique | Sign in with Apple, given iOS-only v1 |
| display_name | text | |
| created_at | timestamptz | |

**`friendships`**
| Column | Type | Notes |
|---|---|---|
| user_id | uuid, FK → users | |
| friend_user_id | uuid, FK → users | |
| status | text | `pending` / `accepted` / `blocked` |
| created_at | timestamptz | |

**`notifications`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| user_id | uuid, FK → users | |
| type | text | `errata` / `your_turn` / `nudge` / `forfeit` |
| payload | jsonb | |
| sent_at | timestamptz, nullable | |
| read_at | timestamptz, nullable | |

---

## 5. Decks & Collection

**`decks`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| user_id | uuid, FK → users | |
| hero_id | uuid, FK → cards | |
| format | text | SAGE / CC / Blitz |
| name | text | |
| source | text | `built` / `imported` |
| created_at | timestamptz | |
| updated_at | timestamptz | |

**`deck_cards`**
| Column | Type | Notes |
|---|---|---|
| deck_id | uuid, FK → decks | |
| card_id | uuid, FK → cards | |
| quantity | int | |

**`deck_errata_flags`**
| Column | Type | Notes |
|---|---|---|
| deck_id | uuid, FK → decks | |
| card_id | uuid, FK → cards | |
| errata_bulletin_id | uuid, FK → errata_bulletins | |
| acknowledged | boolean | default false, drives the notification |
| flagged_at | timestamptz | |

**`collection_items`**
| Column | Type | Notes |
|---|---|---|
| user_id | uuid, FK → users | |
| printing_id | uuid, FK → card_printings | tracked at printing level, not just card, since "which copy" can matter for value/trading later |
| quantity | int | |
| source | text | `manual` / `dragon_shield_import` |

---

## 6. Play

**`matches`**
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| format | text | |
| intent | text | `casual` / `competitive` — drives forfeit window |
| status | text | `pending` / `active` / `completed` / `forfeited` |
| player_a_id | uuid, FK → users | |
| player_b_id | uuid, FK → users, nullable | null while waiting for matchmaking |
| deck_a_id | uuid, FK → decks | |
| deck_b_id | uuid, FK → decks, nullable | |
| current_turn_user_id | uuid, FK → users | |
| forfeit_window_hours | int | 24 competitive / 72 casual, per-turn reset |
| play_assist_enabled | boolean | derived from intent: always `false` for competitive (no toggle), defaults `true` for casual (player-toggleable) |
| last_action_at | timestamptz | clock resets here each turn |
| created_at | timestamptz | |

**`match_state`**
| Column | Type | Notes |
|---|---|---|
| match_id | uuid, PK/FK → matches | |
| engine_state | jsonb | opaque blob owned by the forked Talishar engine — YaFaBa doesn't need to model FaB's internal game state itself, since that's the engine's job, not the app's schema |
| updated_at | timestamptz | |

---

## Open Questions
- **Embedding dimension** for `kb_documents.embedding` — depends on which embedding model gets chosen; not decided yet
- **`card_type` as a text discriminator vs. separate tables per type** (heroes/weapons/equipment/etc., matching the original split JSON files) — went with a single unified table here for simplicity of combo/synergy joins, but worth confirming this doesn't lose type-specific fields (e.g., weapon has power differently than action)
- **Match history/replay** — no event log table included yet; worth deciding whether Play needs a full turn-by-turn history for review, or `match_state`'s current snapshot is sufficient
- **Multi-format legality** — `cards` currently has SAGE/CC flags directly as columns; if Blitz or other formats need the same treatment, this may want to become its own `card_legality` table instead of more columns

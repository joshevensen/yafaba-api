# Play — Schema — DRAFT

> ⚠️ **Draft / not finalized.** Play is the largest and riskiest phase (forked Talishar engine, matchmaking, mobile combat UI, Play Assist). These tables are a starting sketch, deliberately minimal — expect them to change substantially once the engine fork is understood. Nothing else in the schema depends on Play, so it can churn freely.

Depends on: `users` (`docs/phases/read/schema.md`), `decks` (`docs/phases/build/schema.md`). Match/social pipelines reuse Build's `friendships`/`notifications`.

---

### `matches`
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
| play_assist_enabled | boolean | always false for competitive; defaults true for casual (toggleable) |
| last_action_at | timestamptz | clock resets here each turn |
| created_at | timestamptz | |

### `match_state`
| Column | Type | Notes |
|---|---|---|
| match_id | uuid, PK/FK → matches | |
| engine_state | jsonb | opaque blob owned by the forked Talishar engine — YaFaBa doesn't model FaB's internal game state itself |
| updated_at | timestamptz | |

---

## Open / Deferred
- **Match history / replay.** No turn-by-turn event log yet — only `match_state`'s current snapshot. Decide whether review/replay needs a full event log once the engine fork is in hand.
- **Matchmaking queue tables.** Not modeled — matchmaking may need its own queue/state tables beyond `matches.player_b_id = null`.

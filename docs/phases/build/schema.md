# Build — Schema (decks, collection & social)

User-owned, write-heavy data: saved decks, collection, and the social graph. References `users` (Read phase) and the card core (Data phase); nothing in Data or Read points back here.

> Depends on: `users` (`docs/phases/read/schema.md`); `cards`, `card_printings`, `errata_bulletins` (`docs/phases/data/schema.md`).

---

## Social

### `friendships`
| Column | Type | Notes |
|---|---|---|
| user_id | uuid, FK → users | |
| friend_user_id | uuid, FK → users | |
| status | text | `pending` / `accepted` / `blocked` |
| created_at | timestamptz | |

### `notifications`
Shared pipeline: errata alerts (Build) and, later, turn/nudge/forfeit alerts (Play).

| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| user_id | uuid, FK → users | |
| type | text | `errata` / `your_turn` / `nudge` / `forfeit` |
| payload | jsonb | |
| sent_at | timestamptz, nullable | |
| read_at | timestamptz, nullable | |

---

## Decks & Collection

### `decks`
| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| user_id | uuid, FK → users | |
| hero_id | uuid, FK → cards | the specific hero card the deck is registered around |
| format | text | SAGE / CC / Blitz |
| name | text | |
| source | text | `built` / `imported` |
| created_at | timestamptz | |
| updated_at | timestamptz | |

### `deck_cards`
| Column | Type | Notes |
|---|---|---|
| deck_id | uuid, FK → decks | |
| card_id | uuid, FK → cards | |
| quantity | int | |

### `deck_errata_flags`
Drives the errata-change notification (a genuine differentiator per `docs/app-design.md`).

| Column | Type | Notes |
|---|---|---|
| deck_id | uuid, FK → decks | |
| card_id | uuid, FK → cards | |
| errata_bulletin_id | uuid, FK → errata_bulletins | |
| acknowledged | boolean | default false, drives the notification |
| flagged_at | timestamptz | |

### `collection_items`
| Column | Type | Notes |
|---|---|---|
| user_id | uuid, FK → users | |
| printing_id | uuid, FK → card_printings | tracked at printing level, since "which copy" can matter for value/trading |
| quantity | int | |
| source | text | `manual` / `dragon_shield_import` |

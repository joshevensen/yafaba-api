# Read — Schema (accounts, auth & API usage)

The Read phase is the first HTTP surface. It serves **read-only** endpoints over the Data core (Card Explorer, Meta & Standings, Find Your Class) and owns the **account + auth layer** that gates and meters that access.

Every API consumer — the YaFaBa iOS app *and* third-party clients (e.g. a community Android port) — signs up for a **free account** and authenticates with a Sanctum token, so usage can be tracked and, if needed, rate-limited. Public exposure is a stated concern in `docs/app-design.md`; this is where that control lives.

> This doc owns identity/auth. It **reads** the Data core (`docs/phases/data/schema.md`) but adds no card tables. Build and Play depend on `users` defined here.

## Reads from Data (no new card tables)
Read-only endpoints serve, from `docs/phases/data/schema.md`:
`cards`, `card_types`, `card_printings`, `card_explainers`, `card_legality`, `classes`/`talents`/`keywords` (+ joins), `heroes`, `hero_profiles`, `precons`, `combo_pairs`, `synergy_tags`, `meta_snapshots`, `staple_stats`.

---

## Accounts & Auth

### `users`
One account table for both app end-users (Sign in with Apple) and programmatic API consumers (email signup). Laravel/Sanctum-standard shape.

| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| email | text, unique | identity for email-signup consumers; Apple provides one too (may be a private-relay address) |
| name | text | display / developer name |
| apple_id | text, unique, nullable | Sign in with Apple — app users; null for API-only accounts |
| password | text, nullable | hashed; email/password signup — null for Apple-only accounts |
| email_verified_at | timestamptz, nullable | |
| tier | text | rate-limit tier, default `free` (room for future paid tiers) |
| created_at | timestamptz | |
| updated_at | timestamptz | |

### `personal_access_tokens` *(provided by Sanctum)*
Sanctum's standard token table — the API keys consumers authenticate with. Created by Sanctum's own migration; listed here for completeness, not hand-written.

| Column | Type | Notes |
|---|---|---|
| id | bigint, PK | |
| tokenable_type / tokenable_id | text / uuid | polymorphic → `users` |
| name | text | consumer-chosen key label |
| token | text, unique | hashed |
| abilities | text (json) | scopes |
| last_used_at | timestamptz, nullable | |
| expires_at | timestamptz, nullable | |
| created_at / updated_at | timestamptz | |

---

## Usage & Rate Limiting

### `api_usage`
Durable, **aggregated** record of consumption per consumer per day — for analytics and quota decisions. Aggregated rather than per-request logged, to stay bounded as public traffic grows.

| Column | Type | Notes |
|---|---|---|
| id | uuid, PK | |
| user_id | uuid, FK → users | |
| token_id | bigint, FK → personal_access_tokens, nullable | which key drove the traffic |
| date | date | daily bucket |
| endpoint | text, nullable | coarse route bucket; null = all-endpoints total |
| request_count | int | |
| | | unique (user_id, token_id, date, endpoint) |

> **Live enforcement** uses Laravel's `RateLimiter` (cache-backed), keyed by token/user and configured per `users.tier` — the hot path never touches this table. `api_usage` is the durable record that feeds analytics and any quota/tier decisions.

---

## Open / Deferred
- **Per-request logging.** `api_usage` is aggregated. A granular `api_request_logs` (method, path, status, latency, ip, token_id) would help debugging/abuse forensics but grows fast — add only if needed, likely with retention/rollup.
- **Admin trigger for Enrichment.** Whether an admin-only endpoint to trigger/monitor the Data-phase Enrichment job lives here (vs. a CLI/scheduled command) is still open — see the Read README.
- **Paid tiers.** `users.tier` leaves room, but tier→limit mapping is config, not modeled yet.

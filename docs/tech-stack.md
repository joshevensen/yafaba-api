# YaFaBa — Tech Stack & Infrastructure

## Backend
- **Framework**: Laravel (PHP)
- **AI integration**: [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk#introduction) for the AI-facing side of Enrichment (card explainers, combo/synergy tagging) and guide generation
- **API**: public — not just backing the iOS app, so third-party clients (e.g., a community Android port) have something real to build against
- Rate limiting needed given public exposure (real hosting/abuse-surface cost that a private-only backend wouldn't have)

## Client
- **Platform**: native Swift, iOS-only for v1
- Rationale: the platform Josh actually uses (dogfooding), best available camera/ML tooling for the future scanner (Apple Vision/Core ML), and avoids the cross-platform stack overhead (Flutter/React Native+Capacitor both considered and passed on) without a concrete Android requirement yet
- Android: deferred, not a v1 commitment — open sourcing the code and API is the intended path for someone else to build a client, rather than Josh maintaining a second codebase

## Database
- **Engine**: Postgres with the **pgvector** extension (confirmed supported on DigitalOcean Managed PostgreSQL directly, no separate vector DB infra needed)
- **Structure**: single database for both relational card/combo/user data and the vector-searchable Enrichment Knowledge Base
  - Considered splitting into a content DB and a user/app DB for fault isolation, but decided against it for now — the app can't meaningfully function without content data anyway, so the isolation benefit is partial, while the operational cost (no native cross-DB joins, two migration/backup paths) is real and ongoing for a solo dev
  - If a concrete performance problem shows up later (e.g., Enrichment batch runs affecting user-facing query performance), the first move is a **read replica** for content tables, not a full database split — gets load isolation without doubling migration/backup surface
- **Sizing expectation**: the enriched card dataset (cards, combos, synergy tags, KB embeddings) is estimated at well under 1GB even at full maturity — it scales with card releases (a few hundred/year), not with user count. A $15/mo 1GB tier could plausibly hold the full dataset for years.
  - What actually needs to scale with usage is **compute/connection pooling**, not storage — concurrent reads against a small dataset is a CPU problem, not a disk problem
- **Rough hosting cost tiers** (DigitalOcean, current pricing):
  - **Storage vs. compute**: storage isn't the reason to size up as users grow — the enriched dataset stays under 1GB regardless of user count, and DO's smallest Postgres tier already bundles 10GB storage, far more than needed indefinitely. What actually needs to scale with usage is compute/connection handling for concurrent queries.
  - **Early** (hundreds of users): Droplet $6/mo (1GB/1vCPU) + Postgres $15.15/mo (1GB/1vCPU, 10GB storage bundled) + Spaces $5/mo (optional at this stage) ≈ **$21–26/mo**
  - **"Took off"** (low thousands of users, comparable to Fabrary's ~936 Patreon supporters): Droplet $24/mo (4GB/2vCPU) + Postgres $15.15–60.90/mo (bump only if concurrent connections actually strain it, not for storage) + Spaces $5/mo ≈ **$44–90/mo**
  - **Larger scale** (tens of thousands of users): multiple app Droplets + load balancer (~$48–96/mo) + Postgres $60.90–120/mo (compute/HA, still nowhere near a storage ceiling) + Spaces $5–10/mo + read replica ≈ **$150–250/mo**
  - Bandwidth is a minor factor — DO's bandwidth allowances are generous, and images aren't primarily self-hosted at volume beyond the Spaces mirror
  - LLM/Enrichment costs are separate from hosting and are batch/intermittent (set release + periodic refresh), not continuous load

## Object Storage
- **DigitalOcean Spaces** for self-hosting card images ($5/mo — includes 250GiB storage + 1TiB outbound transfer, built-in CDN)
- **Decision: YaFaBa serves its own hosted copy of card images**, not hotlinked from fab-cube/cardvault — the app's own images become the source of truth for the UI, pulled from official sources during Enrichment rather than at request time
- Rationale: reliability independence (particularly from cardvault's unofficial/reverse-engineered API — LSS's own `storage.googleapis.com/fabmaster` hosting is Google Cloud Storage, not really a bandwidth concern either way), performance control (resize/optimize for mobile — thumbnails, WebP — rather than serving whatever the source provides), no hotlink restrictions or rate limits from infrastructure that wasn't built for YaFaBa's traffic, and avoids continuously loading someone else's servers with every app request
- **Storage estimate**: mirroring all ~25,000 unique prints (per cardvault) at a modest ~300KB average is **~7.5GB** — comfortably inside the $5/mo tier's 250GiB allocation, with no reason to expect this to scale up with user count (same logic as the main database — this is content size, not user size)
- **LSS IP terms compliance (confirmed, applies regardless of hosting method — no separate rule for self-hosted vs. hotlinked images)**:
  - Must display the disclaimer: *"YaFaBa is in no way affiliated with Legend Story Studios. Flesh and Blood™, and set names are trademarks of Legend Story Studios®."*
  - Must display the copyright notice: *"© Legend Story Studios"* wherever card images appear
  - No derogatory use; no direct monetization of the images themselves (consistent with everything already established)

## Data Archival
- **Errata Bulletins**: already cached-once-not-re-scraped by design (Enrichment step 3)
- **Meta/win-rate data**: already cached from scheduled pulls, not cumulative history
- **Rules text (`en-fab-cr.txt`)**: needs a fix — currently planned as "re-parsed each run" (overwrite), should instead be a **versioned snapshot on every fetch**, not just the latest copy
  - Protects against the source URL changing/breaking
  - Enables diffing between versions to actually detect when a rules change happened, rather than only noticing explainer drift after the fact
  - Ties directly into the Knowledge Base's ground-truth/draft distinction — if CR text changes, explainers generated against the old wording should be flagged for re-validation, which requires having the old version to diff against

## External Data Sources & Caveats
| Source | Used for | Caveat |
|---|---|---|
| [the-fab-cube/flesh-and-blood-cards](https://github.com/the-fab-cube/flesh-and-blood-cards) | Primary card data | Community-maintained, same source fabrary.net uses — reliable but volunteer-dependent |
| [tcgcsv.com](https://tcgcsv.com/) | Pricing/product data | — |
| `cardvault.fabtcg.com` (via unofficial `api.cardvault.fabtcg.com`) | Printings/product catalog | Reverse-engineered, undocumented by LSS — could change without notice; keep server-side, cached hard, mirror images to Spaces |
| `rules.fabtcg.com/txt/latest/en-fab-cr.txt` | Rules text (Knowledge Base ground truth) | Plain text, reliably parseable; archive versioned snapshots (see above) |
| `fabtcg.com/rules-and-policy-center/errata-bulletins/` | Errata Bulletins (KB ground truth) | No API — scraped once per new bulletin, then cached permanently |
| fabtcgmeta.com, fablazing.com | Win rate/tier/meta data | Scraped — terms of use need checking before caching |
| FABREC (fabrec.gg) | Staple/inclusion rate stats | No public API found — scraping candidate, terms of use needs checking |
| `Talishar/Talishar` (GitHub) | Forked game engine (Play) | **GPL-3.0** — any distributed derivative must stay open source under GPL; aligns with YaFaBa's own open-source plan, not a real constraint |

## AI / Enrichment
- Enrichment is a **scheduled batch job**, not runtime inference — the only place AI cost is incurred
- Uses the Knowledge Base (Postgres/pgvector) for retrieval-grounded card explainer generation, cross-referencing the actual CR text rather than relying on the model's trained-in knowledge
- Self Validation layer (self-play win-rate comparison for combo/synergy tags; rules cross-check for explainer grounding) runs before human spot-check, to make manual review tractable at scale
- **Optional on-device enhancement**: Apple's Foundation Models framework (iPhone 15 Pro+, free, on-device, no token cost) used only for phrasing/summarizing server-retrieved Knowledge Base content in Play Assist — not for reasoning, retrieval, or Enrichment itself. Gracefully degrades on unsupported devices via Apple's built-in availability check.

## Open Source & Licensing
- **YaFaBa's own code and API**: open source under **GPL-3.0** (see `LICENSE`)
- **Talishar fork**: GPL-3.0 obligations apply to that portion of the codebase
- Direct monetization isn't viable under LSS's fan-content IP terms regardless (no paid guides/content), so open sourcing has no real monetization downside

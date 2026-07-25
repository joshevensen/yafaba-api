# Enrichment (processing & AI)

Card-level AI processing on top of the stored data. Turns raw source data (ingested in [Data](../data/sources.md)) into the enriched dataset ([schema](../data/schema.md)): orchestration, the AI tasks and their prompts, embeddings, self-validation, and publish.

> Sequence: **Data → Enrichment → Curation → Read → Build → Play.** This phase produces card-level content (explainers, combos, synergies, KB); class/hero profiles and guides are the next phase ([Curation](../curation/README.md)).

**Design philosophy (from `app-design.md`):** AI does the hard work **once, here, on a schedule** — everything a user touches at runtime is deterministic DB lookups. No runtime inference. That's what makes this scope buildable solo.

---

## Orchestration & runtime

Runs **manually and locally** for testing, and **on a schedule with queues** in production. Same code path both ways — the schedule just invokes the same command.

- **Entry point:** an Artisan command, e.g. `php artisan enrich:run`, that **dispatches queued jobs** (it does not do the work inline). Testing flags:
  - `--only=cards,pricing` — run a subset of steps
  - `--fresh` — ignore `source_hash`/caches and recompute
  - `--dry-run` — fetch + log planned writes without persisting
  - `--card=<id>` — enrich a single card end-to-end (fast local loop)
- **Production schedule:** registered in the console schedule — a **set-release cadence** plus a **periodic full re-run** (re-run against the *whole* pool, not just new cards, to catch new-old synergies).
- **Queues (required):** each step is a queued job.
  - **Chain** the ordered phases (ingest → KB → AI → validate → publish).
  - **Batch** (`Bus::batch`) the fan-out steps — per-card explainer/tagging across thousands of cards — with a concurrency cap and a `then`/`catch`/`finally` to gate the next phase.
  - **Why:** long LLM/HTTP work off the request cycle, automatic retries/backoff, throttling to respect Anthropic + Voyage rate limits, and parallelism with a ceiling.
  - **Driver:** Redis recommended for batching/throughput in prod (database queue is fine locally). Confirm infra.
  - **Rate limiting:** `Redis::throttle` / `RateLimited` middleware on the LLM + embedding jobs.
  - **Idempotency:** every job checks `source_hash` / row `status` and skips unchanged work, so partial re-runs are safe.
  - **Failure:** per-job `tries`/`backoff`, `failed_jobs` dead-letter; **publish only runs after the validation gate passes**.

---

## Pipeline (ordered)

```
A. Ingest            rules snapshot + errata  ─┐
   (sources.md)      cards + printings + images │→ classification + hero-profile grouping
                                                │
B. Knowledge Base    chunk CR + errata + prior validated notes → embed (Voyage) → kb_documents
                                                │
C. AI enrichment     explainers (RAG over KB) → combo tagging → synergy tagging   [status: draft]
                                                │
D. Self-validation   rules-grounding check + self-play → confidence/flags → human spot-check queue
                                                │
E. Publish           promote validated rows → refresh live tables (transaction)
```

**Ordering rationale (the non-obvious dependencies):**
- **Rules + errata before explainers** — explainers must be grounded in the *current* CR, so the KB has to be built (and versioned) first.
- **Cards before printings before images** — printings FK to cards; image mirroring keys off printings.
- **Classification + hero grouping before AI steps** — tagging/explainers use class/talent/keyword context; hero grouping needs all of a character's cards present.
- **AI steps write `draft`** — nothing goes live until validation promotes it, so an error from one set can't silently compound into the next.

---

## AI tasks — prompts / skills to create

Each AI task is a **versioned prompt package** (Claude via the Laravel AI SDK) using **structured outputs** so results validate straight into their target table. These live under the project's skills convention (`**/skills/**`) — proposed layout `skills/enrichment/<task>/` (a `SKILL.md` + prompt template + output schema per task) so they're reusable and diff-able across set cycles.

| # | Task | Grounding inputs (RAG / context) | Structured output → table | Validated by |
|---|---|---|---|---|
| 1 | **Card explainer** *(hardest)* | card `functional_text` + its keywords' glossary text + top-k CR chunks (KB, `ground_truth` only) + any errata affecting the card | `explainer_text`, `cited_rules[]` → `card_explainers` (draft) | rules-grounding check (D) |
| 2 | **Combo tagging** | card + candidate partners (class/talent filter + KB vector similarity) + prior **validated** combos | `[{card_id_b, description}]` → `combo_pairs` (draft) | self-play (D) |
| 3 | **Synergy tagging** | card + `synergy_tags` vocabulary + prior validated tags | tag assignments (+ new-tag proposals) → `card_synergy_tags` / `synergy_tags` (draft) | self-play + similarity audit |
| 4 | **Explainer grounding check** *(validation)* | explainer's `cited_rules[]` + current CR snapshot + card ability text | pass/flag + confidence | deterministic + topical LLM check |
| 5 | **Hero-profile grouping confirm** | the 80–95% gray-band pairs from the deterministic similarity (see `schema.md`) | `hero_profile_grouping_status` | human / self-play |
| 6 | **Learn authoring** *(related; feeds Read)* | CR chunks + validated explainers | How-to-Play tiers, Class Guides | human spot-check |

Notes for every task:
- **Structured outputs** (schema-constrained) so the model can't return unparseable rows; validation happens at the tool-call layer, model retries on mismatch.
- **Ground, don't recall** — the explainer prompt especially must cross-reference *supplied* CR chunks, not the model's trained-in FaB knowledge (which may be outdated). This is light RAG over the rules doc, not a single-shot prompt.
- **Model choice per task** — lean higher-capability for explainers (the hardest, rules-critical step); cheaper tiers are viable for tagging. Confirm cost/quality per task.
- **Prompts are versioned** — a prompt change is a reason to re-run + re-validate affected cards.

---

## Embeddings
- **Model:** Voyage AI `voyage-3.5` → `vector(1024)`; `kb_documents.embedding_model` records provenance.
- **What gets embedded:** CR chunks, errata, prior **validated** explainer notes and combo/synergy reasoning — the enrichment-side memory the AI tasks retrieve over.
- **Runtime:** batched queued jobs with rate limiting; re-embed when `embedding_model` changes.
- **Anthropic ≠ embeddings** — Voyage is a separate API key/account from the Claude calls; provision both.

---

## Self-validation

Runs **before** the human spot-check, to make manual review tractable (flag suspects, don't review everything). Two failure modes, two checks:

- **Explainer grounding (rules cross-check).** Deterministic first: every `cited_rules` entry must exist in the current CR snapshot and be topically consistent with the card's ability. Catches hallucinated/outdated citations. LLM only judges topical consistency. Nuance/quality still needs human sampling (higher rate here — it's the hardest step).
- **Combo/synergy (self-play).** Two full-information AI agents play a tagged-combo deck vs. a baseline deck; compare win rates → `combo_pairs.self_play_win_rate_delta`. A tag with no measurable lift is **flagged** for review (catches "plausible but wrong"). Missed combos (false negatives) rely on a vector-similarity audit + human review, not self-play.
- **Output:** a confidence/flag per card/tag that prioritizes the human spot-check.
- **Trust promotion:** `draft → validated` on pass. **Only `validated` content feeds future enrichment prompts** (via `kb_documents.trust_status`) — this is what stops a set-1 error from being treated as fact by set 5.

---

## Publish
- Promote `validated` rows and refresh the live tables the Read phase queries — in a **transaction** (staging → swap, or in-place update) so readers never see a half-published state.
- Nothing is served that hasn't passed the validation gate.

---

## Open questions
- **Queue driver** — Redis vs. database in prod (infra decision).
- **Admin trigger** — is a manual production trigger/monitor endpoint needed (Read phase), or is the scheduled command + logs enough? (Cross-refs the Read README's open question.)
- **Model per task** — confirm which Claude tier per AI task (explainers vs. tagging) on cost/quality.
- **Prompt/skill home** — confirm `skills/enrichment/*` as the layout for the versioned prompt packages.
- **Learn authoring scope** — How-to-Play / Class Guides are authored here but consumed by Read; decide whether they're in the Phase-1 pipeline or a follow-on pass.

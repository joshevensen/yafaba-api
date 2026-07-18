# YaFaBa — App Design

## Overview
YaFaBa is a Flesh and Blood app built around one idea: **help a player understand their cards and their class, well enough to become a pro and stay one.** Not a card search tool, not a collection tracker first — those exist elsewhere. YaFaBa's differentiation is that everything it does is grounded in a single structured, LLM-enriched card dataset (combos, synergies, tags, rules-grounded explanations), generated once during Enrichment and reused everywhere: choosing a class, browsing cards, building a deck, and now, actually playing a match.

That single dataset is what ties the four areas together:
- **Learn** — get oriented (find a class/hero, tiered how-to-play content, class guides)
- **Explore** — look anything up (card browser with real explanations and synergy info, cached meta/standings)
- **Build** — construct, test, and acquire a deck (guided freeform deckbuilding, proxies, official registration sheets, buying the cards)
- **Play** — actually play a match (forked from Talishar's engine, mobile-native UI, deterministic in-game reminders)

The design has held to a consistent philosophy through all of this: **AI does the hard work once, at Enrichment time; everything a user actually touches at runtime is deterministic — DB lookups, cached data, rule-based suggestions.** No live AI coaching, no open-ended rules chat, no runtime inference cost. That's what makes an ambitious scope like this actually buildable by one person: the expensive part happens on a schedule, not per request.

This started as — and still is — something Josh wants to build for himself first. It's being open sourced (code and API) partly because direct monetization isn't viable under LSS's IP terms anyway, and partly because it means someone else could build on it (an Android client, for instance) without that being Josh's problem to solve. Server costs are expected to be a rounding error against what the game costs to play anyway; any Patreon/ad/affiliate revenue is a bonus that offsets hosting, not something the project depends on to be worth building.

## Platform & Distribution
- **Backend**: Laravel API
- **Client**: native Swift, iOS-only for v1 (the platform Josh actually uses — best experience for the scanner specifically via Apple's Vision/Core ML, and dogfooding on a daily-carry device). Android deferred — not a v1 commitment.
- **Open source**: code will be open sourced. Since direct monetization isn't viable under LSS's IP terms anyway, this removes the downside of opening it up, and lets someone else build an Android (or other) client without Josh needing to maintain a second codebase or learn a cross-platform framework
- **Public API**: the Laravel API will be public, not just backing the iOS app, so third-party clients (like a community Android port) have something real to build against
- **Caveats to manage, not blockers**:
  - Public traffic means real hosting/abuse-surface cost that a private-only backend wouldn't have (rate limiting needed)
  - The `cardvault.fabtcg.com` printings data (already flagged as an unofficial, reverse-engineered API) should stay server-side and cached hard — a popular public API amplifying traffic through that undocumented endpoint risks getting it noticed and blocked/changed by LSS
  - Server costs are expected to stay low relative to Patreon/ad/affiliate revenue even at modest scale, since most cost (Enrichment's LLM calls, DB, base hosting) is fixed/batch, not per-user — userbase growth should outpace cost growth, not track it

## Data Foundation
- **Card data source**: pulled from [the-fab-cube/flesh-and-blood-cards](https://github.com/the-fab-cube/flesh-and-blood-cards/tree/develop/json/english) (community-maintained structured JSON — same source fabrary.net itself runs on) and [tcgcsv.com](https://tcgcsv.com/) (pricing/product data) — not the project's original split JSON files
- **Printings/product data source**: [cardvault.fabtcg.com](https://cardvault.fabtcg.com/) via its unofficial API host (`api.cardvault.fabtcg.com`) — LSS's official print database (~25,000 unique prints), used specifically for print variations (images, finishes, layout) and product catalog data, not as the primary card-text source. Note: this API is reverse-engineered/undocumented by LSS, not a published developer API — could change without notice, so build with a fallback in mind
- **Rules text source**: [rules.fabtcg.com/txt/latest/en-fab-cr.txt](https://rules.fabtcg.com/txt/latest/en-fab-cr.txt) — plain text, numbered rules (e.g., 8.5.3b) plus glossary, directly fetchable and parseable via section splitting; the HTML rules browser is not used as a source (JS-rendered, harder to scrape reliably)
- **Errata Bulletins source**: [fabtcg.com/rules-and-policy-center/errata-bulletins/](https://fabtcg.com/rules-and-policy-center/errata-bulletins/) — no API, scraped from the index + individual bulletin articles, then cached into YaFaBa's own DB during Enrichment so bulletins are never re-scraped at request time (or repeatedly re-scraped across enrichment runs — pulled once, stored, and only re-checked for new bulletins going forward)
- **Database: Postgres with pgvector** — chosen over SQLite so the Enrichment Knowledge Base (which needs vector retrieval over rules/rulings/prior explainers) and the relational card/combo data can live in one instance, using [Laravel's AI SDK](https://laravel.com/docs/13.x/ai-sdk#introduction) for the AI-facing side of enrichment and guide generation
- Enrichment pipeline: LLM-assisted tagging run at each set release (and re-run against the full card pool, not just new cards, to catch new-old synergies) — see Enrichment section below
- Validation pass planned before tags go live (human spot-check or self-play testing)

## Enrichment (Scheduled Job)
Runs on a schedule (set release + periodic refresh), producing fully static/cached data — nothing here happens at request time.

1. **Pull latest card data** — fab-cube JSON (card fields) + tcgcsv.com (pricing/product data) + cardvault API (print variations/product catalog); download and mirror any new/changed card images to Spaces during this step, so `card_printings.image_url` always points at YaFaBa's own hosted copy, never the source directly
2. **Pull latest rules text** — en-fab-cr.txt, re-parsed each run in case of rules updates (new sets sometimes bring rules changes/errata)
3. **Pull new Errata Bulletins** — check the bulletin index for anything not already cached, scrape and store new bulletins into the DB (existing cached bulletins are never re-scraped)
4. **Card explainer generation** — LLM writes a plain-English "how this card works" note per card, grounded in the actual comprehensive rules (not just the printed card text), so explanations reflect real rules interactions (e.g., how a keyword ability actually resolves) rather than a surface paraphrase of the card
   - **Note: hardest part of the pipeline.** Harder than combo/synergy tagging — this needs the LLM to cross-reference specific CR rule numbers accurately, not rely on its own trained-in knowledge of FaB rules (which may be outdated or imprecise). Likely requires chunking the CR text and feeding relevant sections in alongside each card (light RAG over the rules doc) rather than a single-shot prompt. Needs its own careful validation pass, separate from combo/synergy tagging.
5. **Combo pair tagging** — explicit card-to-card relationships (e.g., ninja package cards)
6. **Synergy tagging** — category-based tags (e.g., "cares about instants played") in addition to pairwise combos
7. **Win rate / meta data pull** — cached from external meta sources (fabtcgmeta.com, fablazing.com) into YaFaBa's own DB; app queries only this cache, never the external sites live
   - Requires checking each source's terms of use before scraping/caching
8. **Validation pass** — human spot-check and/or self-play testing before enriched tags go live, to catch LLM tagging errors before they reach the deckbuilder
9. **Publish** — enriched data replaces/updates the live DB tables that Learn, Explore, and Build all query at runtime

## Self Validation
LLM-generated tags and explainers will contain errors — plausible-sounding but wrong combos, missed real ones, explainer notes that misstate a rule. Reviewing every card by hand doesn't scale as sets pile up, so validation needs its own automated layer that runs *before* the human spot-check, to make that spot-check tractable (flag the suspect cases instead of reviewing everything blindly).

Two separate validation problems, since combo/synergy tags and explainer text fail in different ways:

**1. Combo/synergy tag validation — via self-play**
- Two full-information AI agents (acceptable here since this is solo/internal testing, not live opponent play — unlike general CCG AI, which is hard specifically because of imperfect information)
- One deck built using the tagged combos/synergies for a given hero/archetype, one baseline deck built without regard to tags (or randomly), both otherwise legal
- Run enough games to get a meaningful sample, then compare win rates
- A tagged combo that doesn't produce a measurable performance lift over baseline gets flagged for human review rather than trusted outright — this catches "plausible but wrong" tags without needing a human to check every single one
- Doesn't catch missed combos (false negatives) — only over-confident/wrong tags (false positives). Missed combos still rely on the audit-style vector similarity pass discussed earlier, or human review

**2. Card explainer validation — via rules cross-check**
- Automated check: does the explainer's cited rule content (if it references specific mechanics) actually match what's in the current `en-fab-cr.txt`? A simple grounding check — confirm referenced rule numbers/keywords exist and are topically consistent with the card's actual ability text — catches hallucinated or outdated rules citations
- This does not verify the explanation is *well-written* or *correct in nuance* — only that it isn't fabricating rules that don't exist. Nuance still needs human spot-check sampling
- Given this is the hardest part of the enrichment pipeline (noted above), expect a higher human-review sampling rate here than for combo tags, at least until the process is proven out over a few set cycles

**Output of both**: a confidence/flag status per card or tag, so the human review pass in step 8 of Enrichment can prioritize what actually needs eyes, instead of reviewing the full card pool every release.

## Enrichment Knowledge Base
The goal: build up an internal "AI FaB expert" that the Enrichment job draws on, so each run doesn't re-derive rules interactions and card context from scratch — it gets faster and more consistent over time instead of repeating the same research every set.

**Contents:**
- Comprehensive rules text (chunked, from `en-fab-cr.txt`)
- Errata Bulletins (cached, not re-scraped) — FaB's actual equivalent to rulings/FAQs, explaining functional card changes
- Existing card ability text and prior explainer notes
- Prior combo/synergy tags and the reasoning behind them
- General rules insights that apply across many cards (e.g., how a keyword ability commonly interacts with other mechanics), not just per-card notes

**Critical distinction — ground truth vs. draft:**
- **Ground truth**: CR rules text, official rulings/FAQs — these can be trusted as authoritative inputs to future enrichment prompts
- **Draft / unvalidated**: past LLM-generated explainer notes and combo/synergy tags — these are only as good as their last validation pass (see Self Validation). If they're fed back into future enrichment runs as if they were settled fact, an error from set 1 can get treated as established truth by set 5, compounding instead of getting caught
- Every KB entry needs a status field (e.g., `ground_truth`, `validated`, `draft`) so future enrichment prompts can weight or exclude unvalidated content appropriately, rather than the LLM treating everything in the KB as equally trustworthy

**Use in Enrichment:** retrieval (RAG-style) over this KB feeds the relevant ground-truth rules + prior validated context into each card's enrichment prompt — this is separate from the Card Explorer's `en-fab-cr.txt` fetch discussed earlier; the KB is the enrichment-side memory, not something end users query directly. Lives in the same Postgres/pgvector instance as the card DB — one database, not separate infrastructure.

## Features

Organized into three sections: **Learn**, **Explore**, **Build** — matching how players actually approach a TCG (get oriented → look things up → construct and play).

### Learn
Structured, guided content for getting oriented — no card-by-card lookup required.

1. **Find Your Class**
   - Interactive questionnaire, no AI — converts the manual selection worksheet into an app-guided flow, powered entirely by DB lookups/filters against the card data
   - Steps (mirrors the standalone worksheet, each explained to the user as they answer):
     1. **Format & legality** — SAGE, CC, or both: checked as two independent flags (young/SAGE legal, adult/CC-not-Living-Legend) since a hero can clear one, the other, or both — a player isn't forced to pick a single format up front
     2. **Playstyle** — Aggro, Midrange, Control, Combo, Disruptive, Board Presence, Defensive (heroes can carry multiple tags — no forced single label)
     3. **Complexity tolerance** — scored from the hero's ability text (conditions, non-standard resources, hard dependency on specific cards) into Simple/Moderate/Complex
     4. **Cost & acquisition path** — precon availability, set concentration vs. set-spread risk, shared class-support products
     5. **Win rate / meta** — tier and sample-size-aware win rate, pulled from YaFaBa's own cached meta data (see Enrichment) — never queried live
     6. **Color/pitch lean** (optional) — real decklist-verified red/yellow/blue lean, not guessed from ability cost alone
     - Bonus: lore/flavor fit (talent-sharing and story connections)
   - Ends in a scorecard comparing surviving heroes side by side on the user's own stated priorities — app renders this instead of the user filling out a manual worksheet

2. **Class Guides**
   - Standalone learning content per class, browsable independent of deckbuilding
   - Pulls from the same card explainer data used everywhere else in the app
   - Future: Hero Guides (deeper, per-hero version of the same idea)

3. **How to Play**
   - Static, tiered onboarding content — authored once, not a live Q&A tool. Distinct from the excluded "general rules reference": this is a fixed curriculum, not open-ended rules lookup
   - Progressive disclosure structure so new players aren't overwhelmed while intermediate/advanced players still have somewhere to grow:
     - **New player tier** — core loop only: turns, attacking, defending, pitching for resources, life totals
     - **Intermediate tier** — layered concepts once basics are comfortable: priority/timing, the stack, combat chain triggers, arsenal
     - **Advanced tier** — sequencing and edge-case interactions: replacement effects, layered continuous effects, specific high-complexity keyword interactions
   - Content authored during Enrichment (grounded in the CR text, same as card explainers), not generated live per user

### Explore
On-demand lookup — go deeper on any single card than the card itself tells you.

4. **Card Explorer**
   - Browse/search/filter the full card database (by class, type, cost, talent, keyword, etc.)
   - Each card shows more than its printed text:
     - **How-to-use explainer** — plain-English notes on what the card does and when/why to play it, grounded in the comprehensive rules text, generated during the scheduled enrichment job (not live)
     - **Goes-well-with** — combo pairs and synergy-tagged cards, surfaced right on the card so a player can decide whether to add them to a deck
   - Same underlying enrichment data that powers Learn and Build — no separate dataset to maintain

5. **Meta & Standings Explorer**
   - Browse cached win rate, tier, and competitive standings data — reuses the same meta data pull already needed for Find Your Class's Step 5, so no separate dataset to maintain
   - Per-hero and per-class views (tier rank, win rate, sample size) plus, where FABREC-style data is available, staple/inclusion rate stats (which cards show up most often for a given hero) — see the earlier discussion on scraping/caching this data (needs terms-of-use check per source)
   - All cached from the scheduled Enrichment pull — no live queries to external meta sites

### Build
Constructing, testing, and acquiring an actual deck.

6. **Accounts & Saved Decks**
   - Foundational — every other Build feature (proxy, registration sheet, buy links) assumes a deck that persists across sessions
   - **Import/export** — compatible with common decklist text formats and, where feasible, LSS's own GEM tournament tool, so a deck built in YaFaBa is actually usable at a real event, not locked in
   - **Errata change notifications** — since Errata Bulletins are already cached in Enrichment, flag when a card in a user's saved deck receives functional errata. No competitor appears to do this (fab-cube flags errata in its own changelog, but that's a data-maintainer note, not a user-facing per-deck alert) — likely a genuine differentiator, not table stakes to catch up on

7. **Deckbuilder**
   - Freeform + live suggestions — you add cards yourself; the app surfaces combos/synergy as you go, rather than fabrary's filter-and-manually-add model
   - Seeded by Learn — hero/class/talent carries over automatically, so legality filtering is already applied before you touch a card
   - Suggestions panel updates on every add — pulls tagged combo pairs and synergy-tag matches for the card just added
   - Suggestions ranked by connection count — a card combo/synergizing with 3 cards already in the deck ranks above one connecting to only 1 (simple count query, no AI judgment)
   - Suggestions decay as slots fill — once a synergy cluster is well-represented, stop resurfacing more of the same tag
   - Deck-shape warnings — flags structural gaps (e.g., "0 defense reactions," "resource curve heavy at 3+") by checking simple counts against known-good ranges per archetype; a second differentiator from fabrary, still fully rule-based, no live inference
   - Class/talent legality filtering (SAGE-aware)

8. **Proxy Printing**
   - printfabproxies is being transformed into YaFaBa's proxy feature — not kept as a separate app and integrated. Its functionality becomes part of YaFaBa itself, built on the same deckbuilder/card DB.

9. **Official Deck Registration Sheet**
   - Auto-fill and print the [official LSS constructed card-pool registration sheet](https://fabtcg.com/rules-and-policy-center/card-pool-registration-sheets/) directly from a built deck — player name, hero, equipment, and pitch/card breakdown by category and level, matching the official fillable PDF format
   - Precedent exists (fabdecklist.org already does deck-to-registration-sheet generation), so this is a proven, feasible feature
   - Saves the manual step of transcribing a built deck onto the official form by hand before an event

10. **Study Sheet**
   - Printable/exportable sheet listing every card in the deck with its stats and cached explainer info, for offline study — not a new backend feature, just a different output format built entirely from data Enrichment already produces (card explainers, combo/synergy tags)
   - Lives alongside Registration Sheet and Proxy Printing as one of the export options for a finished deck

11. **Buy the Deck**
   - TCGPlayer affiliate links for cards in a built deck (indirect monetization, consistent with LSS IP terms)

12. **Collection Tracker**
   - Manual add and import (starting with Dragon Shield's export format, CSV/text) — no scanner in v1. Lets "Buy the Deck" skip cards already owned without building scanning infrastructure first
   - A YaFaBa-native scanner (image-matching against card image embeddings, not OCR — see scanning feasibility discussion) remains a plausible future addition, not a dependency for this feature to ship

Explicitly out of scope (except where needed to explain a specific card interaction):
- **Live rules reference/lookup** — no runtime AI answering open-ended rules questions. Rules knowledge instead gets baked into the app *ahead of time* through Enrichment (card explainers, guides) — so rules understanding shows up everywhere in the app, just never as a live Q&A feature
- **Strategic/matchup coaching** — no "you should block here" or "this is a bad attack" judgment calls, which would require live AI reasoning about board state. Distinct from Play Assist below, which surfaces facts about current game state, not advice about what to do with them
- Deck guides as a standalone feature (superseded by card-level explanations + class guides)

### Play
Actually playing a match — carried over from Talishar's engine, with a mobile-native frontend and YaFaBa's guidance philosophy applied to live gameplay, not just deckbuilding.

**Onboarding note**: no required path through Learn/Explore/Build before Play — the only requirement to play a game is having a deck, and a deck can be built in YaFaBa *or* imported. Full onboarding flow design deferred until the individual features are built and their real usage patterns are visible.

13. **Game Engine (forked from Talishar)**
   - Fork Talishar's backend (`Talishar/Talishar`, PHP, GPL-3.0 licensed) rather than reimplementing FaB's full rules engine from scratch — inherits years of volunteer-built rules logic (the DecisionQueue system, combat chain resolution, full card-by-card ability implementations)
   - GPL-3.0 requires any distributed derivative to stay open source under GPL — not a constraint given YaFaBa's own open-source plan, just a requirement to keep the forked engine's source open alongside the rest of the codebase
   - New frontend built from scratch — not reusing Talishar-FE's React/Redux UI, since YaFaBa's client is native Swift/mobile, not browser-based
   - Talishar's backend is poll-based (`GetNextTurn.php` — client asks for current state), not websockets — this shape is actually well-suited to async play (Long Games below): live matches poll frequently, async matches poll rarely/wait on push, same underlying mechanism

14. **Matchmaking & Social**
   - **Format + intent as separate filters** — format (SAGE/CC/Blitz) and intent (casual/competitive) are independent axes, not one combined choice, so a player can specifically look for e.g. a competitive SAGE game or a relaxed CC game
   - **Friends** — friends list + direct challenge, skips matchmaking entirely for playing a specific person
   - **Long Games (async play)** — each player acts whenever they're able, not required to be online simultaneously (motivating case: playing across time zones with family). Built on the same poll-based match-state model as live games, and reuses the same push notification pipeline already needed for errata alerts to tell a player it's their turn
   - **Nudge notifications** — a gentler reminder before the forfeit clock actually expires (e.g., partway through the window), distinct from the forfeit itself. Same push pipeline as the forfeit/turn notifications and errata alerts — just an earlier, lower-stakes nudge rather than a last-chance warning
   - **Inactivity/forfeit timers, per intent**:
     - Competitive: 24-hour forfeit window
     - Casual: 72-hour forfeit window
     - **Clock resets on every turn** — not a single match-wide window — so a match can run indefinitely (weeks or months) as long as no single gap between moves exceeds the window. This is what makes genuinely long-running async games (e.g., with family abroad) actually work, rather than risking an early forced end

15. **Mobile-Native Combat Chain UI**
   - No artificial time pressure exists in FaB (paper or Talishar) — the design problem is cognitive load (many zones/decisions to track at once), not a clock, so the UI can let a player pause and expand what they need rather than optimizing for speed
   - Collapse/expand pattern for zones (equipment slots, arsenal, banished, pitch) so the full zone count fits a phone screen without permanent clutter
   - Dedicated full-screen defense-decision flow — the hardest single moment to get right on a small screen (deciding what to pitch/block while tracking live damage math against a full hand) — large touch targets, persistent damage readout
   - **Art-only board, following Talishar's approach** — the board shows card art, not full ability text, keeping the small-screen board decluttered. Tapping a card in play surfaces its cached Card Explorer explainer instead of raw printed text — a step beyond what Talishar does, since YaFaBa has grounded explanations available, not just the text
   - Card images are YaFaBa's own hosted copies (see Tech Stack), not hotlinked — includes the required LSS attribution/copyright disclaimer wherever card images appear, per confirmed IP terms

16. **Play Assist**
   - Deterministic, state-based reminders only — not coaching. Examples: "you have a card in hand with a reaction ability," "this permanent's ability triggers now," "you haven't used your hero ability this turn"
   - Sourced directly from the forked engine's already-tracked game state — this is exposing information the engine already computed, not new AI inference at runtime, consistent with YaFaBa's deterministic-only design elsewhere
   - **Cached card explainer tie-in**: surface the same per-card "how this card works" notes from Card Explorer/Enrichment contextually during play — e.g., when a card enters hand or play, its cached explainer is available right there. Reuses existing enrichment data; no new inference needed to support this
   - Explicitly does not suggest what to do (no "you should block" or "bad attack") — surfaces facts about current state, leaves the decision entirely to the player
   - **Availability tied to match intent** — even though nothing here is strategic advice, automated reminders still remove a real part of what's being tested in competitive play (tracking your own board state correctly), unlike paper/tournament play which offers no such aid:
     - **Competitive matches**: Play Assist is off entirely, no toggle to enable it — this is the main functional difference between competitive and casual matches, alongside (hopefully) player intent itself
     - **Casual matches**: on by default, with a toggle to turn it off if a player finds it noisy rather than helpful
   - **Optional on-device enhancement**: server does the retrieval (pulls the relevant Knowledge Base snippet for the current card/trigger, same ground-truth data used everywhere else), and Apple's on-device Foundation Models framework (where available — iPhone 15 Pro+, gracefully degrades elsewhere via the built-in availability check) handles phrasing/summarizing that retrieved explanation naturally in the moment. Not on-device reasoning about strategy — just a nicer delivery of an explanation the server already determined. Enhances the existing reminder, doesn't add new functionality; works identically (just less polished) on unsupported devices. Not applicable in competitive matches, per the above.

## Competitors

**Card search / deckbuilding**
- **[fabrary.net](https://fabrary.net/)** — the closest direct competitor. Advanced filter-based card search, deckbuilding, and collection management. Filter-and-manually-add model, no synergy/combo suggestions or card explainers.
- **[fabdb.net](https://fabdb.net/)** — long-running community deckbuilder/collection manager, similar filter-based model.
- **[The Pitch Zone](http://www.thepitchzone.com/deckbuilder)** — community deckbuilder, unaffiliated with LSS.
- **[LSS GEM Deck Builder](https://fabtcg.com/articles/introducing-gem-deck-builder/)** — official, beta, tournament-registration-focused; explicitly does not do legality checks or validation yet, and imports from external tools.

**Meta / competitive data**
- **FABREC (fabrec.gg) / Spellvoid** — decklist-derived staples, format/class staples, win rates; partnered with LSS. Closest analog to the Meta & Standings Explorer idea, but no public API found.
- **[TCG Contender](https://tcgcontender.com/fab/deck-tools)** — multi-TCG (not FaB-specific) meta analysis and tier rankings, AI-powered deck analysis.
- fabtcgmeta.com, fablazing.com — tier lists (already noted as scraping candidates).

**Playing the game**
- **[Talishar.net](https://talishar.net/)** — the only established option for playing FaB online (10,000+ daily players). Browser-based, human-vs-human, PHP backend + React/TypeScript frontend, GPL-3.0 licensed, volunteer-maintained. YaFaBa's Play forks Talishar's engine rather than competing with it from scratch — differentiator is a mobile-native frontend and deterministic Play Assist reminders, not a different rules engine.
- **Felt Table** — separate client offering an actual AI opponent for solo practice, distinct from Talishar's human-vs-human model.


**Collection / scanning / mobile**
- **Dragon Shield FaB Scanner** — card scanning via image recognition, price tracking (TCGPlayer/CardMarket), collection management, trade tool, deck stat analysis (pitch curve). Broadest feature set of any competitor, but no combo/synergy intelligence, no card explainers, no guided learning content.
- **FaB Collect** (mobile) — card browsing/discovery and reference, positioned as a learning/reference tool for new players — closest existing competitor to the Learn section's intent, but not combined with deckbuilding.

**Adjacent / different category**
- **Flesh and Blood Counter** — life total / token tracker for physical play, not a card or deckbuilding tool. Not a real competitor, just adjacent in the ecosystem.

**Where YaFaBa differs from all of them:** none of the above combine guided/interactive deckbuilding (live combo/synergy suggestions, deck-shape warnings) with card-level explainers grounded in the actual rules, tiered learning content, and a class-recommendation flow, in one product built around a single enriched dataset.

## Credits
YaFaBa depends heavily on community and volunteer-maintained work, and that should be visible in the app, not just in this doc:
- **[the-fab-cube/flesh-and-blood-cards](https://github.com/the-fab-cube/flesh-and-blood-cards)** (Tyler Luce and contributors) — primary card data source
- **[tcgcsv.com](https://tcgcsv.com/)** — pricing/product data
- **cardvault.fabtcg.com** (Legend Story Studios) — printings/product catalog data
- **Talishar** (`Talishar/Talishar`, GPL-3.0, volunteer-maintained team) — the forked game engine powering Play
- Legend Story Studios — card game, rules text, errata bulletins, official assets (per the standard fan-content disclaimer: not affiliated with LSS)
- To be added as they firm up: FABREC/Spellvoid or other meta data sources actually used, once confirmed feasible


# Class Research: Warrior

*Template file — establishes the format to replicate across the other 10 classes. Feeds both the Stage 1 class-quiz scoring and the Stage 2 Warrior hero-quiz.*

---

## Class Identity

**Mechanical theme:** Weapon-based combat. Warrior cards are built around swords/axes/daggers/etc. landing hits, and around punishing the opponent's blocking decisions with attack reactions. Per fabtcg.com's own framing: *"the Warrior, Dorinthea, [has] a gameplan that includes buffing their weapon with attack reactions to surprise the opponent and leak some damage through."*

**Recurring equipment keywords tied to the class (from official rules glossary):**
- **Battleworn** — equipment that accumulates -1{d} counters after defending (equipment wears down over the game).
- **Blade Break** — equipment that's destroyed after defending once (single-use defensive tool).
- **Temper** — equipment that accumulates -1{d} counters after defending, and is destroyed once defense hits zero (a slower version of Blade Break).
- **"Unity"** — not an official rules keyword, but a recurring card-text label on Warrior equipment: bonus for defending together with a card from hand (rewards multi-card defense).

These four patterns tell you Warrior's defensive identity: gear that's genuinely useful but degrades — you're not meant to block forever with the same piece, which pushes the class toward a proactive, weapon-swinging game rather than a pure turtle strategy.

**Complexity pattern:** Warrior heroes in this dataset skew simple-to-moderate. Most have a single conditional ability (a threshold, a trigger-once-per-turn effect) rather than multi-resource engines — the exception is Boltyn, whose Light talent adds real complexity on top of a otherwise-simple class (see hero table below).

**Resource/color lean:** No single fixed lean — varies by hero based on how expensive their core loop is (see Hero Table). Verify per-hero using the real precon decklist, not the class as a whole (learned this the hard way with Hala).

---

## Full Hero Roster (Warrior class, all printings in dataset)

| Hero | Young/Adult | Talent | SAGE legal? | Rarity (lowest) | Notes |
|---|---|---|---|---|---|
| Boltyn | Young | Light | Yes | Token | |
| Ser Boltyn, Breaker of Dawn | Adult | Light | No (adult, CC only) | Token | |
| Dorinthea | Young | — | Yes | Token | |
| Dorinthea Ironsong | Adult | — | No (adult, CC only) | Token | |
| Dorinthea, Quicksilver Prodigy | Young (alt) | — | Yes | Basic | Second young printing; ability is Dawnblade-Resplendent-specific (narrower than base Dorinthea) |
| Emperor, Dracai of Aesir | Young | Royal, Draconic | **No** | Legendary | Dual-class: Warrior **and** Wizard. Young in name but not SAGE-legal — flag for the Wizard research file too. Mono-red deckbuilding restriction. |
| Fang | Young | Royal, Draconic | Yes | Token | |
| Fang, Dracai of Blades | Adult | Royal, Draconic | No (adult, CC only) | Majestic | |
| Hala | Young | — | Yes | Basic | |
| Hala, Bladesaint of the Vow | Adult | — | No (adult, CC only) | Majestic | |
| Hala Goldenhelm | — | — | No | Majestic | **Mentor**-type card, not a playable hero — exclude from hero roster, but relevant lore/flavor reference |
| Kassai | Young | — | Yes | Token | |
| Kassai of the Golden Sand | Adult | — | No (adult, CC only) | Majestic | |
| Kassai, Cintari Sellsword | Young (alt) | — | Yes | Rare | Second young printing; weapon-attack-count payoff (Copper tokens) |
| Minerva Themis | — | — | No | Majestic | **Mentor**-type card, not a playable hero — exclude |
| Olympia | Young | — | Yes | Token | |
| Olympia, Prized Fighter | Adult | — | No (adult, CC only) | Majestic | |

**Playable SAGE hero count: 8** (Boltyn, Dorinthea, Dorinthea Quicksilver Prodigy, Fang, Hala, Kassai, Kassai Cintari Sellsword, Olympia). Emperor excluded despite "Young" type — not SAGE legal.

---

## Ability Text (all playable young heroes)

**Boltyn:**
> If you've charged this turn, your attacks get +1{p} while defended by an attack action card.
> Attack Reaction – Banish a card from Boltyn's soul: Target attack with {p} greater than its base {p} gains go again.

**Dorinthea:**
> Once per turn Effect – When a weapon you control hits, you may attack an additional time with that weapon this turn.

**Dorinthea, Quicksilver Prodigy:**
> The first time your Dawnblade, Resplendent's attack gets go again each turn, you may attack an additional time with it this turn.

**Fang:**
> Whenever you hit a marked hero, create a Fealty token.
> If you control 3 or more Fealty tokens, dagger attacks cost you {r} less to activate.

**Hala:**
> Action – {r}{r}{r}, {t}: Sharpen target sword you control. Go again.

**Kassai:**
> If you've drawn a card this turn, your sword attacks cost {r} less to activate.
> Once per Turn Action – Banish 2 red and 2 yellow cards from your graveyard: The next time a weapon you control hits a hero this turn, create a Gold token. Go again.

**Kassai, Cintari Sellsword:**
> Your second sword attack each turn costs {r} less.
> At the beginning of your end phase, if you have attacked 2 or more times with weapons this turn, create a Copper token for each weapon attack that hit.

**Olympia:**
> The first time each of your attacks wins a wager, create a Gold token.

---

## Complexity Scoring (using the Stage 1/2 test: +1 per condition, +1 per non-standard resource/zone, +2 if dependent on specific external cards)

| Hero | Score | Rating | Reasoning |
|---|---|---|---|
| Boltyn | 5 | Complex | Charge condition (+1), soul-zone dependency (+1), attack-already-boosted condition (+1), requires external charge-enabler cards to function at all (+2) |
| Dorinthea | 1 | Simple | Single trigger condition, no external dependency |
| Dorinthea, Quicksilver Prodigy | 2 | Moderate | Trigger condition + hard-locked to a specific named weapon (Dawnblade, Resplendent) |
| Fang | 2 | Moderate | Marked-hero trigger + 3-token threshold |
| Hala | 2 | Moderate | Repeatable resource-tax condition (3 resources/turn) + counter-tracking (sharpen) |
| Kassai | 3 | Moderate–Complex | Draw-this-turn condition + graveyard-banish cost + token payoff |
| Kassai, Cintari Sellsword | 2 | Moderate | Attack-count condition + end-phase token payoff |
| Olympia | 1 | Simple | Single wager-win trigger |

---

## Cost / Acquisition Notes

| Hero | Precon | Set concentration |
|---|---|---|
| Boltyn | Chapter Deck (SBL) + Armory Deck (ASB) | Spread across both precons, cheap |
| Dorinthea | Chapter Deck (SDO) | Card pool spans SDO + several reprint sets (SLY, SBL, SGB, MPW, DDD) — moderate spread but mostly cheap generic reprints |
| Fang | None | Concentrated in The Hunted (pricier set) |
| Hala | Armory Deck (AHA) | Mostly self-contained in AHA, zero overlap with Dorinthea's precon despite same class |
| Kassai | None found | Not researched this pass |
| Olympia | Armory Deck (mentioned in fabfoundry listing) | Not fully researched this pass |

---

## Open Research Gaps (flag before calling this file "done")

- Kassai and Olympia precon/cost details not fully verified this pass.
- Dorinthea, Quicksilver Prodigy and Kassai, Cintari Sellsword — archetype tags (aggro/midrange/etc.) not checked against fabtcg.com/hero-selection.
- Win-rate/tier data only confirmed for Dorinthea (54.9%, A-tier) and Olympia (50.9%, B-tier) — the rest are either too new (Hala) or not checked (Boltyn was checked: 50.1-50.3%, B-tier; Fang was checked: 52.7-52.8%, B-tier; Kassai and the two alt-young versions not checked).
- Emperor, Dracai of Aesir needs cross-listing in the Wizard research file (dual-class).

---

## Feeds into: Stage 2 Warrior Quiz question ideas

1. Format check (all 8 heroes are SAGE-young except Emperor, who isn't SAGE-legal at all — filter him out immediately if the person is SAGE-only).
2. Complexity tolerance → routes to Simple (Dorinthea, Olympia) / Moderate (Fang, Hala, Kassai, Kassai-alt, Dorinthea-alt) / Complex (Boltyn).
3. "Do you want a hero already supported by a cheap precon?" → Boltyn/Dorinthea/Hala yes, Fang/Kassai/Olympia no (or unresearched).
4. Lore tiebreaker → Solana/Hand of Sol trio (Boltyn, Dorinthea, Hala — Hala is literally Dorinthea's mentor) vs. Volcor/Cintari (Kassai) vs. Deathmatch Arena (Olympia) vs. Draconic/Royal (Fang).
5. "Do you want the two 'variant' young printings surfaced as separate options, or folded into their base hero?" — Dorinthea, Quicksilver Prodigy and Kassai, Cintari Sellsword are narrower/harder-mode versions of Dorinthea and Kassai respectively; decide whether Stage 2 treats them as 8 distinct options or offers them as an "advanced variant" toggle under their base hero.

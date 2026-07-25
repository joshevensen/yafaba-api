# Play

Actual match play: forked Talishar engine, matchmaking, mobile combat chain UI, Play Assist. Largest and riskiest phase — everything else should be stable before starting this one. Sequence: **Data → Enrichment → Curation → Read → Build → Play.** Schema is a **draft**: [`schema.md`](./schema.md).

## Scope
- `matches`, `match_state`
- Talishar engine fork/port
- Matchmaking & Social (friends, Long Games, nudge/forfeit notifications — built on Build's `friendships`/`notifications`)
- Play Assist (deterministic reminders, KB tie-in)
- Open question: does Play need a full turn-by-turn event log, or is `match_state`'s snapshot sufficient?

## Status
Planning

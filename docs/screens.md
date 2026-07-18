# YaFaBa — Screen Inventory

First pass at every screen implied by the app design doc, organized by area. Some entries are full screens; a few are noted as overlays/components rather than standalone screens. This is a starting list to react to, not final.

## Cross-Cutting
- **Home / Tab Bar** — entry point, likely the four areas (Learn / Explore / Build / Play) as tabs or a launcher
- **Sign In / Sign Up** — account creation, needed before Saved Decks or Play work
- **Account / Profile** — basic account settings
- **Notifications** — errata alerts on saved decks, and whatever Play notifications end up being
- **Settings** — app-level preferences

## Learn

**Find Your Class**
- Intro screen (explains the process)
- Question screens — one screen per step (Steps 1–6)
- Scorecard / Results screen (comparing surviving heroes)

**Class Guides**
- Class Guide list/browse screen
- Class Guide detail screen

**How to Play**
- Tier selector (New / Intermediate / Advanced)
- Lesson list screen (per tier)
- Lesson detail screen

## Explore

**Card Explorer**
- Search/browse screen (filters: class, type, cost, talent, keyword)
- Card Detail screen (explainer, goes-well-with, printings, legality, price)

**Meta & Standings Explorer**
- Standings/tier list screen
- Hero meta detail screen (win rate, staples/inclusion rate)

## Build

**Accounts & Saved Decks**
- My Decks list screen
- Import Deck screen
- Export Deck screen (or an export action from deck detail, not necessarily its own screen)

**Deckbuilder**
- Deck Builder screen (main freeform build UI — card search/add + live suggestions panel)
- Deck-shape warnings — likely a panel/banner within Deck Builder, not a separate screen

**Deck Actions**
- One consolidated screen for everything you do with a finished deck: Official Registration Sheet, Proxy Printing, Study Sheet, standard format export (for other tools), and Buy the Deck (TCGPlayer links) — all live here as options rather than being scattered across separate screens

**Collection Tracker**
- Collection list screen
- Add/Import Collection screen

## Play

**Game Engine / Lobby**
- Play home/lobby screen
- Find Opponent screen (matchmaking — design pending social discussion)

**In-Game**
- Game Board screen (main combat UI)
- Defense Decision screen (dedicated full-screen moment)
- Play Assist — overlay/panel within Game Board, not a separate screen

## Open Questions
- Lobby/matchmaking screens depend on the social model discussion still to come

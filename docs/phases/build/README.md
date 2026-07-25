# Build

Deckbuilding, collection, and deck export/acquisition features. Write-heavy user data, no game engine. Sequence: **Data → Enrichment → Curation → Read → Build → Play.**

Full table definitions: [`schema.md`](./schema.md).

## Scope
- Social: `friendships`, `notifications` (moved here from Read — social lives with decks)
- Decks & collection: `decks`, `deck_cards`, `deck_errata_flags`, `collection_items`
- Deckbuilder logic (suggestions, deck-shape warnings), Proxy Printing, Registration Sheet, Study Sheet, Buy the Deck
- Errata change notifications (built on `notifications`)

> Depends on `users` (Read) and the card core (Data).

## Status
Planning

# YaFaBa API

**YaFaBa** ("Yet Another Flesh and Blood App") is the open-source Laravel API powering a mobile app for [Flesh and Blood](https://fabtcg.com/) built around one idea: help a player understand their cards and their class well enough to become a pro and stay one.

Rather than being another card search tool or collection tracker, YaFaBa is built on a single structured, LLM-enriched card dataset — generated once during a scheduled Enrichment pipeline and reused everywhere: choosing a class, browsing cards, building a deck, and playing a match. Everything a user touches at runtime is deterministic (DB lookups, cached data, rule-based suggestions); the AI work happens once, on a schedule, not per request.

This repository is the backend API. It's public — not just to back the companion iOS client, but so third-party clients (an Android port, for instance) have something real to build against.

## Project docs

The `docs/` directory has the full picture — read these before diving into implementation:

- [`docs/app-design.md`](docs/app-design.md) — product philosophy, features, and how the four areas (Learn, Explore, Build, Play) fit together
- [`docs/tech-stack.md`](docs/tech-stack.md) — infrastructure, hosting, and technology choices
- [`docs/data-schema.md`](docs/data-schema.md) — database schema (Postgres + pgvector)
- [`docs/screens.md`](docs/screens.md) — client screen inventory

## Contributing

This project is young and still taking shape — expect things to move. If you're interested in contributing, start by reading `docs/app-design.md` to understand the philosophy and scope, then open an issue to discuss before sending a large PR. Small, focused PRs are easiest to review.

## Credits & Data Sources

YaFaBa depends heavily on community and volunteer-maintained work:

- **[the-fab-cube/flesh-and-blood-cards](https://github.com/the-fab-cube/flesh-and-blood-cards)** (Tyler Luce and contributors) — primary structured card data source (the same source [fabrary.net](https://fabrary.net/) runs on)
- **[tcgcsv.com](https://tcgcsv.com/)** — card pricing and product data
- **cardvault.fabtcg.com** (Legend Story Studios) — official print/product catalog data
- **[rules.fabtcg.com](https://rules.fabtcg.com/)** — comprehensive rules text, used as ground truth for card explainers
- **[Talishar](https://github.com/Talishar/Talishar)** (GPL-3.0, volunteer-maintained) — the forked game engine powering match play
- **Legend Story Studios** — Flesh and Blood, rules text, errata bulletins, and official card assets

Legend Story Studios' errata bulletins are cached, not scraped live, per the Enrichment pipeline described in the docs.

## Legal

YaFaBa is a fan project and is in no way affiliated with Legend Story Studios. Flesh and Blood™ and set names are trademarks of Legend Story Studios®. Card images and text are © Legend Story Studios.

## License

This repository is licensed under [GPL-3.0](LICENSE), consistent with the Talishar-derived game engine's own GPL-3.0 license.

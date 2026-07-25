# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Data phase database schema: 18 migrations defining tables for cards, card types, classes, talents, keywords, heroes, hero profiles, card printings, card legality, precons, rules text versions, errata bulletins, meta snapshots, and staple stats, with foreign keys, indexes, and constraints. Includes a feature test validating the full schema (#10).
- `php artisan data:ingest-cards` command to ingest Flesh and Blood card data from the-fab-cube/flesh-and-blood-cards, populating cards, card types, classes, talents, keywords, legality, and hero/hero profile seed rows via idempotent upserts keyed on a new `cards.source_id` column (#11).
- `php artisan data:ingest-pricing` command to refresh market pricing from tcgcsv.com per-set CSV data, updating `card_printings.price_cache` and `price_updated_at` by matching the printed card number against `cardvault_print_id`, with a `--category` option for per-category ingestion. Adds the supporting `CardPrinting` model and `CardFactory`/`CardPrintingFactory` factories (#12).

[Unreleased]: https://github.com/joshevensen/yafaba-api/compare/HEAD...HEAD

---
name: synergy-tagging
description: "Versioned prompt package for the synergy tagging AI task: assigns a card draft card_synergy_tags rows against the established synergy_tags vocabulary, and proposes new tags, via a structured-output Claude call."
version: v0
---

# Synergy Tagging

Produces draft `card_synergy_tags` rows for one card, plus proposed `synergy_tags` rows for genuinely new themes, via a structured-output Claude call (`App\Jobs\Enrichment\TagCardSynergies`).

## Grounding inputs (in the order they appear in `prompt.md`)

1. **Target card** — the card's `name`, card type, and `functional_text`.
2. **Established synergy tag vocabulary** — `synergy_tags` rows with `status = 'approved'`, each rendered as `- [{id}] {name}: {description}`.
3. **Prior validated synergy examples** — chunks retrieved from `kb_documents` via a generalized `KbRetriever` (`TRUST_VALIDATED` + `SOURCE_SYNERGY`).

## Propose only from the supplied vocabulary

Every `synergy_tag_id` in the response's `tags` array must be the exact `id` of a tag that appears in the supplied vocabulary block — never recall a tag name from the model's own trained-in Flesh and Blood knowledge, which may be stale or simply wrong relative to the current vocabulary. A theme not already covered by the supplied vocabulary must go through `new_tags`, not `tags`.

## Output contract

Structured output via a single forced tool call (`emit_result`), schema in `schema.json`:

- `tags` (array of `{synergy_tag_id}`) — `synergy_tag_id` is the exact UUID of a supplied vocabulary tag.
- `new_tags` (array of `{name, description}`) — a proposal for a genuinely new tag not covered by the supplied vocabulary.

New-tag proposals land as unvalidated `status = 'proposed'` `synergy_tags` rows, not established vocabulary — they are not offered to later cards until a separate validation step promotes them to `approved`.

## Versioning

`version:` above and `ENRICHMENT_PROMPT_VERSION` move together. Any edit to `prompt.md` or `schema.json` requires bumping both — `TagCardSynergies`'s idempotency guard compares `card_synergy_tags.prompt_version` against the run's configured `ENRICHMENT_PROMPT_VERSION` and regenerates on a mismatch, so an unbumped version silently keeps serving tags generated against the old prompt.

## Placeholders (`prompt.md`)

`{{card_name}}`, `{{card_type}}`, `{{functional_text}}`, `{{synergy_tag_vocabulary}}`, `{{validated_synergy_examples}}` — all substituted by `App\Services\Enrichment\SynergyTaggingPromptBuilder`. Empty grounding inputs render as the literal `(none)` rather than an empty string, so the model always sees an explicit signal that a section has nothing.

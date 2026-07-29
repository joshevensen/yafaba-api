---
name: combo-tagging
description: "Versioned prompt package for the combo pair tagging AI task: proposes draft combo_pairs rows for a card, grounded in candidate partner cards and prior validated combo examples, via a structured-output Claude call."
version: v0
---

# Combo Tagging

Produces `combo_pairs.card_id_b` + `.description` draft rows for one card via a structured-output Claude call (`App\Jobs\Enrichment\TagCardCombos`).

## Grounding inputs (in the order they appear in `prompt.md`)

1. **Target card** — the card's `name`, card type, and `functional_text`.
2. **Candidate partner cards** — class/talent-filtered cards ranked by embedding similarity to the target card, retrieved by `App\Services\Enrichment\ComboCandidateRetriever`.
3. **Prior validated combo examples** — chunks retrieved from `kb_documents` via a generalized `KbRetriever`.

## Propose only from the supplied candidates

The model must propose combos only between the target card and cards that appear in the supplied candidate list — never recall a card partnership from its own trained-in Flesh and Blood knowledge, which may be stale or simply wrong relative to the current card pool. Every `card_id_b` in the response must be the exact `id` of a card that appears in the candidate block.

## Output contract

Structured output via a single forced tool call (`emit_result`), schema in `schema.json`:

- `combos` (array of `{card_id_b, description}`) — `card_id_b` is the exact UUID of a supplied candidate card; `description` is a one-or-two-sentence plain-English explanation of why the two cards combo.

## Versioning

`version:` above and `ENRICHMENT_PROMPT_VERSION` move together. Any edit to `prompt.md` or `schema.json` requires bumping both — `TagCardCombos`'s idempotency guard compares `combo_pairs.prompt_version` against the run's configured `ENRICHMENT_PROMPT_VERSION` and regenerates on a mismatch, so an unbumped version silently keeps serving combos generated against the old prompt.

## Placeholders (`prompt.md`)

`{{card_name}}`, `{{card_type}}`, `{{functional_text}}`, `{{candidate_cards}}`, `{{validated_combo_examples}}` — all substituted by `App\Services\Enrichment\ComboTaggingPromptBuilder`. Empty grounding inputs render as the literal `(none)` rather than an empty string, so the model always sees an explicit signal that a section has nothing.

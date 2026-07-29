---
name: card-explainer
description: "Versioned prompt package for the card explainer AI task: generates a plain-English 'how this card works' explanation grounded in a card's functional text, its keywords' glossary text, ground_truth KB chunks, and any affecting errata."
version: v0
---

# Card Explainer

Produces `card_explainers.explainer_text` + `cited_rules[]` for one card via a structured-output Claude call (`App\Jobs\Enrichment\GenerateCardExplainer`). This is the hardest, most rules-critical Enrichment AI task — explainer grounding validation (a later pipeline step) depends on its output being genuinely grounded, not recalled.

## Grounding inputs (in the order they appear in `prompt.md`)

1. **`functional_text`** — the card's official rules text (`cards.functional_text`).
2. **Keyword glossary** — each keyword on the card, its glossary `explainer` text (falling back to `rules_text`).
3. **KB chunks** — the top-k `kb_documents` rows with `trust_status = ground_truth` (Comprehensive Rules + errata chunks only — never `validated` prior AI output), retrieved by vector similarity against the card's functional text + keyword glossary.
4. **Errata** — any `errata_bulletins` row whose `affected_card_ids` lists this card.

## Ground, don't recall

The model must cross-reference the *supplied* CR chunks, not its own trained-in Flesh and Blood knowledge, which may be stale relative to the current rules. `cited_rules` may only contain rule numbers that appear as a source reference in the supplied KB chunks block — never a rule number recalled from the model's training.

## Output contract

Structured output via a single forced tool call (`emit_result`), schema in `schema.json`:

- `explainer_text` (string) — the plain-English explanation.
- `cited_rules` (array of strings) — CR rule numbers supporting the explanation.

## Versioning

`version:` above and `ENRICHMENT_PROMPT_VERSION` move together. Any edit to `prompt.md` or `schema.json` requires bumping both — `GenerateCardExplainer`'s idempotency guard compares `card_explainers.prompt_version` against the run's configured `ENRICHMENT_PROMPT_VERSION` and regenerates on a mismatch, so an unbumped version silently keeps serving explainers generated against the old prompt.

## Placeholders (`prompt.md`)

`{{card_name}}`, `{{card_type}}`, `{{functional_text}}`, `{{keyword_glossary}}`, `{{kb_chunks}}`, `{{errata}}` — all substituted by `App\Services\Enrichment\CardExplainerPromptBuilder`. Empty grounding inputs render as the literal `(none)` rather than an empty string, so the model always sees an explicit signal that a section has nothing.

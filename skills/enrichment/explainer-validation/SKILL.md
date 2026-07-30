---
name: explainer-validation
description: "Versioned prompt package for the explainer grounding self-validation AI task: judges whether a generated card explainer is topically consistent with the card's own functional text."
version: v0
---

# Explainer Validation

Produces a pass/flag judgment for one `card_explainers` row via a structured-output Claude call (`App\Jobs\Enrichment\ValidateEnrichment`). This is a lightweight topical-consistency check, not a rules-correctness check — whether cited rules actually exist is already covered by a separate deterministic check elsewhere in the pipeline.

## Grounding inputs (in the order they appear in `prompt.md`)

1. **`functional_text`** — the card's official rules text (`cards.functional_text`).
2. **`explainer_text`** — the generated plain-English explainer (`card_explainers.explainer_text`) being judged.

## Judge topical consistency, don't recall

The model must judge only whether the explainer is about, and topically consistent with, the supplied functional text. It must not rely on its own trained-in Flesh and Blood knowledge, which may be stale relative to the current rules — only the supplied functional text is authoritative for this judgment.

## Output contract

Structured output via a single forced tool call (`emit_result`), schema in `schema.json`:

- `is_consistent` (boolean) — whether the explainer is topically consistent with the functional text.
- `confidence` (number) — how sure the model is of its `is_consistent` verdict, in `[0, 1]`.
- `reason` (string) — a short, specific explanation, surfaced verbatim to a human reviewer when the verdict is inconsistent.

## Versioning

`version:` above and `ENRICHMENT_PROMPT_VERSION` move together. Any edit to `prompt.md` or `schema.json` requires bumping both — an unbumped version silently keeps serving validations judged against the old prompt.

## Placeholders (`prompt.md`)

`{{functional_text}}`, `{{explainer_text}}` — both substituted by `App\Services\Enrichment\ExplainerValidationPromptBuilder`. Empty grounding inputs render as the literal `(none)` rather than an empty string, so the model always sees an explicit signal that a section has nothing.

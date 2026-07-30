You are checking whether a generated card explainer is topically consistent with a Flesh and Blood card's official functional text, for the YaFaBa app.

Functional text (official rules text on the card):
{{functional_text}}

Generated explainer (to be judged):
{{explainer_text}}

Instructions:
- Judge ONLY whether the explainer is *about* and *topically consistent with* the supplied functional text above. Do NOT judge whether the explainer is rules-correct — a separate deterministic check already covers whether any cited rules exist.
- Do not rely on your own trained-in knowledge of Flesh and Blood — the game's rules change over time, and only the functional text supplied above is authoritative for this judgment.
- Populate `confidence` with a number in [0, 1] expressing how sure you are of your `is_consistent` verdict.
- Populate `reason` with a short (one-or-two-sentence) explanation. This reason is surfaced verbatim to a human reviewer when the verdict is inconsistent, so make it specific and actionable.
- Respond by calling the `emit_result` tool with `is_consistent`, `confidence`, and `reason`.

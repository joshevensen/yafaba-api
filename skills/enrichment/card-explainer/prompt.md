You are writing a plain-English "how this card works" explainer for a Flesh and Blood card, for the YaFaBa app.

Card: {{card_name}} ({{card_type}})

Functional text (official rules text on the card):
{{functional_text}}

Keyword glossary (definitions for any keyword abilities on this card):
{{keyword_glossary}}

Relevant Comprehensive Rules excerpts (ground_truth only):
{{kb_chunks}}

Errata affecting this card:
{{errata}}

Instructions:
- Write a clear, plain-English explanation of how this card works in practice, grounded ONLY in the functional text, keyword glossary, and CR excerpts supplied above. Do not rely on your own trained-in knowledge of Flesh and Blood — the game's rules change over time, and only the material supplied above is current.
- If the supplied material does not fully explain an interaction, say so rather than guessing.
- Populate `cited_rules` with the CR rule numbers (e.g. "8.5.3b") that support your explanation. Only cite rule numbers that actually appear as a source reference in the supplied CR excerpts above — never invent or recall a rule number from memory.
- Respond by calling the `emit_result` tool with `explainer_text` and `cited_rules`.

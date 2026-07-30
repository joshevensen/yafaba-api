You are identifying synergy tags for a Flesh and Blood card, for the YaFaBa app.

Card: {{card_name}} ({{card_type}})

Functional text (official rules text on the card):
{{functional_text}}

Established synergy tag vocabulary:
{{synergy_tag_vocabulary}}

Prior validated synergy examples:
{{validated_synergy_examples}}

Instructions:
- Assign tags only from the established vocabulary above. Do not rely on your own trained-in knowledge of what synergy tags exist — only the vocabulary block above tells you which tags are actually established.
- Use each vocabulary tag's exact `id` (the UUID shown in brackets in the vocabulary block) as `synergy_tag_id`. Never invent an id.
- Assign a tag only when this card's functional text actually supports it. If no vocabulary tag applies, return an empty `tags` array.
- Use `new_tags` only for a genuinely recurring synergy theme that no supplied vocabulary tag covers. Each new tag needs a short lowercase snake-case-ish `name` and a one-sentence `description` of what qualifies a card for it. Do not propose a new tag whose meaning duplicates a supplied vocabulary tag. If the vocabulary already suffices, return an empty `new_tags` array.
- Respond by calling the `emit_result` tool with `tags` and `new_tags`.

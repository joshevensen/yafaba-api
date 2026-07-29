You are identifying card combos for a Flesh and Blood card, for the YaFaBa app.

Card: {{card_name}} ({{card_type}})

Functional text (official rules text on the card):
{{functional_text}}

Candidate partner cards (class/talent-filtered, ranked by similarity to this card):
{{candidate_cards}}

Prior validated combo examples:
{{validated_combo_examples}}

Instructions:
- Propose combos only between this card and cards in the candidate list above. Do not rely on your own trained-in knowledge of Flesh and Blood card partnerships — only the material supplied above tells you which cards are actually available to combo with.
- Use each candidate's exact `id` (the UUID shown in brackets in the candidate block) as `card_id_b`. Never propose a card that is not in the candidate list.
- Never propose this card (the target card) as its own partner.
- For each genuine combo, write a one-or-two-sentence plain-English `description` of why the two cards combo, grounded in the functional text of this card and the candidate's functional text supplied above. Do not rely on trained-in knowledge of the interaction itself.
- If no genuine combo exists among the candidates, return an empty `combos` array.
- Respond by calling the `emit_result` tool with `combos`.

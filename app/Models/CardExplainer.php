<?php

namespace App\Models;

use Database\Factories\CardExplainerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['card_id', 'explainer_text', 'cited_rules', 'status', 'generated_at', 'validated_at', 'source_hash', 'prompt_version', 'grounding_confidence', 'grounding_flag_reason', 'published_at'])]
class CardExplainer extends Model
{
    /** @use HasFactory<CardExplainerFactory> */
    use HasFactory;

    protected $table = 'card_explainers';

    protected $primaryKey = 'card_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cited_rules' => 'array',
            'generated_at' => 'datetime',
            'validated_at' => 'datetime',
            'grounding_confidence' => 'float',
            'published_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}

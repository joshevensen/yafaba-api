<?php

namespace App\Models;

use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'name', 'card_type_id', 'pitch_value', 'cost', 'power', 'defense', 'functional_text', 'hero_profile_id', 'age', 'source_id', 'source_hash', 'updated_at'])]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, HasUuids;

    protected $table = 'cards';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_at';

    public function cardType(): BelongsTo
    {
        return $this->belongsTo(CardType::class);
    }

    public function heroProfile(): BelongsTo
    {
        return $this->belongsTo(HeroProfile::class);
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(CardClass::class, 'card_classes', 'card_id', 'class_id');
    }

    public function talents(): BelongsToMany
    {
        return $this->belongsToMany(Talent::class, 'card_talents', 'card_id', 'talent_id');
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'card_keywords', 'card_id', 'keyword_id');
    }

    public function legalities(): HasMany
    {
        return $this->hasMany(CardLegality::class);
    }
}

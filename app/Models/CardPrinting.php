<?php

namespace App\Models;

use Database\Factories\CardPrintingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'card_id', 'set_code', 'rarity', 'finish', 'art_variant', 'image_url', 'cardvault_print_id', 'price_cache', 'price_updated_at'])]
class CardPrinting extends Model
{
    /** @use HasFactory<CardPrintingFactory> */
    use HasFactory, HasUuids;

    protected $table = 'card_printings';

    public $timestamps = false;

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cache' => 'decimal:2',
            'price_updated_at' => 'datetime',
        ];
    }
}

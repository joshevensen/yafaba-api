<?php

namespace App\Models;

use Database\Factories\StapleStatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'hero_id', 'card_id', 'inclusion_rate', 'source', 'fetched_at'])]
class StapleStat extends Model
{
    /** @use HasFactory<StapleStatFactory> */
    use HasFactory, HasUuids;

    protected $table = 'staple_stats';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'inclusion_rate' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }

    public function heroCard(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'hero_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'card_id');
    }
}

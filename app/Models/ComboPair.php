<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'card_id_a', 'card_id_b', 'description', 'status', 'self_play_win_rate_delta'])]
class ComboPair extends Model
{
    use HasUuids;

    protected $table = 'combo_pairs';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'self_play_win_rate_delta' => 'float',
        ];
    }

    public function cardA(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'card_id_a');
    }

    public function cardB(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'card_id_b');
    }
}

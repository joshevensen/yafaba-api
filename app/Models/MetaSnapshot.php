<?php

namespace App\Models;

use Database\Factories\MetaSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['hero_id', 'format', 'win_rate', 'sample_size', 'source', 'fetched_at'])]
class MetaSnapshot extends Model
{
    /** @use HasFactory<MetaSnapshotFactory> */
    use HasFactory, HasUuids;

    protected $table = 'meta_snapshots';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'win_rate' => 'decimal:4',
            'sample_size' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function heroCard(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'hero_id');
    }
}

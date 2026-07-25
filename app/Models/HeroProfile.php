<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'hero_id', 'label', 'pattern_summary', 'complexity_score', 'complexity_rating', 'playstyle_tags', 'pitch_lean', 'notes'])]
class HeroProfile extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'playstyle_tags' => 'array',
        ];
    }

    public function hero(): BelongsTo
    {
        return $this->belongsTo(Hero::class);
    }
}

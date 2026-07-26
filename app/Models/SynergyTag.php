<?php

namespace App\Models;

use Database\Factories\SynergyTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['id', 'name', 'description'])]
class SynergyTag extends Model
{
    /** @use HasFactory<SynergyTagFactory> */
    use HasFactory, HasUuids;

    protected $table = 'synergy_tags';

    public $timestamps = false;

    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_synergy_tags', 'synergy_tag_id', 'card_id')->withPivot('status');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'name', 'lore', 'flavor', 'notes'])]
class Hero extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function profiles(): HasMany
    {
        return $this->hasMany(HeroProfile::class);
    }
}

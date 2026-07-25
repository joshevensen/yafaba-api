<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'abbreviation', 'rules', 'deck_configuration', 'link', 'display_order', 'notes'])]
class Format extends Model
{
    protected $table = 'formats';

    public $timestamps = false;

    public function legalities(): HasMany
    {
        return $this->hasMany(CardLegality::class);
    }
}

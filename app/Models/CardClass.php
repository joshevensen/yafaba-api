<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Models the `classes` lookup table (NOT the `card_classes` pivot table).
 *
 * Named `CardClass` instead of `Class` because `Class` is a reserved word in PHP.
 */
#[Fillable(['name', 'mechanical_theme', 'complexity_pattern', 'resource_lean', 'description', 'notes'])]
class CardClass extends Model
{
    protected $table = 'classes';

    public $timestamps = false;
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description', 'display_order', 'notes'])]
class CardType extends Model
{
    protected $table = 'card_types';

    public $timestamps = false;
}

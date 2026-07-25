<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description', 'notes'])]
class Talent extends Model
{
    protected $table = 'talents';

    public $timestamps = false;
}

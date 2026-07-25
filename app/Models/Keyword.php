<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'rules_text', 'explainer', 'cited_rules', 'notes'])]
class Keyword extends Model
{
    protected $table = 'keywords';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cited_rules' => 'array',
        ];
    }
}

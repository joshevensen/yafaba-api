<?php

namespace App\Models;

use Database\Factories\RulesTextVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'fetched_at', 'full_text', 'diff_from_previous'])]
class RulesTextVersion extends Model
{
    /** @use HasFactory<RulesTextVersionFactory> */
    use HasFactory, HasUuids;

    protected $table = 'rules_text_versions';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }
}

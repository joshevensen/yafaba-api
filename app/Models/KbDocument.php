<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'source_type', 'source_ref', 'content', 'embedding', 'embedding_model', 'trust_status', 'version', 'effective_date', 'created_at'])]
class KbDocument extends Model
{
    use HasUuids;

    protected $table = 'kb_documents';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}

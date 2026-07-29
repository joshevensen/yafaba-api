<?php

namespace App\Models;

use Database\Factories\KbDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'source_type', 'source_ref', 'content', 'embedding', 'embedding_model', 'trust_status', 'version', 'effective_date', 'created_at'])]
class KbDocument extends Model
{
    /** @use HasFactory<KbDocumentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'kb_documents';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const SOURCE_CR_RULES = 'cr_rules';

    public const SOURCE_ERRATA = 'errata';

    public const SOURCE_EXPLAINER = 'explainer';

    public const SOURCE_COMBO = 'combo';

    public const SOURCE_SYNERGY = 'synergy';

    public const TRUST_GROUND_TRUTH = 'ground_truth';

    public const TRUST_VALIDATED = 'validated';

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

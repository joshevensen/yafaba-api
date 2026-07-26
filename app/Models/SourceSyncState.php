<?php

namespace App\Models;

use Database\Factories\SourceSyncStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source_key', 'fingerprint', 'fingerprint_type', 'pulled_fingerprint', 'last_checked_at', 'last_changed_at', 'last_pulled_at', 'last_error'])]
class SourceSyncState extends Model
{
    /** @use HasFactory<SourceSyncStateFactory> */
    use HasFactory, HasUuids;

    protected $table = 'source_sync_state';

    public $timestamps = false;

    public const SOURCE_FAB_CUBE_CARDS = 'fab_cube_cards';

    public const SOURCE_CARDVAULT_PRINTINGS = 'cardvault_printings';

    public const SOURCE_TCGCSV_PRICING = 'tcgcsv_pricing';

    public const SOURCE_ERRATA_BULLETINS = 'errata_bulletins';

    public const SOURCE_FABTCG_HERO_SELECTION = 'fabtcg_hero_selection';

    public const SOURCE_FABREC_STAPLE_STATS = 'fabrec_staple_stats';

    public const SOURCE_META_SNAPSHOTS = 'meta_snapshots';

    public const SOURCE_FAB_CR_RULES = 'fab_cr_rules';

    public const FINGERPRINT_GIT_SHA = 'git_sha';

    public const FINGERPRINT_ETAG = 'etag';

    public const FINGERPRINT_LAST_MODIFIED = 'last_modified';

    public const FINGERPRINT_CONTENT_HASH = 'content_hash';

    public const FINGERPRINT_ALWAYS = 'always';

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'last_changed_at' => 'datetime',
            'last_pulled_at' => 'datetime',
        ];
    }
}

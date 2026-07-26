<?php

namespace App\Models;

use Database\Factories\PipelineRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['phase', 'command', 'started_at', 'finished_at', 'status', 'triggered_by', 'counts', 'is_running', 'error'])]
class PipelineRun extends Model
{
    /** @use HasFactory<PipelineRunFactory> */
    use HasFactory, HasUuids;

    protected $table = 'pipeline_runs';

    public $timestamps = false;

    public const PHASE_DATA = 'data';

    public const PHASE_ENRICHMENT = 'enrichment';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIAL = 'partial';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CLI = 'cli';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts' => 'array',
            'is_running' => 'boolean',
        ];
    }

    public function scopeForPhase(Builder $query, string $phase): Builder
    {
        return $query->where('phase', $phase);
    }

    public function scopeLastSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS)
            ->whereNotNull('finished_at')
            ->orderBy('finished_at', 'desc');
    }
}

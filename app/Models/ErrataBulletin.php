<?php

namespace App\Models;

use Database\Factories\ErrataBulletinFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'bulletin_number', 'url', 'published_date', 'content', 'affected_card_ids', 'cached_at'])]
class ErrataBulletin extends Model
{
    /** @use HasFactory<ErrataBulletinFactory> */
    use HasFactory, HasUuids;

    protected $table = 'errata_bulletins';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_date' => 'date',
            'affected_card_ids' => 'array',
            'cached_at' => 'datetime',
        ];
    }
}

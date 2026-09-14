<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'filename',
        'importer_name',
        'success_count',
        'skipped_count',
        'error_count',
        'errors',
        'status',
        'reverted_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'reverted_at' => 'datetime',
        'success_count' => 'integer',
        'skipped_count' => 'integer',
        'error_count' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ImportBatchItem::class);
    }

    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'import_batch_items')
            ->withPivot(['action', 'title', 'year'])
            ->withTimestamps();
    }

    public function isReverted(): bool
    {
        return $this->status === self::STATUS_REVERTED;
    }
}

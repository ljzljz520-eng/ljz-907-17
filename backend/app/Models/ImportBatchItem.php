<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatchItem extends Model
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';

    protected $fillable = [
        'import_batch_id',
        'movie_id',
        'title',
        'year',
        'action',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}

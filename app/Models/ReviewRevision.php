<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recorded "was → became" for a review that changed between parses. */
final class ReviewRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['review_id', 'parse_run_id', 'changes', 'created_at'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function parseRun(): BelongsTo
    {
        return $this->belongsTo(ParseRun::class);
    }
}

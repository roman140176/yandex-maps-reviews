<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The card's aggregates as of one parse run. */
final class OrganizationSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'parse_run_id',
        'rating_value', 'ratings_count', 'reviews_count', 'reviews_stored',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'rating_value' => 'float',
            'captured_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

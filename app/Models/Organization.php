<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A review platform card connected by a user.
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 */
final class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'source', 'external_id', 'url',
        'name', 'address', 'category',
        'rating_value', 'ratings_count', 'reviews_count', 'reviews_stored',
        'parse_status', 'last_parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating_value' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'reviews_stored' => 'integer',
            'parse_status' => ParseStatus::class,
            'last_parsed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<ParseRun, $this> */
    public function parseRuns(): HasMany
    {
        return $this->hasMany(ParseRun::class);
    }

    /** @return HasOne<ParseRun, $this> */
    public function latestParseRun(): HasOne
    {
        return $this->hasOne(ParseRun::class)->latestOfMany();
    }

    /** @return HasMany<OrganizationSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }

    /** True while a run is queued or in flight — used to avoid piling up jobs. */
    public function isParsing(): bool
    {
        return in_array($this->parse_status, [ParseStatus::Queued, ParseStatus::Running], true);
    }
}

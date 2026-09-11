<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Review extends Model
{
    /** @use HasFactory<\Database\Factories\ReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'external_id',
        'author_name', 'author_avatar_url', 'author_level',
        'rating', 'text',
        'business_comment_text', 'business_comment_at',
        'published_at', 'content_hash',
        'photos_count', 'likes_count',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'photos_count' => 'integer',
            'likes_count' => 'integer',
            'published_at' => 'datetime',
            'business_comment_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ReviewRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ReviewRevision::class);
    }
}

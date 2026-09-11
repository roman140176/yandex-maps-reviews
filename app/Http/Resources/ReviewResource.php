<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
final class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'author' => [
                'name' => $this->author_name,
                'avatar_url' => $this->author_avatar_url,
                'level' => $this->author_level,
            ],
            'rating' => $this->rating,
            'text' => $this->text,
            'published_at' => $this->published_at?->toIso8601String(),
            'business_comment' => $this->business_comment_text === null ? null : [
                'text' => $this->business_comment_text,
                'published_at' => $this->business_comment_at?->toIso8601String(),
            ],
            'photos_count' => $this->photos_count,
            'likes_count' => $this->likes_count,
            'revisions_count' => $this->whenCounted('revisions'),
        ];
    }
}

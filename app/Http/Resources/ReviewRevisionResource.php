<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReviewRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReviewRevision */
final class ReviewRevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recorded_at' => $this->created_at?->toIso8601String(),
            'review' => [
                'id' => $this->review?->id,
                'author_name' => $this->review?->author_name,
                'external_id' => $this->review?->external_id,
            ],
            // {"text": {"old": "...", "new": "..."}} — only fields that moved.
            'changes' => $this->changes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
final class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'url' => $this->url,
            'name' => $this->name,
            'address' => $this->address,
            'category' => $this->category,
            'rating' => [
                'value' => $this->rating_value,
                // Two different numbers on purpose: most people rate without
                // writing anything, so these differ by an order of magnitude.
                'ratings_count' => $this->ratings_count,
                'reviews_count' => $this->reviews_count,
            ],
            // NULL until the first parse fills it; the UI wants a number.
            'reviews_stored' => (int) $this->reviews_stored,
            'parse_status' => $this->parse_status?->value,
            'parse_status_label' => $this->parse_status?->label(),
            'is_parsing' => $this->isParsing(),
            'last_parsed_at' => $this->last_parsed_at?->toIso8601String(),
            'latest_run' => ParseRunResource::make($this->whenLoaded('latestParseRun')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

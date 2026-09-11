<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ParseRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ParseRun */
final class ParseRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'trigger' => $this->trigger->value,
            'is_finished' => $this->status->isFinished(),
            'progress' => [
                'pages_done' => $this->pages_done,
                'pages_expected' => $this->pages_expected,
                'percent' => $this->progressPercent(),
                'reviews_seen' => $this->reviews_seen,
            ],
            'reviews_created' => $this->reviews_created,
            'reviews_updated' => $this->reviews_updated,
            'error' => $this->error_code === null ? null : [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ],
            'warnings' => $this->warnings ?? [],
            'attempt' => $this->attempt,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

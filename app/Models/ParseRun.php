<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParseStatus;
use App\Enums\ParseTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParseRun extends Model
{
    /** @use HasFactory<\Database\Factories\ParseRunFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'status', 'trigger',
        'pages_done', 'pages_expected',
        'reviews_seen', 'reviews_created', 'reviews_updated',
        'rating_value', 'ratings_count', 'reviews_count',
        'error_code', 'error_message', 'warnings',
        'attempt', 'started_at', 'finished_at', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParseStatus::class,
            'trigger' => ParseTrigger::class,
            'warnings' => 'array',
            'rating_value' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function progressPercent(): int
    {
        if ($this->pages_expected <= 0) {
            return $this->status->isFinished() ? 100 : 0;
        }

        return (int) min(100, round($this->pages_done / $this->pages_expected * 100));
    }
}

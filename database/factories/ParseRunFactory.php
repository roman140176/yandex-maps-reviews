<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ParseStatus;
use App\Enums\ParseTrigger;
use App\Models\Organization;
use App\Models\ParseRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParseRun> */
final class ParseRunFactory extends Factory
{
    protected $model = ParseRun::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'status' => ParseStatus::Success,
            'trigger' => ParseTrigger::Manual,
            'pages_done' => 12,
            'pages_expected' => 12,
            'reviews_seen' => 600,
            'reviews_created' => 600,
            'reviews_updated' => 0,
            'attempt' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 12000,
        ];
    }
}

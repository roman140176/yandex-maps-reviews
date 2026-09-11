<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $externalId = (string) $this->faker->unique()->numberBetween(1_000_000_000, 1_999_999_999);

        return [
            'user_id' => User::factory(),
            'source' => 'yandex',
            'external_id' => $externalId,
            'url' => "https://yandex.ru/maps/org/{$externalId}/reviews/",
            'name' => $this->faker->company(),
            'address' => $this->faker->address(),
            'category' => 'IT-компания',
            'rating_value' => $this->faker->randomFloat(1, 1, 5),
            'ratings_count' => $this->faker->numberBetween(10, 20000),
            'reviews_count' => $this->faker->numberBetween(1, 5000),
            'reviews_stored' => 0,
            'parse_status' => ParseStatus::Success,
            'last_parsed_at' => now(),
        ];
    }
}

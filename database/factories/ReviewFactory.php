<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
final class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        $publishedAt = $this->faker->dateTimeBetween('-3 years');

        return [
            'organization_id' => Organization::factory(),
            'external_id' => $this->faker->unique()->regexify('[A-Za-z0-9_-]{24}'),
            'author_name' => $this->faker->name(),
            'author_avatar_url' => null,
            'author_level' => null,
            'rating' => $this->faker->numberBetween(1, 5),
            'text' => $this->faker->paragraph(),
            'business_comment_text' => null,
            'business_comment_at' => null,
            'published_at' => $publishedAt,
            'content_hash' => hash('sha256', $this->faker->uuid()),
            'photos_count' => 0,
            'likes_count' => 0,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}

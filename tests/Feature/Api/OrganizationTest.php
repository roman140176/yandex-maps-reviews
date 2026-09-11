<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ParseStatus;
use App\Enums\ParseTrigger;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Queue::fake();
    }

    #[Test]
    public function connecting_a_card_stores_it_and_queues_the_parse(): void
    {
        $response = $this->postJson('/api/organizations', [
            'url' => 'https://yandex.ru/maps/org/yandeks/1124715036/reviews/',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.external_id', '1124715036')
            ->assertJsonPath('data.source', 'yandex')
            ->assertJsonPath('data.parse_status', ParseStatus::Queued->value);

        $this->assertDatabaseHas('organizations', [
            'user_id' => $this->user->id,
            'external_id' => '1124715036',
            // Stored canonical, not as pasted: only /reviews/ serves the list.
            'url' => 'https://yandex.ru/maps/org/1124715036/reviews/',
        ]);

        Queue::assertPushed(ParseOrganizationJob::class, fn (ParseOrganizationJob $job): bool => $job->trigger === ParseTrigger::Initial);
    }

    #[Test]
    public function connecting_the_same_card_twice_updates_it_instead_of_duplicating(): void
    {
        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/yandeks/1124715036/'])
            ->assertCreated();

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/1124715036/reviews/?page=3'])
            ->assertOk();

        $this->assertDatabaseCount('organizations', 1);
        Queue::assertPushed(ParseOrganizationJob::class, 2);
    }

    /** @return array<string, array{string}> */
    public static function invalidUrls(): array
    {
        return [
            'empty' => [''],
            'not a link' => ['hello there'],
            'another platform' => ['https://2gis.ru/moscow/firm/70000001006556069'],
            'random site' => ['https://example.com/'],
            'yandex but not a card' => ['https://yandex.ru/maps/213/moscow/'],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function a_link_that_is_not_an_organisation_card_is_rejected(string $url): void
    {
        $this->postJson('/api/organizations', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertDatabaseCount('organizations', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_short_link_is_resolved_before_the_card_is_stored(): void
    {
        // Short links carry no organisation id, so one redirect has to be
        // followed before the card can be keyed on anything.
        Http::fake([
            'yandex.ru/maps/-/CDbmZ8x' => Http::response('', 301, [
                'Location' => 'https://yandex.ru/maps/org/yandeks/1124715036/',
            ]),
        ]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/CDbmZ8x'])
            ->assertCreated()
            ->assertJsonPath('data.external_id', '1124715036');

        $this->assertDatabaseHas('organizations', ['external_id' => '1124715036']);
    }

    #[Test]
    public function a_short_link_that_leads_nowhere_useful_is_reported(): void
    {
        Http::fake([
            'yandex.ru/maps/-/BROKEN' => Http::response('', 301, [
                'Location' => 'https://yandex.ru/maps/213/moscow/',
            ]),
        ]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/BROKEN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        $this->assertDatabaseCount('organizations', 0);
    }

    #[Test]
    public function a_short_link_is_resolved_once_per_request(): void
    {
        // Validating the link and then acting on it must not cost two lookups.
        Http::fake([
            'yandex.ru/maps/-/CDbmZ8x' => Http::response('', 301, [
                'Location' => 'https://yandex.ru/maps/org/yandeks/1124715036/',
            ]),
        ]);

        $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/-/CDbmZ8x'])
            ->assertCreated();

        Http::assertSentCount(1);
    }

    #[Test]
    public function the_list_shows_only_the_current_users_cards(): void
    {
        Organization::factory()->for($this->user)->create(['name' => 'Моя карточка']);
        Organization::factory()->create(['name' => 'Чужая карточка']);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Моя карточка');
    }

    #[Test]
    public function a_card_belonging_to_someone_else_is_not_reachable(): void
    {
        $foreign = Organization::factory()->create();

        $this->getJson("/api/organizations/{$foreign->id}")->assertForbidden();
        $this->getJson("/api/organizations/{$foreign->id}/reviews")->assertForbidden();
        $this->deleteJson("/api/organizations/{$foreign->id}")->assertForbidden();
    }

    #[Test]
    public function refreshing_queues_another_parse(): void
    {
        $organization = Organization::factory()->for($this->user)->create([
            'parse_status' => ParseStatus::Success,
        ]);

        $this->postJson("/api/organizations/{$organization->id}/refresh")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        Queue::assertPushed(ParseOrganizationJob::class);
    }

    #[Test]
    public function refreshing_a_card_that_is_already_parsing_does_not_queue_a_second_job(): void
    {
        $organization = Organization::factory()->for($this->user)->create([
            'parse_status' => ParseStatus::Running,
        ]);

        $this->postJson("/api/organizations/{$organization->id}/refresh")
            ->assertOk()
            ->assertJsonPath('queued', false);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function disconnecting_a_card_removes_it_with_its_reviews(): void
    {
        $organization = Organization::factory()->for($this->user)->create();
        $organization->reviews()->create([
            'external_id' => 'r1',
            'author_name' => 'Иван',
            'rating' => 5,
            'text' => 'Хорошо',
            'published_at' => now(),
            'content_hash' => str_repeat('a', 64),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->deleteJson("/api/organizations/{$organization->id}")->assertOk();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('reviews', 0);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-password'),
        ]);
    }

    #[Test]
    public function a_seeded_user_can_log_in(): void
    {
        $user = $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $this->user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'nope']);
        }

        $this->postJson('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'secret-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function guests_cannot_reach_the_api(): void
    {
        $this->getJson('/api/organizations')->assertUnauthorized();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function an_authenticated_user_can_read_and_end_their_session(): void
    {
        $this->actingAs($this->user());

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'admin@example.com');
        $this->postJson('/api/auth/logout')->assertOk();
    }
}

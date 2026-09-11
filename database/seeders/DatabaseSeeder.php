<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    /**
     * The task asks for a single seeded user instead of registration, so this
     * is the whole account setup. Credentials come from the environment to keep
     * the deployed prototype from shipping a password that lives in git.
     *
     * Deliberately create-only: the container entrypoint seeds on every start,
     * and re-hashing the password each time would invalidate every active
     * session (Laravel keeps the password hash in the session), logging
     * everyone out on a simple restart.
     */
    public function run(): void
    {
        $email = (string) env('SEED_USER_EMAIL', 'admin@example.com');

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Пользователь {$email} уже существует — пропускаем.");

            return;
        }

        User::query()->create([
            'email' => $email,
            'name' => env('SEED_USER_NAME', 'Администратор'),
            'password' => Hash::make(env('SEED_USER_PASSWORD', 'password')),
            'email_verified_at' => now(),
        ]);

        $this->command?->info("Создан пользователь {$email}.");
    }
}

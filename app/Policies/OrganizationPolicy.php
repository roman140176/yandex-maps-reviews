<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

final class OrganizationPolicy
{
    /** Cards are private to the account that connected them. */
    public function view(User $user, Organization $organization): bool
    {
        return $organization->user_id === $user->id;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }
}

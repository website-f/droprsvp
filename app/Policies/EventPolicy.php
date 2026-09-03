<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /** Superadmins can manage every event. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        return $event->isManageableBy($user);
    }

    public function create(User $user): bool
    {
        return true; // any signed-in user can host an event
    }

    /** Owner or a team collaborator — covers the whole host management surface. */
    public function update(User $user, Event $event): bool
    {
        return $event->isManageableBy($user);
    }

    /** Deleting an event stays with its owner (and superadmins), never collaborators. */
    public function delete(User $user, Event $event): bool
    {
        return $event->user_id === $user->id;
    }
}

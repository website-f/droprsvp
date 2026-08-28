<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * A user who may act on /host/* routes (which are gated to the organizer
     * role). Use this for any test user that creates or manages events.
     */
    protected function organizer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        Role::findOrCreate('organizer', 'web');
        $user->assignRole('organizer');

        return $user;
    }
}

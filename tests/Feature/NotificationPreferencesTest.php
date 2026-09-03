<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_all_on_when_nothing_is_saved(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->wantsNotification('product_news'));
        $this->assertSame(
            ['product_news' => true, 'event_reminders' => true, 'organizer_updates' => true],
            $user->notificationSettings(),
        );
    }

    public function test_the_settings_page_loads_with_the_users_preferences(): void
    {
        $user = User::factory()->create(['notification_preferences' => ['product_news' => false]]);

        $this->actingAs($user)->get('/settings/notifications')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('settings/notifications')
                ->where('preferences.product_news', false)
                ->where('preferences.event_reminders', true));
    }

    public function test_a_user_can_update_their_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/notifications', [
            'product_news' => false,
            'event_reminders' => true,
            'organizer_updates' => false,
        ])->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->wantsNotification('product_news'));
        $this->assertTrue($user->wantsNotification('event_reminders'));
        $this->assertFalse($user->wantsNotification('organizer_updates'));
    }

    public function test_a_broadcast_skips_users_who_opted_out_of_product_news(): void
    {
        Role::findOrCreate('superadmin', 'web');
        Role::findOrCreate('buyer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $optIn = User::factory()->create();
        $optIn->assignRole('buyer');
        $optOut = User::factory()->create(['notification_preferences' => ['product_news' => false]]);
        $optOut->assignRole('buyer');

        $this->actingAs($admin)->post('/admin/broadcast', [
            'audience' => 'buyers',
            'title' => 'New feature!',
            'level' => 'info',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(AppNotification::where('user_id', $optIn->id)->where('type', 'broadcast')->exists());
        $this->assertFalse(AppNotification::where('user_id', $optOut->id)->where('type', 'broadcast')->exists());
    }
}

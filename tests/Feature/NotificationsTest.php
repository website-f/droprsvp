<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_user_can_list_and_mark_notifications_read(): void
    {
        $user = User::factory()->create();
        AppNotification::notify($user, ['title' => 'Hello there', 'body' => 'A message']);

        $this->actingAs($user)->getJson(route('notifications.index'))
            ->assertOk()->assertJson(['unread' => 1])->assertJsonPath('items.0.title', 'Hello there');

        $this->actingAs($user)->postJson(route('notifications.read'))->assertOk();
        $this->assertSame(0, $user->appNotifications()->whereNull('read_at')->count());
    }

    public function test_a_user_cannot_read_another_users_notification(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $n = AppNotification::notify($a, ['title' => 'Private']);

        $this->actingAs($b)->postJson(route('notifications.read-one', $n))->assertForbidden();
    }

    public function test_admin_broadcast_only_notifies_the_chosen_audience(): void
    {
        $admin = $this->superadmin();
        Role::findOrCreate('organizer', 'web');
        $org = User::factory()->create();
        $org->assignRole('organizer');
        $buyer = User::factory()->create(); // no organizer role

        $this->actingAs($admin)->post(route('admin.broadcast'), ['audience' => 'organizers', 'title' => 'Hi organizers'])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', ['user_id' => $org->id, 'title' => 'Hi organizers', 'type' => 'broadcast']);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $buyer->id, 'title' => 'Hi organizers']);
    }

    public function test_non_admin_cannot_broadcast(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('admin.broadcast'), ['audience' => 'all', 'title' => 'X'])->assertForbidden();
    }
}

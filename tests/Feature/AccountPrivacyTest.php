<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_export_their_data_as_json(): void
    {
        $user = User::factory()->create(['name' => 'Exporter', 'phone' => '0123']);
        $event = Event::create([
            'user_id' => $this->organizer()->id, 'title' => 'Expo', 'slug' => 'expo-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        Order::create([
            'reference' => 'DRSVP-EXP1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 30, 'currency' => 'MYR',
            'user_id' => $user->id, 'buyer_email' => $user->email, 'paid_at' => now(),
        ]);

        $res = $this->actingAs($user)->get('/settings/data-export');

        $res->assertOk();
        $this->assertStringContainsString('application/json', $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));

        $json = json_decode($res->streamedContent(), true);
        $this->assertSame('Exporter', $json['account']['name']);
        $this->assertSame('DRSVP-EXP1', $json['orders'][0]['reference']);
    }

    public function test_deleting_an_account_anonymises_retained_records(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe', 'phone' => '0199']);
        $event = Event::create([
            'user_id' => $this->organizer()->id, 'title' => 'Del', 'slug' => 'del-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $order = Order::create([
            'reference' => 'DRSVP-DEL1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 20, 'currency' => 'MYR',
            'user_id' => $user->id, 'buyer_name' => 'Jane Doe', 'buyer_email' => $user->email, 'buyer_phone' => '0199',
        ]);
        $ticket = Ticket::create([
            'order_id' => $order->id, 'event_id' => $event->id, 'qr_token' => 'qr-'.uniqid(), 'attendee_name' => 'Jane Doe',
            'attendee_email' => $user->email, 'status' => 'valid',
        ]);

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertSoftDeleted($user);
        $fresh = $user->fresh();
        $this->assertSame('Deleted user', $fresh->name);
        $this->assertNull($fresh->phone);
        $this->assertStringContainsString('@deleted.invalid', $fresh->email);

        $this->assertSame('Deleted user', $order->fresh()->buyer_name);
        $this->assertNull($order->fresh()->buyer_email);
        $this->assertSame('Deleted user', $ticket->fresh()->attendee_name);
        $this->assertNull($ticket->fresh()->attendee_email);
    }

    public function test_a_superadmin_cannot_self_delete(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->from(route('profile.edit'))
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotSoftDeleted($admin);
    }

    public function test_an_organizer_with_an_upcoming_event_is_blocked_from_deleting(): void
    {
        $organizer = $this->organizer();
        Event::create([
            'user_id' => $organizer->id, 'title' => 'Upcoming', 'slug' => 'upcoming-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(),
        ]);

        $this->actingAs($organizer)->from(route('profile.edit'))
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotSoftDeleted($organizer);
    }
}

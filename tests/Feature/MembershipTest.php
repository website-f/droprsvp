<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventComment;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private function event(?User $host = null): Event
    {
        $host ??= User::factory()->create();

        return Event::create([
            'user_id' => $host->id, 'title' => 'Social Event', 'slug' => 'social-event',
            'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    private function paidOrder(Event $event, string $email): void
    {
        Order::create([
            'reference' => 'M-'.\Illuminate\Support\Str::random(6), 'event_id' => $event->id, 'status' => 'paid',
            'buyer_name' => 'Buyer '.$email, 'buyer_email' => $email, 'total' => 10, 'currency' => 'MYR', 'paid_at' => now(),
        ]);
    }

    public function test_free_viewer_sees_only_top_4_members(): void
    {
        $event = $this->event();
        foreach (range(1, 7) as $i) {
            $this->paidOrder($event, "m{$i}@example.com");
        }

        $this->get('/e/'.$event->slug)->assertInertia(fn (Assert $p) => $p
            ->where('participants.count', 7)
            ->where('participants.unlocked', false)
            ->has('participants.list', 4));
    }

    public function test_premium_viewer_sees_all_members(): void
    {
        $event = $this->event();
        foreach (range(1, 7) as $i) {
            $this->paidOrder($event, "m{$i}@example.com");
        }
        $premium = User::factory()->create(['premium_until' => now()->addMonth()]);

        $this->actingAs($premium)->get('/e/'.$event->slug)->assertInertia(fn (Assert $p) => $p
            ->where('participants.unlocked', true)
            ->has('participants.list', 7)
            ->where('viewer.can_post', true));
    }

    public function test_free_user_cannot_post_in_discussion(): void
    {
        $event = $this->event();
        $free = User::factory()->create();

        $this->actingAs($free)->post("/e/{$event->slug}/comments", ['body' => 'Hi'])
            ->assertSessionHasErrors('body');
        $this->assertDatabaseCount('event_comments', 0);
    }

    public function test_premium_user_can_post_a_question(): void
    {
        $event = $this->event();
        $premium = User::factory()->create(['premium_until' => now()->addMonth()]);

        $this->actingAs($premium)->post("/e/{$event->slug}/comments", ['body' => 'What time do doors open?'])
            ->assertRedirect();
        $this->assertDatabaseHas('event_comments', ['event_id' => $event->id, 'body' => 'What time do doors open?', 'parent_id' => null]);
    }

    public function test_organizer_can_reply_without_premium(): void
    {
        $host = User::factory()->create();
        $event = $this->event($host);
        $premium = User::factory()->create(['premium_until' => now()->addMonth()]);
        $q = EventComment::create(['event_id' => $event->id, 'user_id' => $premium->id, 'body' => 'Q?']);

        $this->actingAs($host)->post("/e/{$event->slug}/comments", ['body' => 'Doors at 8pm.', 'parent_id' => $q->id])
            ->assertRedirect();
        $this->assertDatabaseHas('event_comments', ['parent_id' => $q->id, 'user_id' => $host->id, 'body' => 'Doors at 8pm.']);
    }

    public function test_subscribing_via_fake_gateway_grants_premium(): void
    {
        Setting::put('premium_price', 19);
        Setting::put('premium_days', 30);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/premium/subscribe')->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->isPremium());
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'status' => 'paid']);
    }

    public function test_superadmin_cannot_subscribe_and_has_full_access(): void
    {
        \Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        // Superadmins are redirected away from the subscribe page and blocked from paying.
        $this->actingAs($admin)->get('/premium')->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->post('/premium/subscribe')->assertForbidden();
        $admin->refresh();
        $this->assertFalse($admin->isPremium());

        // …but they still get premium-level access (post in discussions) for free.
        $event = $this->event();
        $this->actingAs($admin)->post("/e/{$event->slug}/comments", ['body' => 'Admin note'])->assertRedirect();
        $this->assertDatabaseHas('event_comments', ['body' => 'Admin note', 'user_id' => $admin->id]);
    }

    public function test_premium_page_lists_benefits(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/premium')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('premium')->where('is_premium', false)->has('price'));
    }
}

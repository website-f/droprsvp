<?php

namespace Tests\Feature;

use App\Mail\GuestAccountMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GuestAccountTest extends TestCase
{
    use RefreshDatabase;

    private function guestOrder(string $email): Order
    {
        Role::findOrCreate('buyer', 'web');
        $host = User::factory()->create();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Show', 'slug' => 'show-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);

        return Order::create(['reference' => 'DRSVP-G'.rand(1000, 9999), 'event_id' => $event->id, 'status' => 'pending', 'total' => 0, 'currency' => 'MYR', 'buyer_name' => 'Gina Guest', 'buyer_email' => $email]);
    }

    public function test_guest_purchase_auto_creates_an_account_with_a_temp_password(): void
    {
        Mail::fake();
        $order = $this->guestOrder('gina@example.com');

        app(CheckoutService::class)->markPaid($order);

        $user = User::where('email', 'gina@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->must_set_password);
        $this->assertTrue($user->hasRole('buyer'));
        $this->assertSame($user->id, $order->fresh()->user_id);
        Mail::assertSent(GuestAccountMail::class, fn ($m) => $m->hasTo('gina@example.com'));
    }

    public function test_guest_purchase_links_to_an_existing_account_without_resetting_it(): void
    {
        Mail::fake();
        $existing = User::factory()->create(['email' => 'has@example.com']);
        $order = $this->guestOrder('has@example.com');

        app(CheckoutService::class)->markPaid($order);

        $this->assertSame($existing->id, $order->fresh()->user_id);
        $this->assertFalse($existing->fresh()->must_set_password);
        Mail::assertNotSent(GuestAccountMail::class);
    }

    public function test_a_new_guest_account_is_forced_to_set_a_password(): void
    {
        $user = User::factory()->create(['must_set_password' => true]);

        // Any page bounces them to the set-password screen.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.set'));

        // Setting a password clears the flag and lets them continue.
        $this->actingAs($user)->post('/set-password', [
            'password' => 'Str0ng!pass_xyz', 'password_confirmation' => 'Str0ng!pass_xyz',
        ])->assertRedirect(route('dashboard'));
        $this->assertFalse($user->fresh()->must_set_password);

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }
}

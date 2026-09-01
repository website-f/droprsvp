<?php

namespace Tests\Feature;

use App\Mail\OrderRefundedMail;
use App\Mail\PayoutPaidMail;
use App\Mail\RegistrationCodeMail;
use App\Mail\WelcomeMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    /** Rendering exercises the shared branded layout + button partial (catches Blade errors). */
    public function test_branded_emails_render_without_errors(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $welcome = (new WelcomeMail($user))->render();
        $this->assertStringContainsString('Drop', $welcome);
        $this->assertStringContainsString('Welcome, Ada', $welcome);
        $this->assertStringContainsString('Browse events', $welcome);

        $code = (new RegistrationCodeMail('123456'))->render();
        $this->assertStringContainsString('123456', $code);

        $host = User::factory()->create();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Gala', 'slug' => 'gala-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $payout = Payout::create(['user_id' => $host->id, 'reference' => 'PO-XYZ', 'amount' => 90, 'currency' => 'MYR', 'status' => 'paid', 'method' => 'CHIP Send']);
        $this->assertStringContainsString('PO-XYZ', (new PayoutPaidMail($payout))->render());
    }

    public function test_registering_sends_a_welcome_email(): void
    {
        Mail::fake();

        $this->post(route('register.store'), [
            'name' => 'New User', 'email' => 'welcome@example.com',
            'password' => 'password', 'password_confirmation' => 'password', 'consent' => true,
        ])->assertRedirect();

        Mail::assertSent(WelcomeMail::class, fn ($m) => $m->hasTo('welcome@example.com'));
    }

    public function test_refunding_an_order_emails_the_buyer(): void
    {
        Mail::fake();
        $host = User::factory()->create();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Show', 'slug' => 'show-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $order = Order::create(['reference' => 'DRSVP-RF', 'event_id' => $event->id, 'status' => 'paid', 'total' => 50, 'currency' => 'MYR', 'buyer_name' => 'Jo', 'buyer_email' => 'jo@example.com', 'paid_at' => now()]);

        app(CheckoutService::class)->refund($order);

        Mail::assertSent(OrderRefundedMail::class, fn ($m) => $m->hasTo('jo@example.com'));
    }

    public function test_marking_a_payout_paid_emails_the_organizer(): void
    {
        Mail::fake();
        $host = User::factory()->create(['email' => 'org@example.com']);
        $payout = Payout::create(['user_id' => $host->id, 'reference' => 'PO-1', 'amount' => 40, 'currency' => 'MYR', 'status' => 'pending']);

        app(PayoutService::class)->markPaid($payout, 'Manual');

        Mail::assertSent(PayoutPaidMail::class, fn ($m) => $m->hasTo('org@example.com'));
    }
}

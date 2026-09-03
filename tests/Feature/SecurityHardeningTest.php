<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function publishedEvent(?User $host = null): Event
    {
        return Event::create([
            'user_id' => ($host ?? User::factory()->create())->id, 'title' => 'Sec Event', 'slug' => 'sec-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    private function paidOrder(Event $event, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid',
            'total' => 50, 'paid_at' => now(), 'buyer_name' => 'Buyer', 'buyer_email' => 'buyer@example.test',
        ], $overrides));
    }

    // ---- Response headers -------------------------------------------------

    public function test_security_headers_are_present(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    // ---- Upload hardening (no SVG → stored XSS) ---------------------------

    public function test_image_upload_rejects_svg(): void
    {
        $user = User::factory()->create();
        // The frontend uploads via fetch with an Accept: application/json header.
        $this->actingAs($user)->withHeaders(['Accept' => 'application/json'])->post('/uploads', [
            'file' => UploadedFile::fake()->create('x.svg', 8, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_image_upload_accepts_png(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withHeaders(['Accept' => 'application/json'])->post('/uploads', [
            'file' => UploadedFile::fake()->image('x.png', 100, 100),
        ])->assertOk()->assertJsonStructure(['url']);
    }

    // ---- Checkout capability-token access control -------------------------

    public function test_order_confirmation_is_blocked_for_a_stranger(): void
    {
        $order = $this->paidOrder($this->publishedEvent());

        // A guest who never started this checkout (no session binding) is refused,
        // even though they somehow hold the reference.
        $this->get("/orders/{$order->reference}")->assertForbidden();
    }

    public function test_order_confirmation_is_allowed_for_the_authenticated_owner(): void
    {
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->publishedEvent(), ['user_id' => $buyer->id]);

        $this->actingAs($buyer)->get("/orders/{$order->reference}")->assertOk();
    }

    public function test_order_confirmation_is_allowed_for_the_events_organizer(): void
    {
        $host = $this->organizer();
        $order = $this->paidOrder($this->publishedEvent($host));

        $this->actingAs($host)->get("/orders/{$order->reference}")->assertOk();
    }

    public function test_a_logged_in_stranger_cannot_view_someone_elses_order(): void
    {
        $order = $this->paidOrder($this->publishedEvent(), ['user_id' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->create())->get("/orders/{$order->reference}")->assertForbidden();
    }

    // ---- Organizer discussion wall only exists for organizers -------------

    public function test_discussion_feed_404s_for_a_non_organizer(): void
    {
        $user = User::factory()->create();
        $user->ensureSlug();

        $this->get("/o/{$user->slug}/discussion")->assertNotFound();
    }

    public function test_contact_honeypot_silently_drops_bot_submissions(): void
    {
        $this->post('/contact', [
            'name' => 'Bot', 'email' => 'bot@spam.test', 'phone' => '0100000000',
            'category' => 'support', 'message' => 'buy cheap stuff', 'website' => 'http://spam.test',
        ])->assertRedirect();

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_html_sanitizer_strips_scripts_and_handlers(): void
    {
        $dirty = '<p onclick="steal()">hi</p><script>alert(1)</script><a href="javascript:evil()">x</a>';
        $clean = \App\Support\HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('hi', $clean); // benign content preserved
    }

    public function test_superadmin_can_still_view_any_order_confirmation(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $order = $this->paidOrder($this->publishedEvent());

        $this->actingAs($admin)->get("/orders/{$order->reference}")->assertOk();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Event;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundSystemTest extends TestCase
{
    use RefreshDatabase;

    private function event($host, array $overrides = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Gala', 'slug' => 'gala-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'refund_policy' => 'until_event', 'starts_at' => now()->addDays(5),
        ], $overrides));
    }

    private function paidOrder(Event $event, ?User $buyer = null, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'reference' => 'DRSVP-'.strtoupper(uniqid()), 'event_id' => $event->id, 'status' => 'paid',
            'total' => 100, 'currency' => 'MYR', 'paid_at' => now(),
            'buyer_name' => $buyer?->name ?? 'Buyer', 'buyer_email' => $buyer?->email ?? 'b@example.test',
            'user_id' => $buyer?->id,
        ], $overrides));
    }

    private function pendingRequest(Order $order, User $buyer): RefundRequest
    {
        return RefundRequest::create([
            'order_id' => $order->id, 'user_id' => $buyer->id, 'amount' => $order->remainingRefundable(), 'status' => 'pending',
        ]);
    }

    public function test_buyer_can_request_a_refund_and_the_organizer_is_notified(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($host), $buyer);

        $this->actingAs($buyer)->post("/my/orders/{$order->reference}/refund-request", ['reason' => 'can’t go'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('refund_requests', ['order_id' => $order->id, 'status' => 'pending', 'amount' => '100.00']);
        $this->assertTrue(AppNotification::where('user_id', $host->id)->where('type', 'refund')->exists());
    }

    public function test_no_refund_policy_blocks_requests(): void
    {
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($this->organizer(), ['refund_policy' => 'no_refunds']), $buyer);

        $this->actingAs($buyer)->post("/my/orders/{$order->reference}/refund-request")->assertStatus(422);
    }

    public function test_a_stranger_cannot_request_a_refund_on_anothers_order(): void
    {
        $order = $this->paidOrder($this->event($this->organizer()), User::factory()->create());

        $this->actingAs(User::factory()->create())->post("/my/orders/{$order->reference}/refund-request")->assertForbidden();
    }

    public function test_organizer_approves_a_full_refund(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($host), $buyer);
        $req = $this->pendingRequest($order, $buyer);

        $this->actingAs($host)->post("/host/refunds/{$req->id}/approve", ['amount' => 100])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertEquals(100, (float) $order->fresh()->refunded_amount);
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_organizer_approves_a_partial_refund_and_the_order_stays_paid(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($host), $buyer);
        $req = $this->pendingRequest($order, $buyer);

        $this->actingAs($host)->post("/host/refunds/{$req->id}/approve", ['amount' => 40])->assertRedirect();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertEquals(40, (float) $order->fresh()->refunded_amount);
        $this->assertEquals(40, (float) $req->fresh()->approved_amount);
    }

    public function test_organizer_cannot_refund_more_than_the_order_total(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($host), $buyer);
        $req = $this->pendingRequest($order, $buyer);

        $this->actingAs($host)->post("/host/refunds/{$req->id}/approve", ['amount' => 999])->assertStatus(302)->assertSessionHasErrors('amount');
    }

    public function test_organizer_declines_a_refund(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($host), $buyer);
        $req = $this->pendingRequest($order, $buyer);

        $this->actingAs($host)->post("/host/refunds/{$req->id}/decline", ['note' => 'outside window'])->assertRedirect();

        $this->assertSame('declined', $req->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_another_organizer_cannot_act_on_someone_elses_refund(): void
    {
        $host = $this->organizer();
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($host), $buyer);
        $req = $this->pendingRequest($order, $buyer);

        $this->actingAs($this->organizer())->post("/host/refunds/{$req->id}/approve", ['amount' => 100])->assertForbidden();
    }

    public function test_admin_refund_oversight_lists_all_requests(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $buyer = User::factory()->create();
        $order = $this->paidOrder($this->event($this->organizer()), $buyer);
        $this->pendingRequest($order, $buyer);

        $this->actingAs($admin)->get('/admin/refunds')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/refunds')
                ->has('requests.data', 1)
                ->where('stats.pending', 1));
    }
}

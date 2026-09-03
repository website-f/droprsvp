<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $host, string $email): Order
    {
        $event = Event::create(['user_id' => $host->id, 'title' => 'Show', 'slug' => 'show-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $order = Order::create(['reference' => 'DRSVP-R'.rand(1000, 9999), 'event_id' => $event->id, 'status' => 'paid', 'subtotal' => 50, 'total' => 50, 'currency' => 'MYR', 'buyer_name' => 'Jo', 'buyer_email' => $email, 'paid_at' => now()]);
        $order->items()->create(['name' => 'General', 'unit_price' => 25, 'quantity' => 2, 'line_total' => 50]);

        return $order;
    }

    public function test_buyer_can_view_their_order_receipt_but_not_others(): void
    {
        $host = User::factory()->create();
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $order = $this->order($host, 'buyer@example.com');

        $this->actingAs($buyer)->get("/my/orders/{$order->reference}/receipt")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('receipts/show')
                ->where('receipt.kind', 'order')
                ->where('receipt.total', 50)
                ->has('receipt.items', 1));

        $this->actingAs(User::factory()->create())->get("/my/orders/{$order->reference}/receipt")->assertForbidden();
    }

    public function test_the_receipt_shows_a_discount_line_so_the_totals_add_up(): void
    {
        $host = User::factory()->create();
        $buyer = User::factory()->create(['email' => 'd@example.com']);
        $event = \App\Models\Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        // subtotal 100, RM40 discount → total 60. Receipt must expose the discount.
        $order = Order::create(['reference' => 'DRSVP-D'.rand(1000, 9999), 'event_id' => $event->id, 'status' => 'paid', 'subtotal' => 100, 'discount' => 40, 'total' => 60, 'currency' => 'MYR', 'buyer_name' => 'D', 'buyer_email' => 'd@example.com', 'paid_at' => now()]);

        $this->actingAs($buyer)->get("/my/orders/{$order->reference}/receipt")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('receipts/show')
                ->where('receipt.subtotal', 100)
                ->where('receipt.discount', 40)
                ->where('receipt.total', 60));
    }

    public function test_order_receipt_downloads_as_a_pdf(): void
    {
        $host = User::factory()->create();
        $buyer = User::factory()->create(['email' => 'pdf@example.com']);
        $order = $this->order($host, 'pdf@example.com');

        $res = $this->actingAs($buyer)->get("/my/orders/{$order->reference}/receipt/pdf");
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_superadmin_can_view_any_order_receipt(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $order = $this->order(User::factory()->create(), 'someone@example.com');

        $this->actingAs($admin)->get("/my/orders/{$order->reference}/receipt")->assertOk();
    }

    public function test_organizer_can_view_their_payout_receipt_but_not_others(): void
    {
        $vendor = User::factory()->create();
        $payout = Payout::create(['user_id' => $vendor->id, 'reference' => 'PO-'.rand(1000, 9999), 'amount' => 120, 'currency' => 'MYR', 'status' => 'paid', 'method' => 'CHIP Send', 'paid_at' => now()]);

        $this->actingAs($vendor)->get("/my/payouts/{$payout->reference}/receipt")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('receipt.kind', 'payout')->where('receipt.total', 120));

        $this->actingAs(User::factory()->create())->get("/my/payouts/{$payout->reference}/receipt")->assertForbidden();
    }

    public function test_invoices_page_lists_the_buyers_orders(): void
    {
        $host = User::factory()->create();
        $buyer = User::factory()->create(['email' => 'b2@example.com']);
        $this->order($host, 'b2@example.com');

        $this->actingAs($buyer)->get('/my/invoices')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('account/invoices')->has('orders.data', 1));
    }
}

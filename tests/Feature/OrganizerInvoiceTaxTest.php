<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerInvoiceTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organizer_can_save_invoice_and_tax_details(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($organizer)->post('/host/payouts/business', [
            'business_name' => 'Acme Events Sdn Bhd',
            'tax_number' => 'W10-1808-31000123',
            'business_address' => "12 Jalan Ampang\nKuala Lumpur",
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('organizer_profiles', [
            'user_id' => $organizer->id,
            'business_name' => 'Acme Events Sdn Bhd',
            'tax_number' => 'W10-1808-31000123',
        ]);
    }

    public function test_the_tax_number_appears_on_the_buyers_receipt(): void
    {
        $organizer = $this->organizer(['name' => 'Host Co']);
        $organizer->organizerProfile()->create([
            'business_name' => 'Acme Events Sdn Bhd', 'tax_number' => 'W10-1808-31000123',
            'business_address' => '12 Jalan Ampang',
        ]);
        $event = Event::create([
            'user_id' => $organizer->id, 'title' => 'Gala', 'slug' => 'gala-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $order = Order::create([
            'reference' => 'DRSVP-TAX1', 'event_id' => $event->id, 'status' => 'paid', 'total' => 100,
            'subtotal' => 100, 'currency' => 'MYR', 'paid_at' => now(), 'buyer_name' => 'Buyer', 'buyer_email' => 'b@example.test',
        ]);

        $receipt = Receipt::forOrder($order);

        $this->assertSame('Acme Events Sdn Bhd', $receipt['seller']['name']);
        $this->assertSame('W10-1808-31000123', $receipt['seller']['tax_number']);
        $this->assertSame('12 Jalan Ampang', $receipt['seller']['address']);
    }

    public function test_the_receipt_view_renders_the_tax_number(): void
    {
        $organizer = $this->organizer();
        $organizer->organizerProfile()->create(['business_name' => 'Acme', 'tax_number' => 'SST-999']);
        $event = Event::create([
            'user_id' => $organizer->id, 'title' => 'Gala', 'slug' => 'gala-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $order = Order::create([
            'reference' => 'DRSVP-TAX2', 'event_id' => $event->id, 'status' => 'paid', 'total' => 50,
            'subtotal' => 50, 'currency' => 'MYR', 'paid_at' => now(), 'buyer_name' => 'Buyer', 'buyer_email' => 'b@example.test',
        ]);

        $this->actingAs($organizer)->get("/my/orders/{$order->reference}/receipt")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('receipts/show')->where('receipt.seller.tax_number', 'SST-999'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Premium + boost must upgrade the moment the buyer returns from the gateway,
 * not only when the (possibly delayed/blocked) webhook fires. These cover the
 * synchronous return-page reconciliation.
 */
class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function useChip(): void
    {
        Config::set('services.chip.driver', 'chip');
        Config::set('services.chip.secret', 'sk_test_123');
    }

    public function test_premium_return_activates_a_paid_subscription(): void
    {
        $this->useChip();
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'pur_prem', 'status' => 'paid'], 200)]);

        $user = User::factory()->create(['email_verified_at' => now(), 'profile_completed_at' => now()]);
        $sub = Subscription::create(['reference' => 'SUB-TEST01', 'user_id' => $user->id, 'amount' => 30, 'days' => 30, 'status' => 'pending', 'payment_ref' => 'pur_prem']);

        $this->actingAs($user)->get(route('premium.return'))
            ->assertInertia(fn (Assert $p) => $p->component('premium')->where('result', 'paid')->where('is_premium', true));

        $this->assertSame('paid', $sub->fresh()->status);
        $this->assertTrue($user->fresh()->isPremium());
    }

    public function test_premium_return_shows_processing_when_gateway_not_yet_paid(): void
    {
        $this->useChip();
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'pur_prem', 'status' => 'pending'], 200)]);

        $user = User::factory()->create(['email_verified_at' => now(), 'profile_completed_at' => now()]);
        Subscription::create(['reference' => 'SUB-TEST02', 'user_id' => $user->id, 'amount' => 30, 'days' => 30, 'status' => 'pending', 'payment_ref' => 'pur_prem']);

        $this->actingAs($user)->get(route('premium.return'))
            ->assertInertia(fn (Assert $p) => $p->where('result', 'processing')->where('is_premium', false));

        $this->assertFalse($user->fresh()->isPremium());
    }

    public function test_boost_return_activates_a_paid_promotion(): void
    {
        $this->useChip();
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'pur_boost', 'status' => 'paid'], 200)]);

        $host = $this->organizer();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Boostable', 'slug' => 'boostable', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $promo = Promotion::create(['reference' => 'BOOST-TEST01', 'event_id' => $event->id, 'user_id' => $host->id, 'amount' => 20, 'days' => 7, 'status' => 'pending', 'payment_ref' => 'pur_boost']);

        $this->actingAs($host)->get(route('host.events.promote.return', $event))
            ->assertInertia(fn (Assert $p) => $p->component('host/events/promote')->where('result', 'paid'));

        $this->assertSame('paid', $promo->fresh()->status);
        $this->assertTrue($event->fresh()->isBoosted());
    }
}

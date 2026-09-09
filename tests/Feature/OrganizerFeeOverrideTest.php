<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\PlatformFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Per-organizer booking-fee overrides: an admin can move one host off the global
 * rate, from the fee table or from the host's own admin page.
 */
class OrganizerFeeOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
    }

    private function host(): User
    {
        Role::findOrCreate('organizer', 'web');
        $user = User::factory()->create();
        $user->assignRole('organizer');

        return $user;
    }

    private function admin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    /** A RM100 order on this host's event, priced through the real checkout path. */
    private function order(User $host)
    {
        $event = Event::create(['user_id' => $host->id, 'title' => 'E', 'slug' => 'e-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay()]);
        $tt = TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'price' => 100, 'currency' => 'MYR', 'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => 'paid']);

        return app(CheckoutService::class)->start($event, [['ticket_type_id' => $tt->id, 'quantity' => 1]]);
    }

    public function test_an_organizer_without_an_override_pays_the_global_fee(): void
    {
        $host = $this->host();

        $this->assertFalse($host->hasFeeOverride());
        $this->assertSame(5.0, PlatformFee::on(100, $host)); // global 5% beats the RM3 flat
        $this->assertEquals(105, (float) $this->order($host)->total);
    }

    public function test_an_override_replaces_the_global_rate_at_checkout(): void
    {
        $host = $this->host();
        $host->setFeeOverride(10, 5);

        $this->assertTrue($host->fresh()->hasFeeOverride());
        $this->assertSame(10.0, PlatformFee::on(100, $host->fresh()));

        // The order is priced with the host's own rate, not the platform's.
        $order = $this->order($host->fresh());
        $this->assertEquals(10, (float) $order->fees);
        $this->assertEquals(110, (float) $order->total);

        // Other organizers are untouched.
        $this->assertSame(5.0, PlatformFee::on(100, $this->host()));
        $this->assertSame(5.0, PlatformFee::on(100));
    }

    public function test_the_flat_half_of_an_override_still_wins_on_cheap_tickets(): void
    {
        $host = $this->host();
        $host->setFeeOverride(2, 8); // 2% of RM100 = RM2, so the RM8 flat wins

        $this->assertSame(8.0, PlatformFee::on(100, $host->fresh()));
    }

    public function test_admin_can_set_and_clear_an_override_from_the_fee_endpoints(): void
    {
        $host = $this->host();

        $this->actingAs($this->admin())
            ->post("/admin/organizer-fees/{$host->id}", ['fee_percent' => 12, 'fee_flat' => 4])
            ->assertRedirect();
        $this->assertEquals(12.0, (float) $host->fresh()->platform_fee_percent);
        $this->assertEquals(4.0, (float) $host->fresh()->platform_fee_flat);

        $this->actingAs($this->admin())
            ->delete("/admin/organizer-fees/{$host->id}")
            ->assertRedirect();
        $this->assertNull($host->fresh()->platform_fee_percent);
        $this->assertNull($host->fresh()->platform_fee_flat);
        $this->assertFalse($host->fresh()->hasFeeOverride());
    }

    public function test_an_override_does_not_reprice_orders_that_already_exist(): void
    {
        $host = $this->host();
        $order = $this->order($host);     // frozen at the global 5% → RM5
        $host->setFeeOverride(20, 10);

        $this->assertEquals(5, (float) $order->fresh()->fees);
        $this->assertEquals(105, (float) $order->fresh()->total);
    }

    public function test_the_fee_table_lists_organizers_with_their_effective_rate(): void
    {
        $host = $this->host();
        $host->setFeeOverride(9, 1);

        $this->actingAs($this->admin())->get('/admin/organizer-fees')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/organizer-fees/index')
                ->where('organizers.data.0.fee.percent', 9)
                ->where('organizers.data.0.fee.custom', true)
                ->where('global.percent', 5)
                ->where('counts.custom', 1));
    }

    public function test_the_fee_table_can_filter_to_organizers_on_a_custom_rate(): void
    {
        $plain = $this->host();
        $custom = $this->host();
        $custom->setFeeOverride(9, 1);

        $this->actingAs($this->admin())->get('/admin/organizer-fees?scope=custom')->assertOk()
            ->assertInertia(fn ($p) => $p->count('organizers.data', 1)->where('organizers.data.0.id', $custom->id));

        $this->actingAs($this->admin())->get('/admin/organizer-fees?scope=global')->assertOk()
            ->assertInertia(fn ($p) => $p->count('organizers.data', 1)->where('organizers.data.0.id', $plain->id));
    }

    public function test_the_organizers_admin_page_carries_the_fee_so_it_can_be_edited_there(): void
    {
        $host = $this->host();

        $this->actingAs($this->admin())->get("/admin/users/{$host->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('user.is_organizer', true)
                ->where('fee.custom', false)
                ->where('fee.percent', 5)
                ->where('globalFee.percent', 5)
                ->where('canManageFees', true));
    }

    public function test_a_non_admin_cannot_change_anyones_fee(): void
    {
        $host = $this->host();

        $this->actingAs($host)->post("/admin/organizer-fees/{$host->id}", ['fee_percent' => 0, 'fee_flat' => 0])->assertForbidden();
        $this->actingAs($host)->get('/admin/organizer-fees')->assertForbidden();
        $this->assertFalse($host->fresh()->hasFeeOverride());
    }

    public function test_staff_without_the_settings_section_cannot_change_fees(): void
    {
        Role::findOrCreate('staff', 'web');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $host = $this->host();

        // The default staff grant excludes Settings, which is where fees live.
        $this->actingAs($staff)->get('/admin/organizer-fees')->assertForbidden();
        $this->actingAs($staff)->post("/admin/organizer-fees/{$host->id}", ['fee_percent' => 1, 'fee_flat' => 1])->assertForbidden();
    }

    public function test_an_override_is_validated_like_the_global_fee(): void
    {
        $host = $this->host();

        $this->actingAs($this->admin())
            ->post("/admin/organizer-fees/{$host->id}", ['fee_percent' => 140, 'fee_flat' => -2])
            ->assertSessionHasErrors(['fee_percent', 'fee_flat']);
        $this->assertFalse($host->fresh()->hasFeeOverride());
    }
}

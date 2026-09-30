<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Birth year replacing the age band, and the order count on the host list.
 */
class BirthYearAndCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_birth_year_derives_the_band_reporting_groups_by(): void
    {
        $year = (int) date('Y');

        $this->assertSame('under-18', Profile::bandFor($year - 10));
        $this->assertSame('18-24', Profile::bandFor($year - 20));
        $this->assertSame('25-34', Profile::bandFor($year - 30));
        $this->assertSame('35-44', Profile::bandFor($year - 40));
        $this->assertSame('45-54', Profile::bandFor($year - 50));
        $this->assertSame('55+', Profile::bandFor($year - 70));
    }

    public function test_an_impossible_year_derives_nothing(): void
    {
        $this->assertNull(Profile::bandFor(null));
        $this->assertNull(Profile::bandFor(1800));
        $this->assertNull(Profile::bandFor((int) date('Y') + 5));
    }

    public function test_the_about_you_form_stores_the_year_and_the_derived_band(): void
    {
        $user = User::factory()->create(['birth_year' => null, 'age_band' => null]);
        $year = (int) date('Y') - 30;

        $this->actingAs($user)->post('/profile/about-you', [
            'phone' => '0123456789',
            'gender' => 'male',
            'birth_year' => $year,
            'country' => 'Malaysia',
            'city' => 'Kajang',
        ])->assertRedirect();

        $user->refresh();

        $this->assertSame($year, $user->birth_year);
        // Derived, so it can never drift out of date the way a self-selected
        // band does after a birthday.
        $this->assertSame('25-34', $user->age_band);
        $this->assertSame('Kajang', $user->city);
        $this->assertNotNull($user->profile_completed_at);
    }

    public function test_the_admin_page_shows_the_year_and_how_long_they_have_been_a_member(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('superadmin', 'web'));

        $user = User::factory()->create(['birth_year' => 1995, 'city' => 'Kajang']);
        $user->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->actingAs($admin)->get('/admin/users/'.$user->id)->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('user.birth_year', 1995)
                ->where('user.city', 'Kajang')
                ->where('user.membership', '2 years on DropRSVP'));
    }

    public function test_membership_reads_sensibly_for_a_new_account(): void
    {
        $this->assertSame('Joined this month', Profile::membershipLabel(now()->subDays(3)));
        $this->assertSame('3 months on DropRSVP', Profile::membershipLabel(now()->subMonths(3)));
        $this->assertSame('1 year on DropRSVP', Profile::membershipLabel(now()->subYear()));
    }

    /** An order row is created the moment someone opens checkout. */
    public function test_the_host_list_counts_paid_orders_not_abandoned_carts(): void
    {
        $host = $this->organizer();

        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'price' => 20, 'currency' => 'MYR',
            'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => 'paid',
        ]);

        $checkout = app(CheckoutService::class);

        // One real sale…
        $paid = $checkout->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);
        $paid->update(['buyer_name' => 'A', 'buyer_email' => 'a@example.test']);
        $checkout->markPaid($paid->fresh());

        // …and two people who opened checkout and never paid.
        $checkout->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);
        $checkout->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);

        // Reported as "3 orders" against a single ticket sold.
        $this->actingAs($host)->get('/host/events')->assertOk()
            ->assertInertia(fn ($p) => $p->where('events.0.orders_count', 1));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Checkout asks the same demographics as the "about you" profile, so the answers
 * must reach the buyer's account.
 *
 * They did not: the answers only ever landed on the order, so someone who filled
 * in their gender and age band while buying a ticket still saw "—" on their
 * profile and was told it was incomplete. Phone was the one field that showed up,
 * and only for guests, because provisionBuyerAccount() happened to copy it.
 *
 * @see Profile::syncFromOrder()
 */
class ProfileSyncFromCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(array $buyer, ?User $as = null)
    {
        $event = Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Gig', 'slug' => 'gig-'.uniqid(),
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'price' => 50, 'currency' => 'MYR',
            'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => 'paid',
        ]);

        $checkout = app(CheckoutService::class);
        $order = $checkout->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]], $as?->id);
        $order->update($buyer);

        $checkout->markPaid($order->fresh());

        return $order->fresh();
    }

    public function test_a_signed_in_buyers_details_reach_their_profile(): void
    {
        $user = User::factory()->create([
            'phone' => null, 'gender' => null, 'age_band' => null, 'city' => null,
        ]);

        $this->paidOrder([
            'buyer_name' => 'Hazman', 'buyer_email' => $user->email,
            'buyer_phone' => '0123456789', 'buyer_gender' => 'male',
            'buyer_age_band' => '25-34', 'buyer_city' => 'Kuala Lumpur',
        ], $user);

        $user->refresh();

        $this->assertSame('0123456789', $user->phone);
        $this->assertSame('male', $user->gender);
        $this->assertSame('25-34', $user->age_band);
        $this->assertSame('Kuala Lumpur', $user->city);
    }

    public function test_a_guest_buyers_details_reach_the_account_provisioned_for_them(): void
    {
        // Guests got name/email/phone from provisionBuyerAccount and nothing else,
        // which is exactly why phone was the only field that ever appeared.
        $this->paidOrder([
            'buyer_name' => 'Guest', 'buyer_email' => 'guest@example.test',
            'buyer_phone' => '0111222333', 'buyer_gender' => 'female',
            'buyer_age_band' => '35-44', 'buyer_city' => 'Ipoh',
        ]);

        $user = User::where('email', 'guest@example.test')->firstOrFail();

        $this->assertSame('female', $user->gender);
        $this->assertSame('35-44', $user->age_band);
        $this->assertSame('Ipoh', $user->city);
    }

    public function test_a_profile_the_user_maintained_is_never_overwritten(): void
    {
        $user = User::factory()->create([
            'phone' => '0199999999', 'gender' => 'other',
            'age_band' => '45-54', 'city' => 'Penang',
        ]);

        $this->paidOrder([
            'buyer_name' => 'X', 'buyer_email' => $user->email,
            'buyer_phone' => '0123456789', 'buyer_gender' => 'male',
            'buyer_age_band' => '18-24', 'buyer_city' => 'Kuala Lumpur',
        ], $user);

        $user->refresh();

        // Someone who moves city and updates their profile must not be reverted
        // by their next ticket purchase.
        $this->assertSame('0199999999', $user->phone);
        $this->assertSame('other', $user->gender);
        $this->assertSame('45-54', $user->age_band);
        $this->assertSame('Penang', $user->city);
    }

    public function test_the_preselected_gender_is_not_recorded_as_an_answer(): void
    {
        $user = User::factory()->create(['gender' => null]);

        // "na" is the checkout form's default, so storing it would record
        // "prefer not to say" for everyone who never touched the field.
        $this->paidOrder([
            'buyer_name' => 'X', 'buyer_email' => $user->email,
            'buyer_gender' => 'na', 'buyer_age_band' => '25-34',
        ], $user);

        $this->assertNull($user->refresh()->gender);
        $this->assertSame('25-34', $user->age_band);
    }

    public function test_the_profile_completes_only_when_nothing_required_is_missing(): void
    {
        // Checkout never asks for country, so it alone cannot finish a profile.
        $withoutCountry = User::factory()->create([
            'phone' => null, 'gender' => null, 'age_band' => null, 'country' => null,
            'profile_completed_at' => null,
        ]);

        $this->paidOrder([
            'buyer_name' => 'X', 'buyer_email' => $withoutCountry->email,
            'buyer_phone' => '011', 'buyer_gender' => 'male', 'buyer_age_band' => '25-34',
        ], $withoutCountry);

        $this->assertNull($withoutCountry->refresh()->profile_completed_at);

        // A returning buyer who already has a country is finished by checkout.
        $withCountry = User::factory()->create([
            'phone' => null, 'gender' => null, 'age_band' => null,
            'country' => 'Malaysia', 'profile_completed_at' => null,
        ]);

        $this->paidOrder([
            'buyer_name' => 'Y', 'buyer_email' => $withCountry->email,
            'buyer_phone' => '012', 'buyer_gender' => 'female', 'buyer_age_band' => '35-44',
        ], $withCountry);

        $this->assertNotNull($withCountry->refresh()->profile_completed_at);
    }

    public function test_an_unpaid_order_syncs_nothing(): void
    {
        $user = User::factory()->create(['gender' => null]);

        $event = Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Gig', 'slug' => 'gig-'.uniqid(), 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'price' => 50, 'currency' => 'MYR',
            'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => 'paid',
        ]);

        app(CheckoutService::class)
            ->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]], $user->id)
            ->update(['buyer_gender' => 'male']);

        // An abandoned cart is not a statement about who someone is.
        $this->assertNull($user->refresh()->gender);
    }

    // ---- the backfill ------------------------------------------------------

    public function test_the_backfill_command_fixes_accounts_that_already_bought(): void
    {
        $user = User::factory()->create([
            'phone' => null, 'gender' => null, 'age_band' => null, 'city' => null,
        ]);

        $order = $this->paidOrder([
            'buyer_name' => 'Hazman', 'buyer_email' => $user->email,
            'buyer_phone' => '0123456789', 'buyer_gender' => 'male',
            'buyer_age_band' => '25-34', 'buyer_city' => 'Kuala Lumpur',
        ], $user);

        // Simulate the pre-fix state: the answers are on the order, not the user.
        // Straight to the table. Going through $user would write nothing: that
        // instance was loaded before markPaid ran the live sync, so its in-memory
        // columns are still null and Eloquent would see no change to save.
        DB::table('users')->where('id', $user->id)->update([
            'phone' => null, 'gender' => null, 'age_band' => null, 'city' => null,
        ]);
        $this->assertNull($user->fresh()->gender, 'precondition: the simulated pre-fix state did not stick');

        $this->artisan('profiles:backfill', ['--dry-run' => true])->assertSuccessful();

        // A dry run reports but writes nothing.
        $this->assertNull($user->refresh()->gender);

        $this->artisan('profiles:backfill')->assertSuccessful();

        $user->refresh();
        $this->assertSame('male', $user->gender);
        $this->assertSame('25-34', $user->age_band);
        $this->assertSame('Kuala Lumpur', $user->city);
    }

    public function test_is_complete_requires_every_mandatory_column(): void
    {
        $user = User::factory()->create([
            'phone' => '011', 'gender' => 'male', 'age_band' => '25-34', 'country' => null,
        ]);

        $this->assertFalse(Profile::isComplete($user));

        $user->update(['country' => 'Malaysia']);

        $this->assertTrue(Profile::isComplete($user->fresh()));
    }
}

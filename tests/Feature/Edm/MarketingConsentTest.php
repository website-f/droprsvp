<?php

namespace Tests\Feature\Edm;

use App\Models\EmailConsent;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Edm\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marketing consent: recorded only when someone actually chooses it.
 *
 * Before this, the only "consent" anywhere was the RSVP-terms switch, which is
 * pre-ticked and required to buy. Agreement you cannot refuse is not
 * permission to market, so every campaign recipient has to come from here.
 */
class MarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    // ---- the ledger ----------------------------------------------------------

    public function test_nobody_is_subscribed_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Consent::isSubscribed($user->email));
    }

    public function test_a_grant_subscribes_and_records_where_it_came_from(): void
    {
        Consent::grant('aisyah@example.test', 'checkout', null, null, '203.0.113.9');

        $row = EmailConsent::first();

        $this->assertTrue(Consent::isSubscribed('aisyah@example.test'));
        $this->assertSame('checkout', $row->source);
        $this->assertSame('203.0.113.9', $row->ip);
        $this->assertNotNull($row->consented_at);
    }

    public function test_addresses_are_compared_without_case_or_whitespace(): void
    {
        // An opt-out recorded against one spelling must hold for every spelling,
        // or a case mismatch mails someone who said stop.
        Consent::grant('Aisyah@Example.TEST ', 'register');

        $this->assertTrue(Consent::isSubscribed('aisyah@example.test'));

        Consent::revoke('AISYAH@example.test');

        $this->assertFalse(Consent::isSubscribed('Aisyah@Example.TEST'));
        $this->assertSame(1, EmailConsent::count());
    }

    public function test_a_revoke_leaves_a_row_even_without_prior_consent(): void
    {
        // That row is what stops a later grant path from quietly re-adding them.
        Consent::revoke('never-subscribed@example.test');

        $this->assertDatabaseHas('email_consents', [
            'email' => 'never-subscribed@example.test',
            'status' => 'unsubscribed',
        ]);
    }

    public function test_scopes_are_independent(): void
    {
        $organizer = User::factory()->create();

        Consent::grant('fan@example.test', 'checkout', null, $organizer->id);

        // Hearing from one organizer is not hearing from DropRSVP…
        $this->assertTrue(Consent::isSubscribed('fan@example.test', $organizer->id));
        $this->assertFalse(Consent::isSubscribed('fan@example.test'));

        // …and leaving DropRSVP's list does not silence the organizer.
        Consent::grant('fan@example.test', 'register');
        Consent::revoke('fan@example.test');

        $this->assertFalse(Consent::isSubscribed('fan@example.test'));
        $this->assertTrue(Consent::isSubscribed('fan@example.test', $organizer->id));
    }

    public function test_a_suppressed_address_is_never_mailable_whatever_its_consent(): void
    {
        Consent::grant('bounced@example.test', 'register');
        Consent::suppress('bounced@example.test', 'bounce', '550 5.1.1 User unknown');

        $this->assertTrue(Consent::isSubscribed('bounced@example.test'));
        $this->assertFalse(Consent::mayEmail('bounced@example.test'));
    }

    public function test_garbage_addresses_are_ignored_rather_than_stored(): void
    {
        $this->assertNull(Consent::grant('not-an-email', 'register'));
        $this->assertNull(Consent::grant('', 'register'));
        $this->assertSame(0, EmailConsent::count());
    }

    public function test_the_batch_lookup_answers_for_every_address_in_one_go(): void
    {
        Consent::grant('yes@example.test', 'register');

        $map = Consent::subscribedMap(['yes@example.test', 'NO@example.test', null, 'yes@example.test']);

        $this->assertSame(['yes@example.test' => true, 'no@example.test' => false], $map);
    }

    public function test_consent_follows_a_user_who_changes_their_email(): void
    {
        // An opt-OUT especially must not be left behind on the old address
        // while the new one starts from a clean slate.
        $user = User::factory()->create(['email' => 'old@example.test']);
        Consent::revoke('old@example.test', 'settings');

        $user->update(['email' => 'new@example.test']);

        $this->assertDatabaseHas('email_consents', ['email' => 'new@example.test', 'status' => 'unsubscribed']);
        $this->assertDatabaseMissing('email_consents', ['email' => 'old@example.test']);
    }

    // ---- where it is captured ------------------------------------------------

    private function pendingOrder(): Order
    {
        $host = User::factory()->create();
        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Gig', 'slug' => 'gig-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addWeek(),
        ]);
        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'kind' => 'free', 'price' => 0,
            'currency' => 'MYR', 'quantity' => 10, 'is_active' => true,
        ]);

        $this->post("/e/{$event->slug}/checkout", ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]]);

        return Order::latest('id')->firstOrFail();
    }

    private function buyerDetails(array $extra = []): array
    {
        return array_merge([
            'buyer_name' => 'Aisyah',
            'buyer_email' => 'aisyah@example.test',
            'buyer_phone' => '0123456789',
            'consent' => true,
        ], $extra);
    }

    public function test_checkout_records_an_opt_in_only_when_ticked(): void
    {
        $order = $this->pendingOrder();

        $this->post("/checkout/{$order->reference}/pay", $this->buyerDetails(['marketing_opt_in' => true]));

        $this->assertTrue(Consent::isSubscribed('aisyah@example.test'));
        $this->assertSame('checkout', EmailConsent::first()->source);
    }

    public function test_checkout_without_the_opt_in_records_nothing(): void
    {
        // The required RSVP-terms switch must not be read as marketing consent.
        $order = $this->pendingOrder();

        $this->post("/checkout/{$order->reference}/pay", $this->buyerDetails());

        $this->assertSame(0, EmailConsent::count());
    }

    public function test_registration_records_an_opt_in_only_when_ticked(): void
    {
        $this->post('/register', [
            'name' => 'Aisyah', 'email' => 'aisyah@example.test',
            'password' => 'a-Str0ng-Passw0rd!', 'password_confirmation' => 'a-Str0ng-Passw0rd!',
            'consent' => '1', 'marketing_opt_in' => '1',
        ]);

        $this->assertTrue(Consent::isSubscribed('aisyah@example.test'));
        $this->assertSame('register', EmailConsent::first()->source);
    }

    public function test_registration_with_the_switch_off_records_nothing(): void
    {
        // The form always posts the hidden input, so "off" arrives as '0'.
        $this->post('/register', [
            'name' => 'Aisyah', 'email' => 'aisyah@example.test',
            'password' => 'a-Str0ng-Passw0rd!', 'password_confirmation' => 'a-Str0ng-Passw0rd!',
            'consent' => '1', 'marketing_opt_in' => '0',
        ]);

        $this->assertNotNull(User::where('email', 'aisyah@example.test')->first());
        $this->assertSame(0, EmailConsent::count());
    }

    public function test_settings_can_subscribe_and_unsubscribe(): void
    {
        $user = User::factory()->create();
        $prefs = ['product_news' => true, 'event_reminders' => true, 'organizer_updates' => true];

        $this->actingAs($user)->patch('/settings/notifications', [...$prefs, 'marketing_email' => true]);
        $this->assertTrue(Consent::isSubscribed($user->email));

        $this->actingAs($user)->patch('/settings/notifications', [...$prefs, 'marketing_email' => false]);
        $this->assertFalse(Consent::isSubscribed($user->email));
    }

    public function test_re_saving_settings_does_not_refresh_the_consent_timestamp(): void
    {
        // consented_at is evidence of WHEN they agreed; an unrelated save must
        // not move it.
        $user = User::factory()->create();
        $prefs = ['product_news' => true, 'event_reminders' => true, 'organizer_updates' => true];

        $this->actingAs($user)->patch('/settings/notifications', [...$prefs, 'marketing_email' => true]);
        $first = EmailConsent::first()->consented_at;

        $this->travel(2)->days();
        $this->actingAs($user)->patch('/settings/notifications', [...$prefs, 'product_news' => false, 'marketing_email' => true]);

        $this->assertTrue($first->equalTo(EmailConsent::first()->fresh()->consented_at));
    }

    public function test_the_settings_page_shows_the_current_choice(): void
    {
        $user = User::factory()->create();
        Consent::grant($user->email, 'register', $user);

        $this->actingAs($user)->get('/settings/notifications')
            ->assertInertia(fn ($p) => $p->where('marketingEmail', true));
    }
}

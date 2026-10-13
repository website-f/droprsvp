<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Buying a ticket asks for a name, an email and a phone number, and nothing
 * else is compulsory — except the organizer's own questions they marked as
 * required.
 *
 * Before this, the "about you" page that followed a purchase demanded gender,
 * birth year and country while marking none of them, and the checkout itself
 * could be switched to demand any demographic at all.
 */
class EasyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $free;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'user_id' => $this->organizer()->id, 'title' => 'Open Day', 'slug' => 'open-day',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addWeek(),
        ]);
        $this->free = $this->event->ticketTypes()->create([
            'name' => 'RSVP', 'kind' => 'free', 'price' => 0, 'quantity' => 100,
            'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true,
        ]);
    }

    private function order(int $quantity = 1): Order
    {
        $this->post(route('checkout.start', $this->event), [
            'items' => [['ticket_type_id' => $this->free->id, 'quantity' => $quantity]],
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    /** The three things a ticket needs, and the terms. */
    private function essentials(array $overrides = []): array
    {
        return array_merge([
            'buyer_name' => 'Aisyah', 'buyer_email' => 'aisyah@example.test',
            'buyer_phone' => '0123456789', 'consent' => true,
        ], $overrides);
    }

    // ---- checkout ------------------------------------------------------------

    public function test_name_email_and_phone_are_all_it_takes(): void
    {
        $order = $this->order();

        $this->post(route('checkout.pay', $order), $this->essentials())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('checkout.confirmation', $order));

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNull($order->fresh()->buyer_birth_year);
    }

    public function test_each_of_the_three_is_required(): void
    {
        foreach (['buyer_name', 'buyer_email', 'buyer_phone'] as $field) {
            $order = $this->order();

            $this->post(route('checkout.pay', $order), $this->essentials([$field => '']))
                ->assertSessionHasErrors($field);

            $this->assertSame('pending', $order->fresh()->status, "{$field} left blank should stop checkout");
        }
    }

    public function test_the_errors_say_what_to_do(): void
    {
        $order = $this->order();

        $this->post(route('checkout.pay', $order), $this->essentials(['buyer_phone' => '']))
            ->assertSessionHasErrors(['buyer_phone' => 'Please enter a phone number, in case the organizer needs to reach you.']);
    }

    public function test_demographics_stay_optional_even_if_an_old_setting_demanded_them(): void
    {
        // A value saved by the switches that used to be on the admin settings
        // page. It must no longer have any effect.
        Setting::putArray('checkout_required', [
            'phone' => true, 'gender' => true, 'age_band' => true, 'city' => true, 'source' => true, 'notes' => true,
        ]);

        $order = $this->order();

        $this->post(route('checkout.pay', $order), $this->essentials())
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_optional_answers_are_still_kept_when_given(): void
    {
        $order = $this->order();

        $this->post(route('checkout.pay', $order), $this->essentials([
            'buyer_gender' => 'female', 'buyer_birth_year' => (int) date('Y') - 28,
            'buyer_city' => 'Kajang', 'buyer_source' => 'instagram', 'notes' => 'Vegetarian please',
        ]))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('female', $order->buyer_gender);
        $this->assertSame('25-34', $order->buyer_age_band);
        $this->assertSame('Kajang', $order->buyer_city);
        $this->assertSame('Vegetarian please', $order->notes);
    }

    public function test_an_organizer_question_marked_required_still_has_to_be_answered(): void
    {
        $this->event->update(['custom_fields' => [
            ['id' => 'meal', 'type' => 'select', 'label' => 'Meal', 'required' => true,
                'options' => [['id' => 'chicken', 'label' => 'Chicken'], ['id' => 'veg', 'label' => 'Vegetarian']]],
            ['id' => 'shirt', 'type' => 'text', 'label' => 'T-shirt size', 'required' => false],
        ]]);

        $order = $this->order(2);

        // Ticket 2 has no meal picked; nobody answered the optional question.
        $this->post(route('checkout.pay', $order), $this->essentials([
            'custom_answers' => [['meal' => 'chicken'], []],
        ]))->assertSessionHasErrors(['custom_answers.1.meal' => 'Please choose Meal for ticket 2.']);

        $this->assertSame('pending', $order->fresh()->status);

        $this->post(route('checkout.pay', $order), $this->essentials([
            'custom_answers' => [['meal' => 'chicken'], ['meal' => 'veg']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_the_page_no_longer_sends_a_required_map(): void
    {
        $order = $this->order();

        $this->get(route('checkout.show', $order))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('checkout/show')->missing('required'));
    }

    // ---- what happens after ----------------------------------------------------

    public function test_a_guest_is_not_sent_to_the_about_you_form_after_buying(): void
    {
        $order = $this->order();

        $this->post(route('checkout.pay', $order), $this->essentials())->assertSessionHasNoErrors();

        $buyer = User::where('email', 'aisyah@example.test')->firstOrFail();

        // The account checkout created already has everything a profile needs.
        $this->assertNotNull($buyer->profile_completed_at);

        // Choosing their own password is a separate, deliberate step for an
        // account we created for them; with that done, nothing else stands
        // between them and the app.
        $buyer->forceFill(['must_set_password' => false])->save();
        $this->actingAs($buyer)->get('/dashboard')->assertOk();
    }

    public function test_a_buyer_signed_in_at_checkout_sees_their_tickets_straight_away(): void
    {
        $order = $this->order();

        $this->post(route('checkout.pay', $order), $this->essentials(['auto_login' => true]))
            ->assertRedirect(route('checkout.confirmation', $order));

        $this->assertAuthenticated();

        // The confirmation page, with the tickets on it, must not be swapped
        // for a password or profile form the moment they arrive.
        $this->get(route('checkout.confirmation', $order))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('checkout/confirmation')
                // ...and it is where they are asked for a password instead.
                ->where('auth.must_set_password', true));

        // So are the links on it: the ticket itself and the list of them.
        $ticket = $order->fresh()->tickets()->firstOrFail();
        $this->get(route('tickets.show', $ticket))->assertOk();
        $this->get(route('account.tickets'))->assertOk();

        // Anywhere else still asks for the password first.
        $this->get('/dashboard')->assertRedirect(route('password.set'));
    }

    public function test_an_existing_buyer_with_a_phone_is_not_stopped_again(): void
    {
        // From before the form was cut down: a phone on file, never marked done.
        $buyer = User::factory()->create(['phone' => '0123456789', 'profile_completed_at' => null]);

        $this->actingAs($buyer)->get('/dashboard')->assertOk();

        $this->assertNotNull($buyer->fresh()->profile_completed_at);
    }

    public function test_someone_without_a_phone_is_still_asked_for_one(): void
    {
        $user = User::factory()->create(['phone' => null, 'profile_completed_at' => null]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.about-you'));
    }

    // ---- the about-you page ------------------------------------------------------

    public function test_about_you_needs_only_a_phone(): void
    {
        $user = User::factory()->create(['phone' => null, 'profile_completed_at' => null]);

        $this->actingAs($user)->post('/profile/about-you', ['phone' => '0198765432'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('0198765432', $user->phone);
        $this->assertNotNull($user->profile_completed_at);
        $this->assertNull($user->birth_year);
        $this->assertNull($user->age_band);
    }

    public function test_about_you_still_insists_on_the_phone(): void
    {
        $user = User::factory()->create(['phone' => null, 'profile_completed_at' => null]);

        $this->actingAs($user)->post('/profile/about-you', ['gender' => 'female'])
            ->assertSessionHasErrors('phone');

        $this->assertNull($user->fresh()->profile_completed_at);
    }

    public function test_skipping_a_question_does_not_wipe_an_earlier_answer(): void
    {
        // Answered at checkout; left blank on the profile form.
        $user = User::factory()->create([
            'phone' => '011', 'birth_year' => 1990, 'age_band' => '35-44', 'city' => 'Ipoh', 'country' => 'Malaysia',
        ]);

        $this->actingAs($user)->post('/profile/about-you', [
            'phone' => '011', 'birth_year' => '', 'city' => '', 'country' => '',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(1990, (int) $user->birth_year);
        $this->assertSame('35-44', $user->age_band);
        $this->assertSame('Ipoh', $user->city);
        $this->assertSame('Malaysia', $user->country);
    }

    // ---- the switches that are gone ------------------------------------------

    public function test_the_admin_settings_page_no_longer_offers_required_switches(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->missing('settings.checkout_required'));
    }
}

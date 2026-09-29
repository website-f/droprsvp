<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\CustomFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organizer-defined questions on the checkout form, answered once per ticket.
 *
 * The load-bearing property is the pairing: the buyer fills one answer set per
 * ticket, and each set must land on the ticket it was filled in for. Checkout
 * captures them as a flat list and CheckoutService issues tickets by walking
 * order items then quantity — two separate loops that have to stay in step, so
 * that is what these cover most closely.
 */
class EventCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function organizerUser(): User
    {
        return User::factory()->create();
    }

    private function eventWithFields(array $fields, float $price = 50): array
    {
        $event = Event::create([
            'user_id' => $this->organizerUser()->id,
            'title' => 'Gala', 'slug' => 'gala-'.uniqid(),
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
            'custom_fields' => CustomFields::sanitize($fields),
        ]);

        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'GA', 'price' => $price,
            'currency' => 'MYR', 'quantity' => 100, 'sold' => 0,
            'is_active' => true, 'kind' => $price > 0 ? 'paid' : 'free',
        ]);

        return [$event->fresh(), $type];
    }

    /** A picture menu — the "choose your water" case. */
    private function waterField(bool $required = true, bool $multiple = false): array
    {
        return [
            'label' => 'Which water?',
            'type' => CustomFields::IMAGE_CHOICE,
            'required' => $required,
            'multiple' => $multiple,
            'options' => [
                ['label' => 'Sparkling', 'image' => 'https://img.test/sparkling.jpg'],
                ['label' => 'Still', 'image' => 'https://img.test/still.jpg'],
            ],
        ];
    }

    // ---- definitions -------------------------------------------------------

    public function test_sanitize_mints_stable_ids_and_keeps_them(): void
    {
        $once = CustomFields::sanitize([$this->waterField()]);

        $this->assertNotEmpty($once[0]['id']);
        $this->assertNotEmpty($once[0]['options'][0]['id']);

        // Saving again must NOT regenerate them: ids are the keys every answer
        // already placed is stored under.
        $twice = CustomFields::sanitize($once);

        $this->assertSame($once[0]['id'], $twice[0]['id']);
        $this->assertSame($once[0]['options'][0]['id'], $twice[0]['options'][0]['id']);
    }

    public function test_a_choice_field_with_no_options_is_dropped(): void
    {
        // It would trap the buyer on a required question they cannot answer.
        $fields = CustomFields::sanitize([
            ['label' => 'Pick one', 'type' => CustomFields::SELECT, 'required' => true, 'options' => []],
        ]);

        $this->assertSame([], $fields);
    }

    public function test_an_unlabelled_or_unknown_field_is_dropped(): void
    {
        $fields = CustomFields::sanitize([
            ['label' => '', 'type' => CustomFields::TEXT],
            ['label' => 'Fine', 'type' => 'not_a_type'],
            ['label' => 'Kept', 'type' => CustomFields::TEXT],
        ]);

        $this->assertCount(1, $fields);
        $this->assertSame('Kept', $fields[0]['label']);
    }

    // ---- answers at checkout ----------------------------------------------

    public function test_a_required_question_must_be_answered_for_every_ticket(): void
    {
        [$event, $type] = $this->eventWithFields([$this->waterField()]);
        $field = $event->custom_fields[0];

        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 2]]);

        // Only the first ticket answered.
        [$normalised, $errors] = CustomFields::normalise($event, [[$field['id'] => $field['options'][0]['id']]], 2);

        $this->assertArrayNotHasKey("custom_answers.0.{$field['id']}", $errors);
        $this->assertArrayHasKey("custom_answers.1.{$field['id']}", $errors);
        $this->assertStringContainsString('ticket 2', $errors["custom_answers.1.{$field['id']}"]);
        $this->assertCount(2, $normalised);
    }

    public function test_an_answer_that_is_not_one_of_the_options_is_rejected(): void
    {
        [$event] = $this->eventWithFields([$this->waterField()]);
        $field = $event->custom_fields[0];

        [$normalised, $errors] = CustomFields::normalise($event, [[$field['id'] => 'not-an-option']], 1);

        $this->assertSame([], $normalised[0]);
        $this->assertArrayHasKey("custom_answers.0.{$field['id']}", $errors);
    }

    public function test_a_single_choice_stores_one_id_and_a_multiple_stores_a_list(): void
    {
        [$single] = $this->eventWithFields([$this->waterField(multiple: false)]);
        $f = $single->custom_fields[0];
        [$one] = CustomFields::normalise($single, [[$f['id'] => [$f['options'][0]['id'], $f['options'][1]['id']]]], 1);

        // Not multiple → only the first survives, as a scalar.
        $this->assertSame($f['options'][0]['id'], $one[0][$f['id']]);

        [$multi] = $this->eventWithFields([$this->waterField(multiple: true)]);
        $g = $multi->custom_fields[0];
        [$both] = CustomFields::normalise($multi, [[$g['id'] => [$g['options'][0]['id'], $g['options'][1]['id']]]], 1);

        $this->assertSame([$g['options'][0]['id'], $g['options'][1]['id']], $both[0][$g['id']]);
    }

    // ---- the pairing -------------------------------------------------------

    public function test_each_ticket_is_issued_with_its_own_answers(): void
    {
        [$event, $type] = $this->eventWithFields([$this->waterField()]);
        $field = $event->custom_fields[0];
        [$sparkling, $still] = [$field['options'][0]['id'], $field['options'][1]['id']];

        $checkout = app(CheckoutService::class);
        $order = $checkout->start($event, [['ticket_type_id' => $type->id, 'quantity' => 3]]);

        [$answers] = CustomFields::normalise($event, [
            [$field['id'] => $sparkling],
            [$field['id'] => $still],
            [$field['id'] => $sparkling],
        ], 3);

        $order->update(['custom_answers' => $answers, 'buyer_name' => 'Ana', 'buyer_email' => 'a@example.test']);

        $checkout->markPaid($order->fresh());

        $issued = $order->fresh()->tickets()->orderBy('id')->get();

        $this->assertCount(3, $issued);
        $this->assertSame($sparkling, $issued[0]->custom_answers[$field['id']]);
        $this->assertSame($still, $issued[1]->custom_answers[$field['id']]);
        $this->assertSame($sparkling, $issued[2]->custom_answers[$field['id']]);
    }

    public function test_answers_read_back_as_labels_not_ids(): void
    {
        [$event, $type] = $this->eventWithFields([$this->waterField()]);
        $field = $event->custom_fields[0];

        $readable = CustomFields::readable($event, [$field['id'] => $field['options'][1]['id']]);

        $this->assertSame(['Which water?' => 'Still'], $readable);
    }

    public function test_an_answer_whose_question_was_deleted_is_not_shown(): void
    {
        [$event] = $this->eventWithFields([$this->waterField()]);
        $staleId = $event->custom_fields[0]['id'];

        $event->update(['custom_fields' => []]);

        // A bare uuid on the attendee list would be worse than showing nothing.
        $this->assertSame([], CustomFields::readable($event->fresh(), [$staleId => 'whatever']));
    }

    // ---- the buyer-facing checkout ----------------------------------------

    public function test_the_checkout_page_receives_the_questions_and_the_ticket_count(): void
    {
        [$event, $type] = $this->eventWithFields([$this->waterField()]);
        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 2]]);

        $this->withSession(['checkout_orders' => [$order->reference]])
            ->get("/checkout/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('ticketCount', 2)
                ->where('customFields.0.label', 'Which water?')
                ->where('customFields.0.type', CustomFields::IMAGE_CHOICE)
                ->where('customFields.0.options.0.label', 'Sparkling'));
    }

    public function test_an_event_with_no_questions_asks_nothing(): void
    {
        [$event, $type] = $this->eventWithFields([]);

        [$normalised, $errors] = CustomFields::normalise($event, [], 2);

        $this->assertSame([], $normalised);
        $this->assertSame([], $errors);
    }
}

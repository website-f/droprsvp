<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\OrganizerProfile;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\AnswerInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Booking-question answers, counted — for the analytics page and the attendee
 * filters. The event here uses typed-answer questions on purpose: those had no
 * filter and no analytics at all, which is what was reported.
 */
class AnswerInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithAnswers(): Event
    {
        Mail::fake();

        $host = $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
        OrganizerProfile::create(['user_id' => $host->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $event = Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Clocktower Night', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'city' => 'Kajang', 'venue_name' => 'HOL Cafe', 'starts_at' => now()->addDays(10), 'published_at' => now(),
            'custom_fields' => [
                ['id' => 'exp', 'type' => 'select', 'label' => 'Have you played before?', 'required' => true,
                    'options' => [['id' => 'first', 'label' => 'First time'], ['id' => 'vet', 'label' => 'Veteran'], ['id' => 'pro', 'label' => 'Storyteller']]],
                ['id' => 'diet', 'type' => 'text', 'label' => 'Any dietary needs?', 'required' => false],
            ],
        ]);

        $a = TicketType::create(['event_id' => $event->id, 'name' => 'Session A', 'kind' => 'paid', 'price' => 20]);
        $b = TicketType::create(['event_id' => $event->id, 'name' => 'Session B', 'kind' => 'paid', 'price' => 20]);

        // Typed answers that differ only by case and spacing must count as one.
        foreach ([
            [$a, ['exp' => 'first', 'diet' => 'Halal'], 'Kajang'],
            [$a, ['exp' => 'first', 'diet' => 'halal '], 'Kajang'],
            [$a, ['exp' => 'vet', 'diet' => 'HALAL'], 'Kuala Lumpur'],
            [$b, ['exp' => 'vet', 'diet' => 'Vegetarian'], 'Kajang'],
            [$b, ['exp' => 'vet'], 'Kajang'],
        ] as $i => [$type, $answers, $city]) {
            $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);
            $order->update(['buyer_name' => 'Buyer '.$i, 'buyer_email' => "b{$i}@x.test", 'buyer_city' => $city, 'custom_answers' => [$answers]]);
            app(CheckoutService::class)->markPaid($order, 'T');
        }

        return $event->fresh();
    }

    public function test_choice_questions_count_every_option_including_zeros(): void
    {
        $exp = collect(AnswerInsights::forEvent($this->eventWithAnswers()))->firstWhere('id', 'exp');

        $this->assertSame('choice', $exp['kind']);
        $this->assertSame(5, $exp['answered']);
        $this->assertSame(5, $exp['total']);
        $this->assertSame(100, $exp['rate']);

        $options = collect($exp['options'])->pluck('value', 'name')->all();
        $this->assertSame(['First time' => 2, 'Veteran' => 3, 'Storyteller' => 0], $options);
    }

    public function test_choice_answers_split_by_ticket_type(): void
    {
        $exp = collect(AnswerInsights::forEvent($this->eventWithAnswers()))->firstWhere('id', 'exp');
        $byType = collect($exp['by_type'])->keyBy('type');

        $this->assertSame(['First time' => 2, 'Veteran' => 1, 'Storyteller' => 0], $byType['Session A']['counts']);
        $this->assertSame(['First time' => 0, 'Veteran' => 2, 'Storyteller' => 0], $byType['Session B']['counts']);
    }

    public function test_typed_answers_are_grouped_regardless_of_case_and_spacing(): void
    {
        $diet = collect(AnswerInsights::forEvent($this->eventWithAnswers()))->firstWhere('id', 'diet');

        $this->assertSame('text', $diet['kind']);
        $this->assertSame(4, $diet['answered'], 'One ticket-holder left it blank.');
        $this->assertSame(80, $diet['rate']);

        $values = collect($diet['values'])->pluck('value', 'name')->all();
        // "Halal", "halal " and "HALAL" are one answer, shown in the first-typed spelling.
        $this->assertSame(3, $values['Halal']);
        $this->assertSame(1, $values['Vegetarian']);
        $this->assertSame(2, $diet['distinct']);

        // Both answers are plotted, so there is nothing left to show as an example.
        $this->assertSame([], $diet['samples']);
        $this->assertSame(0, $diet['other']);
    }

    public function test_one_off_answers_appear_as_examples_not_bars_once_there_are_many(): void
    {
        $event = $this->eventWithAnswers();

        // Push the question past the "plot everything" threshold with unique answers.
        $type = $event->ticketTypes()->first();
        foreach (range(1, 9) as $i) {
            $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);
            $order->update(['buyer_name' => 'Extra '.$i, 'buyer_email' => "x{$i}@x.test", 'custom_answers' => [['exp' => 'first', 'diet' => 'Unique need '.$i]]]);
            app(CheckoutService::class)->markPaid($order, 'T');
        }

        $diet = collect(AnswerInsights::forEvent($event->fresh()))->firstWhere('id', 'diet');
        $plotted = collect($diet['values'])->pluck('name')->all();

        // Only the shared answer is a bar; the one-offs are counted and sampled.
        $this->assertSame(['Halal'], $plotted);
        $this->assertSame(10, $diet['other']);
        $this->assertNotEmpty($diet['samples']);
        $this->assertNotContains('Halal', $diet['samples']);
    }

    public function test_the_analytics_page_carries_the_answers(): void
    {
        $event = $this->eventWithAnswers();

        $this->actingAs($event->user)
            ->get(route('host.events.analytics', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('answers', 2)
                ->where('answers.0.label', 'Have you played before?')
                ->where('answers.1.kind', 'text'));
    }

    public function test_the_analytics_city_filter_narrows_the_answers(): void
    {
        $event = $this->eventWithAnswers();

        $response = $this->actingAs($event->user)
            ->get(route('host.events.analytics', $event).'?city=Kuala+Lumpur')
            ->assertOk();

        $exp = collect($response->viewData('page')['props']['answers'])->firstWhere('id', 'exp');
        $this->assertSame(1, $exp['total'], 'Only the one Kuala Lumpur ticket-holder.');
    }

    public function test_an_event_without_questions_has_no_answer_section(): void
    {
        $event = $this->eventWithAnswers();
        $event->update(['custom_fields' => []]);

        $this->assertSame([], AnswerInsights::forEvent($event->fresh()));
    }

    public function test_superadmin_event_analytics_shows_the_same_answers(): void
    {
        $event = $this->eventWithAnswers();
        $admin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->get(route('admin.analytics.show', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('data.answers', 2));
    }

    // ---- the attendee filters now cover typed answers -------------------------

    public function test_typed_answer_questions_get_filters_in_the_attendee_list(): void
    {
        $event = $this->eventWithAnswers();

        $response = $this->actingAs($event->user)->get(route('host.events.attendees', $event))->assertOk();
        $diet = collect($response->viewData('page')['props']['facets'])->firstWhere('key', 'f_diet');

        $this->assertNotNull($diet, 'A typed-answer question must appear under the booking-question filters.');
        $chips = collect($diet['options'])->keyBy('label');
        $this->assertSame(4, $chips['Answered']['count']);
        $this->assertSame(1, $chips['No answer']['count']);
        $this->assertSame(3, $chips['Halal']['count']);
    }

    public function test_filtering_by_a_typed_answer_matches_every_spelling(): void
    {
        $event = $this->eventWithAnswers();

        $names = fn (array $facets) => collect($this->actingAs($event->user)
            ->get(route('host.events.attendees', $event).'?'.http_build_query(['facets' => $facets]))
            ->viewData('page')['props']['tickets']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Buyer 0', 'Buyer 1', 'Buyer 2'], $names(['f_diet' => 'v:halal']));
        $this->assertSame(['Buyer 4'], $names(['f_diet' => '__none']));
        $this->assertCount(4, $names(['f_diet' => '__any']));
    }
}

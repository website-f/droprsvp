<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\OrganizerProfile;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PayoutService;
use App\Support\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The attendee directory, the payout release schedule, the organizer's finance
 * figures, and what a crawler can read on an event page.
 */
class OrganizerToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        // Three real checkouts per test, and nothing here is about email — so
        // don't render a QR-code tickets email for every one of them.
        Mail::fake();

        $this->host = $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
        OrganizerProfile::create(['user_id' => $this->host->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved']);

        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $this->event = Event::create([
            'user_id' => $this->host->id, 'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session', 'slug' => 'botc',
            'description' => '<p>Blood on the Clocktower is a <strong>social-deduction</strong> game.</p><p>If you are new, a dedicated Storyteller will guide you through every rule.</p>',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(3), 'published_at' => now(),
            'custom_fields' => [
                ['id' => 'exp', 'type' => 'select', 'label' => 'Have you played before?', 'required' => true,
                    'options' => [['id' => 'first', 'label' => 'First time'], ['id' => 'vet', 'label' => 'Veteran']]],
                ['id' => 'drink', 'type' => 'select', 'label' => 'Drink with your ticket', 'required' => true,
                    'options' => [['id' => 'latte', 'label' => 'Iced latte'], ['id' => 'tea', 'label' => 'Teh tarik']]],
                ['id' => 'nick', 'type' => 'text', 'label' => 'Nickname for your name tag', 'required' => false],
            ],
        ]);

        $a = TicketType::create(['event_id' => $this->event->id, 'name' => 'Session A - For Beginners', 'kind' => 'paid', 'price' => 20, 'quantity' => 15]);
        $b = TicketType::create(['event_id' => $this->event->id, 'name' => 'Session B - For Experienced Players', 'kind' => 'paid', 'price' => 20, 'quantity' => 10]);

        foreach ([
            ['Azfar Shamin', 'azfar@x.test', '012-111 1111', 'male', 1996, 'Kajang', 'instagram', $a, ['exp' => 'first', 'drink' => 'latte', 'nick' => 'Az'], null],
            ['Siti Nur', 'siti@x.test', '0122222222', 'female', 1999, 'Kuala Lumpur', 'friend', $a, ['exp' => 'first', 'drink' => 'tea'], 'Bringing a friend who uses a wheelchair.'],
            ['Daniel Lee', 'daniel@x.test', '0133333333', 'male', 1990, 'Kajang', 'tiktok', $b, ['exp' => 'vet', 'drink' => 'latte', 'nick' => 'Dan the Demon'], null],
        ] as [$name, $email, $phone, $gender, $year, $city, $source, $type, $answers, $notes]) {
            $order = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);
            $order->update([
                'buyer_name' => $name, 'buyer_email' => $email, 'buyer_phone' => $phone,
                'buyer_gender' => $gender, 'buyer_birth_year' => $year, 'buyer_age_band' => Profile::bandFor($year),
                'buyer_city' => $city, 'buyer_source' => $source, 'notes' => $notes,
                'custom_answers' => [$answers],
            ]);
            app(CheckoutService::class)->markPaid($order, 'T');
        }
    }

    private function attendees(array $query = [])
    {
        return $this->actingAs($this->host)
            ->get(route('host.events.attendees', $this->event).($query ? '?'.http_build_query($query) : ''))
            ->assertOk();
    }

    private function names($response): array
    {
        return collect($response->viewData('page')['props']['tickets']['data'])->pluck('name')->sort()->values()->all();
    }

    // ---- attendee directory ------------------------------------------------

    public function test_filters_are_built_from_the_events_own_questions_with_counts(): void
    {
        $facets = collect($this->attendees()->viewData('page')['props']['facets'])->keyBy('key');

        // One facet per choice question the organizer wrote…
        $this->assertSame('Have you played before?', $facets['f_exp']['label']);
        $exp = collect($facets['f_exp']['options'])->keyBy('label');
        $this->assertSame(2, $exp['First time']['count']);
        $this->assertSame(1, $exp['Veteran']['count']);

        // …the text question is not a facet (searchable instead)…
        $this->assertArrayNotHasKey('f_nick', $facets->all());

        // …plus the demographics buyers actually answered, and remarks.
        $this->assertTrue($facets->has('gender'));
        $this->assertTrue($facets->has('city'));
        $this->assertTrue($facets->has('source'));
        $this->assertSame(1, $facets['note']['options'][0]['count']);
    }

    public function test_picking_an_answer_narrows_the_list(): void
    {
        $this->assertSame(['Azfar Shamin', 'Siti Nur'], $this->names($this->attendees(['facets' => ['f_exp' => 'first']])));
        $this->assertSame(['Daniel Lee'], $this->names($this->attendees(['facets' => ['f_exp' => 'vet']])));
    }

    public function test_facets_combine_and_counts_follow_the_other_filters(): void
    {
        $response = $this->attendees(['facets' => ['f_exp' => 'first', 'f_drink' => 'latte']]);

        // First-timers who chose latte.
        $this->assertSame(['Azfar Shamin'], $this->names($response));

        // Faceted counts: the drink options are counted among first-timers
        // only, while the experience options ignore their own selection so the
        // organizer can still see how many veterans there are to switch to.
        $facets = collect($response->viewData('page')['props']['facets'])->keyBy('key');
        $drink = collect($facets['f_drink']['options'])->keyBy('label');
        $this->assertSame(1, $drink['Iced latte']['count']);
        $this->assertSame(1, $drink['Teh tarik']['count']);

        $exp = collect($facets['f_exp']['options'])->keyBy('label');
        $this->assertSame(1, $exp['Veteran']['count']);
    }

    public function test_demographic_and_remarks_facets_filter(): void
    {
        $this->assertSame(['Siti Nur'], $this->names($this->attendees(['facets' => ['gender' => 'female']])));
        $this->assertSame(['Azfar Shamin', 'Daniel Lee'], $this->names($this->attendees(['facets' => ['city' => 'Kajang']])));
        $this->assertSame(['Siti Nur'], $this->names($this->attendees(['facets' => ['note' => 'yes']])));
    }

    public function test_search_reaches_answers_and_phone_numbers(): void
    {
        // A free-text answer.
        $this->assertSame(['Daniel Lee'], $this->names($this->attendees(['q' => 'demon'])));
        // A phone number typed without the punctuation it was stored with.
        $this->assertSame(['Azfar Shamin'], $this->names($this->attendees(['q' => '0121111111'])));
        // Remarks are searchable too.
        $this->assertSame(['Siti Nur'], $this->names($this->attendees(['q' => 'wheelchair'])));
    }

    public function test_unknown_facets_are_ignored_rather_than_emptying_the_list(): void
    {
        $this->assertCount(3, $this->names($this->attendees(['facets' => ['f_nope' => 'x', 'bogus' => 'y']])));
    }

    public function test_each_attendee_carries_everything_checkout_asked(): void
    {
        $row = collect($this->attendees()->viewData('page')['props']['tickets']['data'])->firstWhere('name', 'Siti Nur');

        $this->assertSame('Female', $row['buyer']['gender']);
        $this->assertSame(1999, $row['buyer']['birth_year']);
        $this->assertSame('Kuala Lumpur', $row['buyer']['city']);
        $this->assertSame('A friend', $row['buyer']['source']);
        $this->assertSame('Bringing a friend who uses a wheelchair.', $row['buyer']['notes']);
        $this->assertSame('0122222222', $row['phone']);

        // EVERY question, in order, including the one she left blank.
        $this->assertSame([
            ['label' => 'Have you played before?', 'answer' => 'First time'],
            ['label' => 'Drink with your ticket', 'answer' => 'Teh tarik'],
            ['label' => 'Nickname for your name tag', 'answer' => null],
        ], $row['questions']);
    }

    public function test_the_export_matches_the_filtered_screen_and_includes_the_profile(): void
    {
        $csv = $this->actingAs($this->host)
            ->get(route('host.events.attendees.export', $this->event).'?'.http_build_query(['facets' => ['f_exp' => 'vet']]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Gender', $csv);
        $this->assertStringContainsString('Have you played before?', $csv);
        $this->assertStringContainsString('Daniel Lee', $csv);
        $this->assertStringNotContainsString('Azfar Shamin', $csv, 'The export should honour the facet filter.');
    }

    // ---- payout release schedule -------------------------------------------

    public function test_the_payout_page_says_when_held_money_unlocks(): void
    {
        $schedule = app(PayoutService::class)->releaseSchedule($this->host);

        $this->assertCount(1, $schedule);
        $this->assertSame('Blood on the Clocktower Session', $schedule[0]['title']);
        $this->assertFalse($schedule[0]['available'], 'An upcoming event is still held.');
        $this->assertSame(60.0, $schedule[0]['net']);
        $this->assertNotNull($schedule[0]['releases_on']);

        $this->actingAs($this->host)->get(route('host.payouts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('schedule', 1));
    }

    public function test_an_ended_event_shows_as_available(): void
    {
        $this->event->update(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHours(3)]);

        $schedule = app(PayoutService::class)->releaseSchedule($this->host);

        $this->assertTrue($schedule[0]['available']);
    }

    // ---- organizer finance figures add up -----------------------------------

    public function test_gross_minus_platform_fee_equals_net(): void
    {
        config(['droprsvp.platform_fee_percent' => 10, 'droprsvp.platform_fee_flat' => 0]);
        Order::query()->update(['fees' => 2.00]); // 10% of RM20

        $row = collect($this->actingAs($this->host)->get(route('host.finance.index'))
            ->assertOk()->viewData('page')['props']['events'])->first();

        $this->assertEquals(60.0, $row['gross'], 'What buyers paid for tickets.');
        $this->assertEquals(6.0, $row['fee'], 'The commission taken out of it.');
        $this->assertEquals(54.0, $row['net'], 'What the organizer is left with.');
    }

    // ---- crawlability -------------------------------------------------------

    public function test_the_event_page_body_carries_its_content_inside_the_app_mount(): void
    {
        $html = $this->get('/en-my/e/botc')->assertOk()->getContent();

        $this->assertStringNotContainsString('<noscript>', $html, 'Text extractors discard <noscript>; the content must be real body content.');
        $this->assertMatchesRegularExpression('~<div id="app">\s*<div class="seo-fallback">~', $html);

        preg_match('~<div id="app">(.*)</body>~s', $html, $mount);
        $text = html_entity_decode(strip_tags($mount[1]));

        // The WHOLE description, not the 155-character meta snippet.
        $this->assertStringContainsString('a dedicated Storyteller will guide you through every rule', $text);
        // Tickets, prices and availability.
        $this->assertStringContainsString('Session A - For Beginners', $text);
        $this->assertStringContainsString('MYR 20.00', $text);
        // Where, who, and the way in.
        $this->assertStringContainsString('HOL Cafe', $text);
        $this->assertStringContainsString('BoardLah Entertainment', $text);
        $this->assertStringContainsString('Get tickets', $text);
    }

    public function test_javascript_visitors_never_see_the_fallback_flash(): void
    {
        $html = $this->get('/en-my/e/botc')->assertOk()->getContent();

        // The class is set synchronously in <head>, before #app is parsed.
        $head = substr($html, 0, strpos($html, '</head>'));
        $this->assertStringContainsString("classList.add('js')", $head);
        $this->assertStringContainsString('.js .seo-fallback { display: none; }', $head);
    }

    public function test_the_fallback_cannot_inject_script(): void
    {
        $this->event->update(['description' => '<p>Hello</p><script>alert(1)</script><img src=x onerror="steal()">']);

        $html = $this->get('/en-my/e/botc')->assertOk()->getContent();
        preg_match('~<div id="app">(.*)</body>~s', $html, $mount);

        $this->assertStringNotContainsString('<script>alert(1)', $mount[1]);
        $this->assertStringNotContainsString('onerror', $mount[1]);
    }
}

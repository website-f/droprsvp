<?php

namespace Tests\Feature\Edm;

use App\Models\EdmAccount;
use App\Models\EdmCreditEntry;
use App\Models\EdmCreditPurchase;
use App\Models\EdmSendingDomain;
use App\Models\EmailBounce;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\OrganizerProfile;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Edm\CampaignSender;
use App\Services\Edm\CreditPurchases;
use App\Services\Edm\Credits;
use App\Services\Edm\OrganizerGuard;
use App\Services\Edm\SendingDomains;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\Health\Dns;
use App\Support\Edm\OrganizerRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 2: organizers emailing their own opted-in followers — scoped lists,
 * credits, purchases, guardrails and their own sending domains.
 */
class OrganizerEdmTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        // Organizer email is off until an admin switches it on; these tests are about using it.
        OrganizerRules::saveGlobal(['enabled' => true]);
        Mail::fake();
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false, 'edm.organizers.premium_allowance' => 100, 'edm.organizers.free_allowance' => 0]);

        $this->org = $this->organizer(['name' => 'Sophia Kuek']);
        OrganizerProfile::create(['user_id' => $this->org->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved', 'business_address' => '12 Jalan Kajang, Selangor']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);
        $this->event = Event::create([
            'user_id' => $this->org->id, 'category_id' => $category->id, 'title' => 'Clocktower Night', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(), 'published_at' => now(),
        ]);
    }

    private function subscribers(int $n, ?int $organizerId = null): void
    {
        $organizerId ??= $this->org->id;

        for ($i = 0; $i < $n; $i++) {
            Consent::grant("fan{$i}@example.com", 'checkout', null, $organizerId);
            // Opted in AND joined: only people with a ticket can be emailed.
            $this->joined("fan{$i}@example.com", $organizerId);
        }
    }

    /** A paid order for one of this organizer's events. */
    private function joined(string $email, ?int $organizerId = null): void
    {
        $organizerId ??= $this->org->id;
        $event = $organizerId === $this->org->id ? $this->event : (Event::where('user_id', $organizerId)->first() ?? Event::create([
            'user_id' => $organizerId, 'title' => 'Other night', 'slug' => 'other-'.$organizerId,
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]));

        Order::create([
            'reference' => 'DRSVP-'.strtoupper(substr(md5($email.$organizerId), 0, 8)), 'event_id' => $event->id, 'buyer_email' => $email,
            'buyer_name' => 'Fan', 'status' => 'paid', 'paid_at' => now()->subDay(), 'total' => 0, 'currency' => 'MYR',
        ]);
    }

    private function campaign(array $attrs = []): EmailCampaign
    {
        return EmailCampaign::create(array_merge([
            'organizer_id' => $this->org->id,
            'name' => 'Next session',
            'subject' => 'Hi {{first_name}}',
            'design' => ['root' => ['props' => []], 'content' => [['type' => 'Text', 'props' => ['html' => '<p>See you there, friends — the next session is on Saturday.</p>']]]],
            'audience' => [],
        ], $attrs));
    }

    // ---- consent -------------------------------------------------------------

    public function test_checkout_opt_in_joins_only_that_organizers_list(): void
    {
        $type = TicketType::create(['event_id' => $this->event->id, 'name' => 'GA', 'kind' => 'paid', 'price' => 20]);
        $this->post(route('checkout.start', $this->event), ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]]);
        $order = Order::latest('id')->firstOrFail();

        $this->post("/checkout/{$order->reference}/pay", [
            'buyer_name' => 'Aisyah', 'buyer_email' => 'Aisyah@Example.com', 'buyer_phone' => '0123456789', 'consent' => true, 'organizer_opt_in' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Consent::isSubscribed('aisyah@example.com', $this->org->id));
        $this->assertFalse(Consent::isSubscribed('aisyah@example.com')); // not the platform list
    }

    public function test_buyers_manage_organizer_lists_in_settings(): void
    {
        $buyer = User::factory()->create(['email' => 'fan@example.com']);
        Consent::grant('fan@example.com', 'checkout', $buyer, $this->org->id);

        $this->actingAs($buyer)->get('/settings/notifications')->assertOk()
            ->assertInertia(fn ($p) => $p->where('organizerLists.0.name', 'BoardLah Entertainment'));

        $this->actingAs($buyer)->post("/settings/notifications/organizers/{$this->org->id}/unsubscribe")->assertRedirect();
        $this->assertFalse(Consent::isSubscribed('fan@example.com', $this->org->id));
    }

    // ---- scoping ---------------------------------------------------------------

    public function test_the_host_workspace_is_scoped_to_the_organizer(): void
    {
        $this->subscribers(3);
        Consent::grant('platform-only@example.com', 'register');

        $this->actingAs($this->org)->post('/host/edm/campaigns', ['name' => 'Mine'])->assertRedirect();
        $mine = EmailCampaign::where('name', 'Mine')->firstOrFail();
        $this->assertSame($this->org->id, (int) $mine->organizer_id);

        $this->actingAs($this->org)->get("/host/edm/campaigns/{$mine->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('host/edm/campaigns/show')
                ->where('audienceCount', 3)
                ->where('base', '/host/edm')
                ->has('credits'));

        // Another organizer cannot reach it, nor can the platform workspace.
        $other = $this->organizer();
        OrganizerProfile::create(['user_id' => $other->id, 'business_name' => 'Other', 'status' => 'approved']);
        $this->actingAs($other)->get("/host/edm/campaigns/{$mine->id}")->assertNotFound();

        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAs($admin)->get("/admin/edm/campaigns/{$mine->id}")->assertNotFound();
    }

    public function test_an_organizer_audience_counts_only_purchases_from_their_events(): void
    {
        Consent::grant('fan0@example.com', 'checkout', null, $this->org->id);
        Consent::grant('fan1@example.com', 'checkout', null, $this->org->id);
        $other = $this->organizer();
        $otherEvent = Event::create(['user_id' => $other->id, 'category_id' => $this->event->category_id, 'title' => 'Elsewhere', 'slug' => 'elsewhere', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(), 'published_at' => now()]);
        Order::create(['reference' => 'R1', 'event_id' => $otherEvent->id, 'status' => 'paid', 'buyer_email' => 'fan0@example.com', 'paid_at' => now(), 'total' => 10]);
        Order::create(['reference' => 'R2', 'event_id' => $this->event->id, 'status' => 'paid', 'buyer_email' => 'fan1@example.com', 'paid_at' => now(), 'total' => 10]);

        $this->assertSame(1, Audience::count(['purchase' => 'buyers'], $this->org->id));
        // And with no filter at all: a ticket from someone else is not joining THEIR event.
        $this->assertSame(1, Audience::count([], $this->org->id));
    }

    public function test_organizer_subscribers_hide_platform_suppression_controls(): void
    {
        $this->subscribers(1);
        Consent::suppress('stranger@example.com', 'bounce');
        Consent::suppress('fan0@example.com', 'bounce');

        $this->actingAs($this->org)->get('/host/edm/subscribers?status=suppressed')->assertOk()
            ->assertInertia(fn ($p) => $p->component('host/edm/subscribers')
                ->where('canSuppress', false)
                ->where('counts.suppressed', 1) // only their own subscriber, never a stranger
                ->where('subscribers.data.0.email', 'fan0@example.com'));
    }

    // ---- credits ---------------------------------------------------------------

    public function test_sending_reserves_credits_and_refunds_what_was_not_sent(): void
    {
        $this->org->forceFill(['premium_until' => now()->addMonth()])->save();
        $this->subscribers(5);
        Credits::grant($this->org->id, 10);
        $campaign = $this->campaign();

        app(CampaignSender::class)->start($campaign);

        $campaign->refresh();
        $this->assertSame(5, $campaign->allowance_reserved); // free allowance first
        $this->assertSame(0, $campaign->credits_reserved);
        $this->assertSame(5, Credits::allowanceUsed($this->org->id));

        // Two go out, then it is cancelled: three come back.
        config(['edm.hourly_limit' => 120]); // 2 a minute
        app(CampaignSender::class)->dispatch();
        app(CampaignSender::class)->cancel($campaign->fresh());

        $this->assertSame(2, Credits::allowanceUsed($this->org->id));
        $this->assertNotNull($campaign->fresh()->credits_settled_at);

        // Settling twice changes nothing.
        Credits::settle($campaign->fresh());
        $this->assertSame(2, Credits::allowanceUsed($this->org->id));
    }

    public function test_not_enough_credits_stops_the_start_cleanly(): void
    {
        $this->subscribers(5);
        Credits::grant($this->org->id, 3);
        $campaign = $this->campaign();

        try {
            app(CampaignSender::class)->start($campaign);
            $this->fail('Expected not enough credits');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Not enough email credits', $e->getMessage());
        }

        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertSame(0, EmailSend::count());
        $this->assertSame(3, Credits::balance($this->org->id));
    }

    public function test_buying_a_pack_adds_credits_once(): void
    {
        $this->actingAs($this->org)->post('/host/edm/credits', ['pack' => 'starter'])->assertRedirect('/host/edm/credits');

        $this->assertSame(1000, Credits::balance($this->org->id));
        $purchase = EdmCreditPurchase::firstOrFail();
        $this->assertSame('paid', $purchase->status);

        app(CreditPurchases::class)->settle($purchase->fresh()); // webhook arriving late
        $this->assertSame(1000, Credits::balance($this->org->id));
        $this->assertSame(1, EdmCreditEntry::where('reason', 'purchase')->count());
    }

    // ---- guardrails ------------------------------------------------------------

    public function test_a_high_bounce_rate_suspends_the_organizer(): void
    {
        $campaign = $this->campaign(['status' => 'sending', 'started_at' => now()]);
        for ($i = 0; $i < 210; $i++) {
            EmailSend::create(['campaign_id' => $campaign->id, 'email' => "r{$i}@example.com", 'token' => str_pad((string) $i, 40, 'x'), 'status' => $i < 15 ? 'bounced' : 'sent', 'sent_at' => now()]);
        }

        $this->assertSame(1, OrganizerGuard::sweep());

        $account = EdmAccount::where('organizer_id', $this->org->id)->firstOrFail();
        $this->assertTrue($account->isSuspended());
        $this->assertStringContainsString('Bounce rate', (string) $account->suspended_reason);
        $this->assertSame('paused', $campaign->fresh()->status);

        // Suspended: cannot resume or start.
        $this->actingAs($this->org)->post("/host/edm/campaigns/{$campaign->id}/resume")->assertSessionHas('flash_error');
        $this->assertSame('paused', $campaign->fresh()->status);

        // A superadmin reviews and reinstates.
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAs($admin)->get('/admin/edm/organizers')->assertOk()
            ->assertInertia(fn ($p) => $p->where('organizers.0.status', 'suspended')->where('totals.suspended', 1));
        $this->actingAs($admin)->post("/admin/edm/organizers/{$this->org->id}/reinstate")->assertRedirect();
        $this->assertFalse($account->fresh()->isSuspended());

        // And the week's grace means the old numbers do not suspend them again at once.
        $this->assertSame(0, OrganizerGuard::sweep());
    }

    public function test_admin_adjusts_credits_and_allowance(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->campaign(); // makes them an EDM user

        $this->actingAs($admin)->post("/admin/edm/organizers/{$this->org->id}/adjust", ['credits' => 250, 'note' => 'Goodwill', 'monthly_allowance' => 500])->assertRedirect();

        $this->assertSame(250, Credits::balance($this->org->id));

        // Credits alone, no note field sent.
        $this->actingAs($admin)->post("/admin/edm/organizers/{$this->org->id}/adjust", ['credits' => 10])->assertRedirect();
        $this->assertSame(260, Credits::balance($this->org->id));
        $this->assertSame(500, Credits::allowance($this->org));
    }

    // ---- sending domains -------------------------------------------------------

    private function fakeDns(EdmSendingDomain $d): void
    {
        $this->app->instance(Dns::class, new class($d) extends Dns
        {
            public function __construct(private EdmSendingDomain $d) {}

            public function txt(string $name): array
            {
                return match ($name) {
                    "_droprsvp.{$this->d->domain}" => ["droprsvp-verify={$this->d->verify_token}"],
                    "droprsvp._domainkey.{$this->d->domain}" => ["v=DKIM1; k=rsa; p={$this->d->dkim_public}"],
                    default => [],
                };
            }
        });
    }

    public function test_a_verified_domain_signs_the_organizers_mail(): void
    {
        $this->actingAs($this->org)->post('/host/edm/domains', ['domain' => 'https://Mail.BoardLah.com/'])->assertSessionHasNoErrors();
        $domain = EdmSendingDomain::firstOrFail();
        $this->assertSame('mail.boardlah.com', $domain->domain);
        $this->assertStringContainsString('BEGIN', $domain->dkim_private);
        $this->assertStringNotContainsString('BEGIN', (string) $domain->getRawOriginal('dkim_private')); // encrypted at rest

        $this->fakeDns($domain);
        $this->actingAs($this->org)->post("/host/edm/domains/{$domain->id}/verify")->assertRedirect();
        $this->assertTrue($domain->fresh()->isVerified());

        // A From address on an unverified domain is refused.
        $campaign = $this->campaign();
        $this->actingAs($this->org)->put("/host/edm/campaigns/{$campaign->id}", ['name' => 'X', 'subject' => 'Hi', 'from_address' => 'hi@elsewhere.com'])->assertSessionHasErrors('from_address');
        $this->actingAs($this->org)->put("/host/edm/campaigns/{$campaign->id}", ['name' => 'X', 'subject' => 'Hi', 'from_address' => 'Hello@mail.boardlah.com'])->assertSessionHasNoErrors();
        $this->assertSame($domain->id, (int) $campaign->fresh()->sending_domain_id);

        // Send for real through the array transport and read the message.
        // A real mail manager (not the fake from setUp), so the message goes
        // through the transport the DKIM signer wraps.
        config(['edm.mailer' => 'array']);
        $manager = new MailManager($this->app);
        $this->app->instance('mail.manager', $manager);
        Mail::swap($manager);
        $this->subscribers(1);
        Credits::grant($this->org->id, 5);
        app(CampaignSender::class)->start($campaign->fresh());
        app(CampaignSender::class)->dispatch();

        $sent = $manager->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $raw = $sent[0]->toString();
        $this->assertStringContainsString('From: BoardLah Entertainment <hello@mail.boardlah.com>', $raw);
        $this->assertMatchesRegularExpression('/DKIM-Signature:.*d=mail\.boardlah\.com/s', $raw);
        $this->assertStringContainsString('s=droprsvp', $raw);
        $this->assertStringContainsString('12 Jalan Kajang', $raw); // their address in the footer
    }

    public function test_a_domain_that_stops_verifying_falls_back_to_the_platform_address(): void
    {
        $domain = app(SendingDomains::class)->add($this->org->id, 'mail.boardlah.com');
        $domain->forceFill(['status' => 'verified'])->save();
        $this->app->instance(Dns::class, new class extends Dns
        {
            public function txt(string $name): array
            {
                return [];
            }
        });

        app(SendingDomains::class)->verify($domain);

        $this->assertSame('failed', $domain->fresh()->status);
    }

    public function test_platform_domains_cannot_be_claimed(): void
    {
        config(['app.url' => 'https://www.droprsvp.com']);

        $this->actingAs($this->org)->post('/host/edm/domains', ['domain' => 'edm.droprsvp.com'])->assertSessionHasErrors('domain');
        $this->assertSame(0, EdmSendingDomain::count());
    }

    // ---- pages -----------------------------------------------------------------

    public function test_the_organizer_pages_render(): void
    {
        foreach (['/host/edm' => 'host/edm/overview', '/host/edm/campaigns' => 'host/edm/campaigns/index', '/host/edm/templates' => 'host/edm/templates/index', '/host/edm/subscribers' => 'host/edm/subscribers', '/host/edm/credits' => 'host/edm/credits', '/host/edm/domains' => 'host/edm/domains'] as $url => $component) {
            $this->actingAs($this->org)->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
        }
    }

    public function test_buyers_cannot_open_the_organizer_workspace(): void
    {
        $this->actingAs(User::factory()->create())->get('/host/edm')->assertForbidden();
    }

    public function test_complaints_count_toward_the_guardrails(): void
    {
        $campaign = $this->campaign(['status' => 'sent', 'started_at' => now()]);
        $send = EmailSend::create(['campaign_id' => $campaign->id, 'email' => 'a@example.com', 'token' => str_repeat('c', 40), 'status' => 'sent', 'sent_at' => now()]);
        EmailBounce::create(['email' => 'a@example.com', 'send_id' => $send->id, 'campaign_id' => $campaign->id, 'type' => 'complaint']);

        $this->assertSame(1, OrganizerGuard::rates($this->org->id)['complaints']);
    }
}

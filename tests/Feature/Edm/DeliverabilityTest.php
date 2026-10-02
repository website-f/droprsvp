<?php

namespace Tests\Feature\Edm;

use App\Mail\PlatformAlertMail;
use App\Models\EdmCheck;
use App\Models\EmailCampaign;
use App\Models\Setting;
use App\Models\User;
use App\Support\Edm\Health\BlocklistCheck;
use App\Support\Edm\Health\Dns;
use App\Support\Edm\Health\DomainAuth;
use App\Support\Edm\Health\Readiness;
use App\Support\Edm\SpamCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** The pre-send spam check, the DNS and blocklist checks, and the readiness list. */
class DeliverabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['edm.from.address' => 'promo@edm.droprsvp.com', 'edm.sending_ips' => ['203.0.113.7']]);
    }

    private function dns(array $txt = [], array $a = []): void
    {
        $this->app->instance(Dns::class, new class($txt, $a) extends Dns
        {
            public function __construct(private array $txtMap, private array $aMap) {}

            public function txt(string $name): array
            {
                return $this->txtMap[$name] ?? [];
            }

            public function a(string $name): array
            {
                return $this->aMap[$name] ?? [];
            }
        });
    }

    // ---- spam check ------------------------------------------------------------

    public function test_a_clean_email_scores_well(): void
    {
        $r = SpamCheck::analyse(
            subject: '{{first_name}}, three shows in KL this weekend',
            preheader: 'Jazz, comedy and a rooftop set.',
            html: '<p>Hi {{first_name}},</p><p>'.str_repeat('We have a lovely weekend of music lined up across the city for you to enjoy with friends. ', 8).'</p><a href="https://droprsvp.com/e/x">See the line-up</a> <a href="{{unsubscribe_url}}">Unsubscribe</a>',
            text: '',
            postalAddress: 'DropRSVP, 1 Jalan Ampang, 50450 Kuala Lumpur',
            fromName: 'DropRSVP',
        );

        $this->assertSame('good', $r['level']);
        $this->assertGreaterThanOrEqual(9, $r['score']);
    }

    public function test_a_spammy_email_is_flagged_with_specific_fixes(): void
    {
        $r = SpamCheck::analyse(
            subject: 'RE: FREE TICKETS!!! ACT NOW',
            preheader: '',
            html: '<p>Click here</p><a href="https://bit.ly/abc">here</a><a href="http://1.2.3.4/x">x</a><img src="a.png"><img src="b.png">',
            text: '',
            postalAddress: '',
            fromName: '',
        );

        $ids = collect($r['checks'])->where('status', '!=', 'pass')->pluck('id')->all();

        $this->assertSame('poor', $r['level']);
        foreach (['subject-caps', 'subject-punctuation', 'subject-fake-reply', 'preheader', 'links-shorteners', 'links-ip', 'postal-address', 'text-amount', 'links-click-here'] as $id) {
            $this->assertContains($id, $ids, "Expected {$id} to be flagged");
        }
    }

    public function test_free_on_its_own_is_not_spam_on_an_events_site(): void
    {
        $r = SpamCheck::analyse('Free entry jazz night on Friday', 'x', '<p>'.str_repeat('Entry is free, just turn up. ', 20).'</p>', '', 'Addr', 'DropRSVP');

        $this->assertSame('pass', collect($r['checks'])->firstWhere('id', 'subject-triggers')['status']);
    }

    // ---- DNS ---------------------------------------------------------------------

    public function test_spf_dkim_dmarc_all_present(): void
    {
        $this->dns([
            'edm.droprsvp.com' => ['v=spf1 +a +mx +ip4:203.0.113.7 ~all'],
            'default._domainkey.edm.droprsvp.com' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GN'],
            '_dmarc.edm.droprsvp.com' => ['v=DMARC1; p=quarantine; rua=mailto:d@droprsvp.com'],
        ]);

        $r = collect(app(DomainAuth::class)->check())->keyBy('name');

        $this->assertSame('pass', $r['spf']['status']);
        $this->assertSame('pass', $r['dkim']['status']);
        $this->assertSame('pass', $r['dmarc']['status']);
        $this->assertSame(3, EdmCheck::where('kind', 'dns')->where('status', 'pass')->count());
    }

    public function test_missing_and_broken_records_say_what_to_publish(): void
    {
        $this->dns([
            'edm.droprsvp.com' => ['v=spf1 a ~all', 'v=spf1 mx ~all'],     // two SPF records
            '_dmarc.droprsvp.com' => ['v=DMARC1; p=none'],                 // inherited from the root
        ]);

        $r = collect(app(DomainAuth::class)->check())->keyBy('name');

        $this->assertSame('fail', $r['spf']['status']);
        $this->assertStringContainsString('Only one is allowed', $r['spf']['detail']);
        $this->assertSame('v=spf1 +a +mx +ip4:203.0.113.7 ~all', $r['spf']['suggest']);
        $this->assertSame('fail', $r['dkim']['status']);
        $this->assertStringContainsString('cPanel', $r['dkim']['detail']);
        $this->assertSame('pass', $r['dmarc']['status']);
        $this->assertStringContainsString('inherits', $r['dmarc']['detail']);
    }

    // ---- blocklists ----------------------------------------------------------------

    public function test_a_listing_alerts_the_admins(): void
    {
        Role::findOrCreate('superadmin', 'web');
        User::factory()->create()->assignRole('superadmin');
        Setting::put('support_email', 'ops@droprsvp.com');

        $this->dns(a: ['7.113.0.203.bl.spamcop.net' => ['127.0.0.2']]);

        $results = collect(app(BlocklistCheck::class)->run());

        $this->assertSame('listed', $results->firstWhere('list', 'bl.spamcop.net')['status']);
        $this->assertSame('clean', $results->firstWhere('list', 'zen.spamhaus.org')['status']);
        Mail::assertSent(PlatformAlertMail::class);
    }

    public function test_a_refused_query_is_unknown_not_listed(): void
    {
        $this->dns(a: ['7.113.0.203.zen.spamhaus.org' => ['127.255.255.254']]);

        $results = collect(app(BlocklistCheck::class)->run());

        $this->assertSame('unknown', $results->firstWhere('list', 'zen.spamhaus.org')['status']);
        Mail::assertNothingSent();
    }

    public function test_delisting_is_announced(): void
    {
        Setting::put('support_email', 'ops@droprsvp.com');
        EdmCheck::record('blocklist', '203.0.113.7', 'bl.spamcop.net', 'listed');
        $this->dns();

        app(BlocklistCheck::class)->run();

        $this->assertSame('clean', EdmCheck::where('name', 'bl.spamcop.net')->value('status'));
        Mail::assertSent(PlatformAlertMail::class, fn ($m) => str_contains(json_encode($m), 'removed from blocklist'));
    }

    // ---- readiness -----------------------------------------------------------------

    public function test_the_checklist_reflects_the_setup(): void
    {
        Cache::forever('edm.dispatch.last', now()->toIso8601String());
        Setting::putArray('edm', ['postal_address' => 'DropRSVP, KL', 'hourly_limit' => 300]);

        $items = collect(Readiness::checklist())->keyBy('id');

        $this->assertSame('pass', $items['scheduler']['status']);
        $this->assertSame('pass', $items['postal']['status']);
        $this->assertSame('pass', $items['hourly']['status']);
        $this->assertSame('pass', $items['from']['status']); // on its own sub-domain

        Cache::forget('edm.dispatch.last');
        $this->assertSame('fail', collect(Readiness::checklist())->firstWhere('id', 'scheduler')['status']);
    }

    // ---- the page ------------------------------------------------------------------

    public function test_the_deliverability_page_and_re_run(): void
    {
        $this->dns(['edm.droprsvp.com' => ['v=spf1 +a ~all']]);
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/edm/deliverability')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/edm/deliverability')
                ->where('domain', 'edm.droprsvp.com')
                ->where('dns.0.status', 'pass')
                ->has('checklist'));

        $this->actingAs($admin)->post('/admin/edm/deliverability/check')->assertRedirect();
        $this->assertTrue(EdmCheck::where('kind', 'blocklist')->exists());
    }

    public function test_campaign_page_carries_the_spam_check(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $c = EmailCampaign::create(['name' => 'X', 'subject' => 'HELLO!!!', 'design' => ['root' => ['props' => []], 'content' => [['type' => 'Text', 'props' => ['html' => '<p>Hi</p>']]]]]);

        $this->actingAs($admin)->get("/admin/edm/campaigns/{$c->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('spam.level', fn ($l) => in_array($l, ['fair', 'poor'], true))->has('spam.checks'));
    }
}

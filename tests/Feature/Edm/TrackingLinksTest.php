<?php

namespace Tests\Feature\Edm;

use App\Models\EmailCampaign;
use App\Models\EmailLink;
use App\Models\EmailSend;
use App\Models\User;
use App\Services\Edm\CampaignSender;
use App\Support\Edm\Consent;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The links inside a campaign email.
 *
 * Public, identified by a random token only, and each one has a way to be
 * abused if done carelessly: an open redirect, an unsubscribe triggered by a
 * mail scanner, a counter that a refresh inflates.
 */
class TrackingLinksTest extends TestCase
{
    use RefreshDatabase;

    private EmailCampaign $campaign;

    private EmailSend $send;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false]);

        $user = User::factory()->create(['email' => 'reader@example.test', 'name' => 'Reader One']);
        Consent::grant('reader@example.test', 'register', $user);

        $this->campaign = app(CampaignSender::class)->start(EmailCampaign::create([
            'name' => 'News',
            'subject' => 'News',
            'design' => ['root' => ['props' => []], 'content' => [
                ['type' => 'Button', 'props' => ['label' => 'Go', 'url' => 'https://droprsvp.test/en-my/all/']],
            ]],
        ]));

        $this->send = EmailSend::firstOrFail();
    }

    private function link(): EmailLink
    {
        return $this->campaign->links()->firstOrFail();
    }

    public function test_the_open_pixel_counts_a_reader_once(): void
    {
        $this->get("/m/o/{$this->send->token}.gif")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
        $this->get("/m/o/{$this->send->token}.gif");

        $this->assertSame(1, $this->campaign->fresh()->opened_count);
        $this->assertSame(2, $this->send->fresh()->open_count);
    }

    public function test_an_unknown_token_still_gets_a_pixel_and_reveals_nothing(): void
    {
        $this->get('/m/o/'.str_repeat('z', 40).'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get('/m/o/short.gif')->assertOk();

        $this->assertSame(0, $this->campaign->fresh()->opened_count);
    }

    public function test_a_click_redirects_to_the_real_url_and_is_counted(): void
    {
        $this->get("/m/c/{$this->send->token}/{$this->link()->id}")
            ->assertRedirect('https://droprsvp.test/en-my/all/');
        $this->get("/m/c/{$this->send->token}/{$this->link()->id}");

        $this->assertSame(1, $this->campaign->fresh()->clicked_count);
        $this->assertSame(2, $this->link()->fresh()->clicks);
        $this->assertSame(1, $this->link()->fresh()->unique_clicks);
    }

    public function test_a_click_counts_as_an_open_when_images_were_blocked(): void
    {
        $this->get("/m/c/{$this->send->token}/{$this->link()->id}");

        $this->assertNotNull($this->send->fresh()->opened_at);
        $this->assertSame(1, $this->campaign->fresh()->opened_count);
    }

    public function test_a_link_from_another_campaign_is_not_an_open_redirect(): void
    {
        // Any valid token plus someone else's link id must not bounce a reader
        // to a URL this campaign never contained.
        $other = EmailCampaign::create(['name' => 'Other', 'subject' => 'x']);
        $foreign = EmailLink::create(['campaign_id' => $other->id, 'hash' => sha1('https://evil.test'), 'url' => 'https://evil.test']);

        $this->get("/m/c/{$this->send->token}/{$foreign->id}")->assertRedirect('/');
    }

    public function test_view_in_browser_shows_the_email_without_counting_an_open(): void
    {
        $this->get("/m/v/{$this->send->token}")
            ->assertOk()
            ->assertSee('Go', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame(0, $this->campaign->fresh()->opened_count);
    }

    public function test_opening_the_unsubscribe_link_does_not_unsubscribe(): void
    {
        // Mail scanners and link previewers fetch every URL in an email. A GET
        // that acted would unsubscribe people who never clicked.
        $this->get("/m/u/{$this->send->token}")->assertOk()->assertSee('Unsubscribe?', false);

        $this->assertTrue(Consent::isSubscribed('reader@example.test'));
    }

    public function test_confirming_unsubscribes_and_counts_once(): void
    {
        $this->post("/m/u/{$this->send->token}")->assertOk()->assertSee('unsubscribed', false);
        $this->post("/m/u/{$this->send->token}");

        $this->assertFalse(Consent::isSubscribed('reader@example.test'));
        $this->assertSame(1, $this->campaign->fresh()->unsubscribed_count);
    }

    public function test_the_one_click_post_from_a_mail_provider_works_without_a_csrf_token(): void
    {
        // Gmail and Yahoo send this from their own servers (RFC 8058). It can
        // carry no token and no session.
        //
        // Laravel skips CSRF checks entirely under PHPUnit, so a request alone
        // would pass even if the route were NOT excluded. Assert the exclusion
        // itself, which is what production enforces.
        $excluded = app(PreventRequestForgery::class)->getExcludedPaths();
        $this->assertContains('m/u/*', $excluded);

        $this->call('POST', "/m/u/{$this->send->token}", ['List-Unsubscribe' => 'One-Click'])
            ->assertOk()
            // Machine-to-machine: a bare 2xx, not the HTML page.
            ->assertContent('');

        $this->assertFalse(Consent::isSubscribed('reader@example.test'));
    }

    public function test_unsubscribing_from_an_organizer_leaves_droprsvp_alone(): void
    {
        $organizer = User::factory()->create();
        Consent::grant('reader@example.test', 'checkout', null, $organizer->id);

        $campaign = EmailCampaign::create(['name' => 'Org', 'subject' => 'x', 'organizer_id' => $organizer->id]);
        $send = EmailSend::create(['campaign_id' => $campaign->id, 'email' => 'reader@example.test', 'token' => str_repeat('q', 40), 'status' => 'sent']);

        $this->post("/m/u/{$send->token}");

        $this->assertFalse(Consent::isSubscribed('reader@example.test', $organizer->id));
        $this->assertTrue(Consent::isSubscribed('reader@example.test'));
    }

    public function test_an_expired_unsubscribe_link_points_to_settings(): void
    {
        $this->get('/m/u/'.str_repeat('n', 40))->assertOk()->assertSee('This link has expired', false);
    }
}

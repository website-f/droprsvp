<?php

namespace Tests\Feature\Edm;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\EmailSend;
use App\Models\User;
use App\Support\Edm\Consent;
use App\Support\Edm\Throttle;
use App\Support\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin > Email marketing: building, previewing, testing and sending.
 */
class EdmAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false]);

        Role::findOrCreate('superadmin', 'web');
        $this->admin = User::factory()->create(['name' => 'Aisyah Admin']);
        $this->admin->assignRole('superadmin');
    }

    private function campaign(array $attributes = []): EmailCampaign
    {
        return EmailCampaign::create(array_merge([
            'name' => 'October',
            'subject' => 'Hi {{first_name}}',
            'design' => ['root' => ['props' => []], 'content' => [
                ['type' => 'Text', 'props' => ['html' => '<p>Hello {{first_name}}</p>']],
            ]],
        ], $attributes));
    }

    // ---- access ---------------------------------------------------------------

    public function test_staff_need_the_email_marketing_section_granted(): void
    {
        // Emailing the whole list is not something every staff account gets.
        Role::findOrCreate('staff', 'web');
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/admin/edm/campaigns')->assertForbidden();

        RolePermissions::save(['staff' => ['edm']]);
        $this->actingAs($staff)->get('/admin/edm/campaigns')->assertOk();
    }

    public function test_an_organizer_campaign_is_not_reachable_from_the_platform_admin(): void
    {
        $organizer = User::factory()->create();
        $campaign = $this->campaign(['organizer_id' => $organizer->id]);

        $this->actingAs($this->admin)->get("/admin/edm/campaigns/{$campaign->id}")->assertNotFound();
    }

    // ---- building -------------------------------------------------------------

    public function test_a_new_campaign_starts_from_a_skeleton_not_a_blank_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/edm/campaigns', ['name' => 'Launch'])->assertRedirect();

        $campaign = EmailCampaign::firstOrFail();
        $this->assertSame('draft', $campaign->status);
        $this->assertNotEmpty($campaign->design['content']);
    }

    public function test_setup_and_audience_save_together(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->put("/admin/edm/campaigns/{$campaign->id}", [
            'name' => 'October line-up',
            'subject' => 'Three shows',
            'preheader' => 'This weekend in Kajang',
            'audience' => ['purchase' => 'buyers', 'cities' => ['Kajang'], 'nonsense' => 'dropped'],
        ])->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame('Three shows', $campaign->subject);
        $this->assertSame('buyers', $campaign->audience['purchase']);
        $this->assertSame(['Kajang'], $campaign->audience['cities']);
        $this->assertArrayNotHasKey('nonsense', $campaign->audience);
    }

    public function test_the_design_saves_from_the_editor(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->postJson("/admin/edm/campaigns/{$campaign->id}/design", ['design' => [
            'root' => ['props' => ['brandColor' => '#ff0000']],
            'content' => [['type' => 'Heading', 'props' => ['text' => 'New']]],
        ]])->assertOk();

        $this->assertSame('New', $campaign->fresh()->design['content'][0]['props']['text']);
    }

    public function test_a_sent_campaign_can_no_longer_be_changed(): void
    {
        $campaign = $this->campaign(['status' => 'sent']);

        $this->actingAs($this->admin)->put("/admin/edm/campaigns/{$campaign->id}", ['name' => 'Rewritten'])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->postJson("/admin/edm/campaigns/{$campaign->id}/design", ['design' => ['content' => []]])
            ->assertUnprocessable();

        $this->assertSame('October', $campaign->fresh()->name);
    }

    public function test_the_live_audience_count_reflects_unsaved_filters(): void
    {
        foreach (['a', 'b', 'c'] as $n) {
            $u = User::factory()->create(['email' => "{$n}@example.test", 'city' => $n === 'a' ? 'Kajang' : 'Ipoh']);
            Consent::grant($u->email, 'register', $u);
        }

        $this->actingAs($this->admin)->postJson('/admin/edm/audience-count', ['audience' => []])->assertJson(['count' => 3]);
        $this->actingAs($this->admin)->postJson('/admin/edm/audience-count', ['audience' => ['cities' => ['Kajang']]])->assertJson(['count' => 1]);
    }

    // ---- preview + test ------------------------------------------------------

    public function test_the_preview_is_the_real_render_with_a_sample_name_and_no_scripts(): void
    {
        $campaign = $this->campaign();

        $response = $this->actingAs($this->admin)->get("/admin/edm/campaigns/{$campaign->id}/preview")->assertOk();

        $response->assertSee('Hello Aisyah', false)->assertSee('Unsubscribe', false);
        // Its own stricter policy survives the global security headers.
        $this->assertStringContainsString("script-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_test_send_goes_to_the_typed_addresses_only_and_counts_nothing(): void
    {
        Mail::fake();
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/test", ['emails' => 'one@example.test, two@example.test'])
            ->assertSessionHasNoErrors();

        Mail::assertSent(CampaignMail::class, 2);
        Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => str_starts_with($m->subjectLine, '[Test] ') && ! str_contains($m->htmlBody, '{{'));
        $this->assertSame(0, EmailSend::count());
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_a_test_send_is_capped_at_five_addresses(): void
    {
        Mail::fake();
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/test", ['emails' => 'a@x.test b@x.test c@x.test d@x.test e@x.test f@x.test'])
            ->assertSessionHasErrors('emails');

        Mail::assertNothingSent();
    }

    // ---- sending ---------------------------------------------------------------

    public function test_send_now_starts_the_campaign(): void
    {
        $u = User::factory()->create();
        Consent::grant($u->email, 'register', $u);
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/send")->assertSessionHas('flash_success');

        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertSame(1, EmailSend::count());
    }

    public function test_send_without_a_subject_explains_rather_than_failing(): void
    {
        $campaign = $this->campaign(['subject' => '']);

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/send")
            ->assertSessionHas('flash_error', 'Add a subject line before sending.');

        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_a_schedule_is_typed_in_malaysian_time_and_stored_in_utc(): void
    {
        $campaign = $this->campaign();
        $this->travelTo(Carbon::parse('2026-10-01 00:00:00', 'UTC'));

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/schedule", ['at' => '2026-10-05T09:00'])
            ->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame('scheduled', $campaign->status);
        // 9am in Kuala Lumpur is 1am UTC.
        $this->assertSame('2026-10-05 01:00:00', $campaign->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_schedule_in_the_past_is_refused(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/schedule", ['at' => '2020-01-01T09:00'])
            ->assertSessionHasErrors('at');
    }

    public function test_a_sent_campaign_cannot_be_deleted(): void
    {
        // It is a record of mail that went out.
        $campaign = $this->campaign(['status' => 'sent']);

        $this->actingAs($this->admin)->delete("/admin/edm/campaigns/{$campaign->id}");

        $this->assertNotNull($campaign->fresh());
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->delete("/admin/edm/campaigns/{$campaign->id}")->assertRedirect('/admin/edm/campaigns');

        $this->assertNull($campaign->fresh());
    }

    public function test_duplicating_makes_an_unsent_draft(): void
    {
        $campaign = $this->campaign(['status' => 'sent', 'sent_count' => 50]);

        $this->actingAs($this->admin)->post("/admin/edm/campaigns/{$campaign->id}/duplicate");

        $copy = EmailCampaign::latest('id')->first();
        $this->assertSame('draft', $copy->status);
        $this->assertSame(0, $copy->sent_count);
        $this->assertSame($campaign->design, $copy->design);
    }

    // ---- subscribers + settings ----------------------------------------------

    public function test_the_subscriber_list_shows_who_opted_in_and_how(): void
    {
        $u = User::factory()->create(['email' => 'in@example.test']);
        Consent::grant('in@example.test', 'checkout', $u);
        Consent::revoke('out@example.test');

        $this->actingAs($this->admin)->get('/admin/edm/subscribers')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->has('subscribers.data', 1)
                ->where('subscribers.data.0.email', 'in@example.test')
                ->where('subscribers.data.0.source', 'checkout')
                ->where('counts.unsubscribed', 1));
    }

    public function test_an_admin_can_unsubscribe_or_suppress_on_request(): void
    {
        Consent::grant('ask@example.test', 'register');
        $row = EmailConsent::firstOrFail();

        $this->actingAs($this->admin)->post("/admin/edm/subscribers/{$row->id}/unsubscribe");
        $this->assertFalse(Consent::isSubscribed('ask@example.test'));

        $this->actingAs($this->admin)->post("/admin/edm/subscribers/{$row->id}/suppress");
        $this->assertTrue(Consent::isSuppressed('ask@example.test'));
    }

    public function test_the_hourly_limit_is_set_from_the_admin(): void
    {
        $this->actingAs($this->admin)->post('/admin/edm/settings', [
            'hourly_limit' => 240, 'from_name' => 'DropRSVP Picks', 'postal_address' => 'Kajang, Selangor',
        ])->assertSessionHasNoErrors();

        $this->assertSame(240, Throttle::hourlyLimit());
        $this->assertSame(4, Throttle::perMinute());
    }

    public function test_the_admin_postal_address_appears_in_the_footer(): void
    {
        $this->actingAs($this->admin)->post('/admin/edm/settings', ['hourly_limit' => 100, 'postal_address' => 'Lot 7, Kajang']);
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->get("/admin/edm/campaigns/{$campaign->id}/preview")->assertSee('Lot 7, Kajang', false);
    }

    public function test_every_page_renders(): void
    {
        $campaign = $this->campaign();

        $this->actingAs($this->admin)->get('/admin/edm/campaigns')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/edm/campaigns/index'));
        $this->actingAs($this->admin)->get("/admin/edm/campaigns/{$campaign->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/edm/campaigns/show'));
        $this->actingAs($this->admin)->get("/admin/edm/campaigns/{$campaign->id}/editor")->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/edm/campaigns/editor'));
        $this->actingAs($this->admin)->get('/admin/edm/subscribers')->assertOk();
        $this->actingAs($this->admin)->get('/admin/edm/settings')->assertOk();
        $this->actingAs($this->admin)->get('/admin/edm/subscribers/export')->assertOk();
    }
}

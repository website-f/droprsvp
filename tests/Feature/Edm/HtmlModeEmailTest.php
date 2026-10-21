<?php

namespace Tests\Feature\Edm;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Edm\CampaignSender;
use App\Support\Edm\Consent;
use App\Support\Edm\Renderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Emails in "HTML mode": a whole email pasted in instead of built from blocks.
 *
 * It keeps its own tables, inline styles and <style>; it still goes through the
 * same pipeline as a built email — merge tags, tracked links, the open pixel —
 * and still gets the unsubscribe footer. Pasting HTML is not a way round it.
 */
class HtmlModeEmailTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>October</title><style>.lead { color: #0a7; font-size: 18px; }</style></head>
<body style="background:#f5f5f5">
  <table width="600" style="background:#ffffff"><tr><td>
    <p class="lead">Hello {{first_name}}</p>
    <p>This weekend: board games and trivia.</p>
    <a href='https://droprsvp.test/en-my/all/' onclick="steal()">See events</a>
    <a href="javascript:alert(1)">Bad</a>
    <iframe src="https://evil.test/frame"></iframe>
    <script>alert(1)</script>
  </td></tr></table>
</body>
</html>
HTML;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edm.hourly_limit' => 6000, 'edm.warmup.enabled' => false]);
        Role::findOrCreate('superadmin', 'web');
        $this->admin = User::factory()->create(['name' => 'Aisyah Admin']);
        $this->admin->assignRole('superadmin');
    }

    private function design(string $html = self::EMAIL): array
    {
        return [
            'root' => ['props' => []],
            // The blocks from before HTML mode was switched on are kept.
            'content' => [['type' => 'Text', 'props' => ['html' => '<p>The built version</p>']]],
            'editor' => 'html',
            'html' => $html,
        ];
    }

    // ---- rendering --------------------------------------------------------------

    public function test_a_pasted_email_keeps_its_design(): void
    {
        $html = Renderer::render($this->design(), ['subject' => 'October'])['html'];

        $this->assertStringContainsString('.lead { color: #0a7; font-size: 18px; }', $html);
        $this->assertStringContainsString('<table width="600" style="background:#ffffff">', $html);
        $this->assertStringContainsString('Hello {{first_name}}', $html);
        // Not the blocks: HTML mode is what is in use.
        $this->assertStringNotContainsString('The built version', $html);
    }

    public function test_it_always_gets_the_unsubscribe_footer(): void
    {
        $rendered = Renderer::render($this->design(), ['address' => 'Kajang, Selangor']);

        $this->assertStringContainsString('{{unsubscribe_url}}', $rendered['html']);
        $this->assertStringContainsString('{{view_url}}', $rendered['html']);
        $this->assertStringContainsString('Kajang, Selangor', $rendered['html']);
        // Inside the document, not after it.
        $this->assertLessThan(strripos($rendered['html'], '</body>'), strpos($rendered['html'], '{{unsubscribe_url}}'));
        $this->assertStringContainsString('Unsubscribe: {{unsubscribe_url}}', $rendered['text']);
    }

    public function test_what_could_run_or_would_be_blocked_is_removed(): void
    {
        $html = Renderer::render($this->design())['html'];

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_the_text_version_is_the_words_not_the_css(): void
    {
        $text = Renderer::render($this->design())['text'];

        $this->assertStringContainsString('This weekend: board games and trivia.', $text);
        $this->assertStringNotContainsString('font-size', $text);
        $this->assertStringNotContainsString('October', $text, 'the <title> is not body copy');
    }

    public function test_the_preheader_goes_in_hidden_at_the_top(): void
    {
        $html = Renderer::render($this->design(), ['preheader' => 'Three nights out'])['html'];

        $this->assertMatchesRegularExpression('#<body[^>]*><div style="display:none;[^"]*">Three nights out#', $html);
    }

    public function test_a_fragment_is_made_into_a_document(): void
    {
        $html = Renderer::render($this->design('<p>Just a line</p>'), ['subject' => 'Hi'])['html'];

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<p>Just a line</p>', $html);
        $this->assertStringContainsString('{{unsubscribe_url}}', $html);
    }

    public function test_switching_back_to_the_builder_sends_the_blocks(): void
    {
        $design = ['editor' => 'builder'] + $this->design();

        $html = Renderer::render($design)['html'];

        $this->assertStringContainsString('The built version', $html);
        $this->assertStringNotContainsString('This weekend', $html);
    }

    // ---- sending ------------------------------------------------------------------

    public function test_a_pasted_campaign_sends_personalised_and_tracked(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'aisyah@example.test', 'name' => 'Aisyah Rahman']);
        Consent::grant('aisyah@example.test', 'register', $user);

        $campaign = EmailCampaign::create(['name' => 'October', 'subject' => 'Hi {{first_name}}', 'design' => $this->design()]);
        $sender = app(CampaignSender::class);

        // An email with no blocks at all is still sendable when it is pasted.
        $campaign->update(['design' => ['content' => []] + $this->design()]);
        $sender->start($campaign->fresh());
        $this->assertSame(1, $sender->dispatch());

        Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('aisyah@example.test')
            && str_contains($mail->htmlBody, 'Hello Aisyah')
            // The single-quoted link was tracked like the rest, and the pixel added.
            && str_contains($mail->htmlBody, '/m/c/')
            && str_contains($mail->htmlBody, '/m/o/')
            && str_contains($mail->htmlBody, '/m/u/'));
    }

    // ---- the editor ---------------------------------------------------------------

    public function test_the_editor_saves_both_versions(): void
    {
        $template = EmailTemplate::create(['name' => 'Monthly', 'design' => ['root' => ['props' => []], 'content' => []]]);

        $this->actingAs($this->admin)->postJson("/admin/edm/templates/{$template->id}/design", ['design' => $this->design()])
            ->assertOk();

        $design = $template->fresh()->design;
        $this->assertSame('html', $design['editor']);
        $this->assertStringContainsString('This weekend', $design['html']);
        $this->assertSame('The built version', strip_tags($design['content'][0]['props']['html']));
    }

    public function test_an_oversized_paste_is_refused(): void
    {
        $template = EmailTemplate::create(['name' => 'Monthly', 'design' => ['root' => ['props' => []], 'content' => []]]);

        $this->actingAs($this->admin)->postJson("/admin/edm/templates/{$template->id}/design", [
            'design' => $this->design(str_repeat('a', Renderer::MAX_HTML + 1)),
        ])->assertUnprocessable();
    }

    public function test_an_older_editor_that_sends_only_blocks_still_saves(): void
    {
        $template = EmailTemplate::create(['name' => 'Monthly', 'design' => ['root' => ['props' => []], 'content' => []]]);

        $this->actingAs($this->admin)->postJson("/admin/edm/templates/{$template->id}/design", [
            'design' => ['root' => ['props' => []], 'content' => [['type' => 'Text', 'props' => ['html' => '<p>Hi</p>']]]],
        ])->assertOk();

        $this->assertArrayNotHasKey('editor', $template->fresh()->design);
    }

    public function test_the_template_preview_shows_the_pasted_email_with_a_sample_name(): void
    {
        $template = EmailTemplate::create(['name' => 'Monthly', 'design' => $this->design()]);

        $this->actingAs($this->admin)->get("/admin/edm/templates/{$template->id}/preview")->assertOk()
            ->assertSee('.lead { color: #0a7;', false)
            ->assertSee('Hello Aisyah')
            ->assertHeader('Content-Security-Policy', "script-src 'none'; frame-ancestors 'self'");
    }

    public function test_a_campaign_made_from_a_pasted_template_is_pasted_too(): void
    {
        $template = EmailTemplate::create(['name' => 'Monthly', 'subject' => 'Hi', 'design' => $this->design()]);

        $this->actingAs($this->admin)->post('/admin/edm/templates/use', ['template_id' => $template->id, 'name' => 'October'])->assertRedirect();

        $this->assertTrue(Renderer::isHtml(EmailCampaign::latest('id')->firstOrFail()->design));
    }

    public function test_the_library_marks_pasted_templates(): void
    {
        EmailTemplate::create(['name' => 'Pasted', 'design' => $this->design()]);

        $this->actingAs($this->admin)->get('/admin/edm/templates')->assertOk()
            ->assertInertia(fn ($p) => $p->where('templates.0.html', true));
    }
}

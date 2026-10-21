<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\User;
use App\Support\PageHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CMS pages in "HTML mode": a whole document pasted in, styles and all,
 * instead of built from blocks. It keeps its look; it loses anything that
 * could run.
 */
class CmsHtmlModeTest extends TestCase
{
    use RefreshDatabase;

    private const DOC = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins">
  <style>body { font-family: Poppins; } .hero { background: #0a0a0a; color: #fff; }</style>
  <script>alert('head')</script>
</head>
<body class="landing">
  <section class="hero"><h1>Run your events</h1><p>Someone = everyone, at DropRSVP.</p></section>
  <img src="/logo.png" alt="Logo" onerror="alert(1)">
  <a href="javascript:alert(2)">Bad link</a>
  <a href="/en-my/all/">Browse events</a>
  <svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>
  <script>alert('body')</script>
</body>
</html>
HTML;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    private function create(array $overrides = []): CmsPage
    {
        $this->actingAs($this->superadmin())->post(route('admin.cms.pages.store'), array_merge([
            'title' => 'Landing',
            'slug' => 'landing',
            'publish' => true,
            'editor' => 'html',
            'html' => self::DOC,
            'seo' => ['robots_index' => true, 'robots_follow' => true],
        ], $overrides))->assertSessionHasNoErrors()->assertRedirect();

        return CmsPage::firstOrFail();
    }

    // ---- saving --------------------------------------------------------------

    public function test_a_pasted_page_keeps_its_design_and_loses_its_scripts(): void
    {
        $page = $this->create();

        $this->assertSame('html', $page->editor);
        // The look survives: stylesheet, fonts, classes, inline SVG as written.
        $this->assertStringContainsString('.hero { background: #0a0a0a; color: #fff; }', $page->html);
        $this->assertStringContainsString('fonts.googleapis.com', $page->html);
        $this->assertStringContainsString('<body class="landing">', $page->html);
        $this->assertStringContainsString('viewBox="0 0 10 10"', $page->html);
        // Text that merely looks like an attribute is left alone.
        $this->assertStringContainsString('Someone = everyone', $page->html);

        // Anything that could run does not.
        $this->assertStringNotContainsString('<script', $page->html);
        $this->assertStringNotContainsString('onerror', $page->html);
        $this->assertStringNotContainsString('javascript:', $page->html);
    }

    public function test_its_text_is_what_crawlers_and_the_description_get(): void
    {
        $page = $this->create();

        $this->assertStringContainsString('<h1>Run your events</h1>', $page->body);
        $this->assertStringContainsString('<a href="/en-my/all/">Browse events</a>', $page->body);
        // No styles, scripts or attributes beyond the safe few in the main document.
        $this->assertStringNotContainsString('<style', $page->body);
        $this->assertStringNotContainsString('class=', $page->body);
        $this->assertStringNotContainsString('alert', $page->body);
    }

    public function test_the_public_page_renders_it_between_the_site_header_and_footer(): void
    {
        $this->create();

        $this->get('/en-my/landing/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/page')
                ->where('page.editor', 'html')
                ->where('page.html', fn ($html) => str_contains($html, '.hero {') && ! str_contains($html, '<script')))
            // Crawlers read the page's words without JavaScript.
            ->assertSee('Run your events')
            ->assertSee('Someone = everyone, at DropRSVP.');
    }

    public function test_the_description_falls_back_to_the_pasted_text(): void
    {
        $this->create();

        $this->get('/en-my/landing/')->assertSee('<meta name="description" content="Run your events', false);
    }

    public function test_an_oversized_paste_is_refused(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.cms.pages.store'), [
            'title' => 'Huge', 'editor' => 'html', 'html' => str_repeat('a', PageHtml::MAX + 1),
        ])->assertSessionHasErrors('html');
    }

    // ---- switching -------------------------------------------------------------

    public function test_switching_back_to_the_builder_keeps_both_versions(): void
    {
        $page = $this->create();
        $page->update(['puck_data' => ['content' => [['type' => 'Heading', 'props' => ['title' => 'Built heading']]], 'root' => ['props' => []]]]);

        $this->put(route('admin.cms.pages.update', $page->id), [
            'title' => 'Landing', 'slug' => 'landing', 'publish' => true, 'editor' => 'builder',
        ])->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('builder', $page->editor);
        // The pasted page is still there to switch back to.
        $this->assertStringContainsString('.hero {', $page->html);
        // And the text snapshot is the builder's again.
        $this->assertSame('Built heading', $page->body);

        $this->get('/en-my/landing/')->assertInertia(fn (Assert $p) => $p->where('page.editor', 'builder')->where('page.html', null));
    }

    public function test_saving_from_the_builder_means_the_page_uses_the_builder(): void
    {
        $page = $this->create();

        $this->postJson(route('admin.cms.pages.builder.save', $page->id), [
            'data' => ['content' => [['type' => 'Heading', 'props' => ['title' => 'From the builder']]], 'root' => ['props' => []]],
        ])->assertOk();

        $this->assertSame('builder', $page->fresh()->editor);
    }

    public function test_a_form_that_does_not_mention_the_editor_leaves_it_alone(): void
    {
        $page = $this->create();

        // An older form, or a metadata-only save.
        $this->put(route('admin.cms.pages.update', $page->id), ['title' => 'Renamed', 'slug' => 'landing', 'publish' => true])
            ->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('html', $page->editor);
        $this->assertStringContainsString('.hero {', $page->html);
    }

    public function test_the_editor_loads_the_pasted_page_back(): void
    {
        $page = $this->create();

        $this->get(route('admin.cms.pages.edit', $page->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.editor', 'html')->where('page.html', $page->html));
    }

    public function test_the_draft_preview_shows_the_pasted_page(): void
    {
        $page = $this->create(['publish' => false]);

        $this->get(route('admin.cms.pages.preview', $page->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.editor', 'html')->where('preview', true));
    }

    // ---- the cleaner on its own --------------------------------------------------

    public function test_a_quoted_angle_bracket_cannot_hide_a_handler(): void
    {
        $clean = PageHtml::clean('<img title=">" /onerror=alert(1) src="x.png">');

        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringContainsString('src="x.png"', $clean);
    }

    public function test_nested_documents_and_redirects_are_removed(): void
    {
        $clean = PageHtml::clean(
            '<iframe srcdoc="<script>x</script>" src="https://www.youtube.com/embed/x"></iframe>'
            .'<meta http-equiv="refresh" content="0;url=https://evil.test"><base href="https://evil.test/">'
            .'<object data="x.swf"></object><a href=javascript:alert(1)>x</a>'
        );

        $this->assertStringNotContainsString('srcdoc', $clean);
        $this->assertStringNotContainsString('http-equiv', $clean);
        $this->assertStringNotContainsString('<base', $clean);
        $this->assertStringNotContainsString('<object', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        // A real embed is kept.
        $this->assertStringContainsString('https://www.youtube.com/embed/x', $clean);
    }

    public function test_a_page_without_head_or_body_still_works(): void
    {
        $this->assertSame('<p>Just a paragraph</p>', PageHtml::content('<p>Just a paragraph</p>'));
        $this->assertSame('', PageHtml::content('   '));
    }
}

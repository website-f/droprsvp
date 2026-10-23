<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\Setting;
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

    public function test_a_pasted_page_is_kept_as_written_scripts_and_all(): void
    {
        $page = $this->create();

        $this->assertSame('html', $page->editor);
        // The look survives: stylesheet, fonts, classes, inline SVG as written.
        $this->assertStringContainsString('.hero { background: #0a0a0a; color: #fff; }', $page->html);
        $this->assertStringContainsString('fonts.googleapis.com', $page->html);
        $this->assertStringContainsString('<body class="landing">', $page->html);
        $this->assertStringContainsString('viewBox="0 0 10 10"', $page->html);
        $this->assertStringContainsString('Someone = everyone', $page->html);

        // So does its JavaScript: it runs, in the sandboxed frame only.
        $this->assertStringContainsString("<script>alert('head')</script>", $page->html);
        $this->assertStringContainsString('onerror="alert(1)"', $page->html);
    }

    public function test_its_scripts_never_reach_the_page_as_markup(): void
    {
        $this->create();

        $html = $this->get('/en-my/landing/')->assertOk()->getContent();

        // Not in the crawler text, and not readable as tags in the page data:
        // every "<" there is \u003C, decoded only by JavaScript.
        $this->assertStringNotContainsString("<script>alert('head')", $html);
        $this->assertStringNotContainsString('onerror="alert(1)"', $html);
        $this->assertStringContainsString(chr(92).'u003Cscript'.chr(92).'u003Ealert', $html);
    }

    public function test_a_commented_out_script_cannot_break_the_page(): void
    {
        // "<!-- <script>" inside the page data can make a browser lose track of
        // where that <script> tag ends and swallow the rest of the page.
        $this->create(['html' => '<p>Before</p><!-- <script>old()</script> --><p>After</p>']);

        $html = $this->get('/en-my/landing/')->assertOk()->getContent();

        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);
        $this->assertStringNotContainsString('<', $m[1]);
        $this->assertSame('<p>Before</p><!-- <script>old()</script> --><p>After</p>', json_decode($m[1], true)['props']['page']['html']);
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
                // Carried as written, scripts included, for the sandboxed frame.
                ->where('page.html', fn ($html) => str_contains($html, '.hero {') && str_contains($html, '<script')))
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

    // ---- partial vs full ----------------------------------------------------------

    public function test_a_page_from_before_the_choice_is_left_to_auto_detection(): void
    {
        // What /pricing is: pasted content, no styles, saved before the choice
        // existed. Null tells the page to work it out — partial, site styling.
        $page = CmsPage::create([
            'title' => 'Pricing', 'slug' => 'pricing', 'status' => 'published', 'published_at' => now(),
            'editor' => 'html', 'html' => '<h1>Simple Pricing</h1><table><tr><td>RM10</td></tr></table>',
        ]);

        $this->assertNull($page->html_layout);
        $this->get('/en-my/pricing/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.editor', 'html')->where('page.html_layout', null));
    }

    public function test_the_choice_is_saved_and_sent_everywhere(): void
    {
        $page = $this->create(['html_layout' => 'content']);

        $this->assertSame('content', $page->html_layout);
        $this->get('/en-my/landing/')->assertInertia(fn (Assert $p) => $p->where('page.html_layout', 'content'));
        $this->get(route('admin.cms.pages.edit', $page->id))->assertInertia(fn (Assert $p) => $p->where('page.html_layout', 'content'));
        $this->get(route('admin.cms.pages.preview', $page->id))->assertInertia(fn (Assert $p) => $p->where('page.html_layout', 'content'));

        // Back to Auto.
        $this->put(route('admin.cms.pages.update', $page->id), [
            'title' => 'Landing', 'slug' => 'landing', 'publish' => true, 'editor' => 'html', 'html' => self::DOC, 'html_layout' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($page->fresh()->html_layout);
    }

    public function test_only_the_two_layouts_are_accepted(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.cms.pages.store'), [
            'title' => 'Odd', 'editor' => 'html', 'html' => '<p>x</p>', 'html_layout' => 'sideways',
        ])->assertSessionHasErrors('html_layout');
    }

    // ---- structured data that came with the paste -----------------------------------

    public function test_pasted_faq_schema_is_kept_and_served_in_the_head(): void
    {
        $faq = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Is it free?","acceptedAnswer":{"@type":"Answer","text":"Free events cost nothing."}}]}</script>';

        $page = $this->create(['html' => '<h1>Pricing</h1><p>Free to host.</p>'.$faq, 'html_layout' => 'content']);

        // Stored, unlike every other script.
        $this->assertStringContainsString('application/ld+json', $page->html);

        $html = $this->get('/en-my/landing/')->assertOk()->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $graph = json_decode(str_replace('<\/', '</', $m[1]), true)['@graph'];

        $node = collect($graph)->firstWhere('@type', 'FAQPage');
        $this->assertSame('Is it free?', $node['mainEntity'][0]['name']);
    }

    public function test_json_ld_cannot_smuggle_a_script_out(): void
    {
        // Valid JSON whose text, once decoded, holds a closing tag and a script.
        $clean = PageHtml::clean(
            '<script type="application/ld+json">{"@type":"Thing","name":"<\/script><script>alert(1)<\/script>"}</script>'
            .'<script>alert(2)</script>'
        );

        // One script element, closed once: the "</" inside the data is escaped,
        // so the browser never sees the block end early.
        $this->assertSame(1, substr_count(strtolower($clean), '</script>'));
        $this->assertStringNotContainsString('alert(2)', $clean);

        // And what is inside is still the same data.
        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $clean, $m);
        $this->assertSame('</script><script>alert(1)</script>', json_decode($m[1], true)['name']);
    }

    public function test_broken_json_ld_is_dropped_rather_than_kept_as_a_script(): void
    {
        $clean = PageHtml::clean('<p>Hi</p><script type="application/ld+json">{not json</script>');

        $this->assertSame('<p>Hi</p>', $clean);
    }

    // ---- the HTML block in the Drop Builder ----------------------------------------

    public function test_an_html_block_mixes_in_with_the_builder_blocks(): void
    {
        $this->actingAs($this->superadmin());
        $page = CmsPage::create(['title' => 'Mixed', 'slug' => 'mixed', 'status' => 'published', 'published_at' => now()]);

        $this->postJson(route('admin.cms.pages.builder.save', $page->id), ['data' => [
            'root' => ['props' => []],
            'content' => [
                ['type' => 'Heading', 'props' => ['id' => 'h', 'text' => 'Plans', 'level' => 'h2', 'align' => 'text-left']],
                ['type' => 'Html', 'props' => ['id' => 'x', 'layout' => 'auto', 'html' => '<style>.p{color:red}</style><table><tr><td>RM3 per ticket</td></tr></table><img src=x onerror="alert(1)"><script>alert(2)</script>']],
                // Any other block still has script stripped from its HTML.
                ['type' => 'Text', 'props' => ['id' => 't', 'text' => '<p>Hi</p><script>alert(3)</script>', 'align' => 'text-left']],
            ],
        ]])->assertOk();

        $page->refresh();
        $block = $page->puck_data['content'][1]['props'];

        // The HTML block is kept as written — its JavaScript runs in the sandbox.
        $this->assertStringContainsString('<table>', $block['html']);
        $this->assertStringContainsString('.p{color:red}', $block['html']);
        $this->assertStringContainsString('<script>alert(2)</script>', $block['html']);
        $this->assertStringNotContainsString('alert(3)', $page->puck_data['content'][2]['props']['text']);

        // Search and crawlers get its words, not its CSS, scripts or tags.
        $this->assertStringContainsString('RM3 per ticket', $page->body);
        $this->assertStringNotContainsString('color:red', $page->body);
        $this->assertStringNotContainsString('alert', $page->body);
        $this->assertStringNotContainsString('<table', $page->body);

        $this->get('/en-my/mixed/')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('page.puck.content.1.type', 'Html'));
    }

    // ---- whose scripts run in the page itself -----------------------------------------

    private function staffWithContent(): User
    {
        Role::findOrCreate('staff', 'web');
        Setting::putArray('role_permissions', ['staff' => ['content']]);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        return $staff;
    }

    public function test_a_superadmin_save_lets_the_pages_scripts_run_in_the_page(): void
    {
        $page = $this->create();

        $this->assertTrue($page->html_trusted);
        $this->get('/en-my/landing/')->assertInertia(fn (Assert $p) => $p->where('page.html_trusted', true));
    }

    public function test_a_staff_save_keeps_them_in_the_sealed_frame(): void
    {
        $staff = $this->staffWithContent();

        $this->actingAs($staff)->post(route('admin.cms.pages.store'), [
            'title' => 'Staff page', 'slug' => 'staff-page', 'publish' => true,
            'editor' => 'html', 'html' => '<p>Hi</p><script>steal()</script>',
            'seo' => ['robots_index' => true, 'robots_follow' => true],
        ])->assertSessionHasNoErrors();

        $page = CmsPage::where('slug', 'staff-page')->firstOrFail();
        $this->assertFalse($page->html_trusted);
        $this->get('/en-my/staff-page/')->assertInertia(fn (Assert $p) => $p->where('page.html_trusted', false));
    }

    public function test_staff_touching_a_trusted_page_makes_it_untrusted_again(): void
    {
        $page = $this->create();
        $this->assertTrue($page->html_trusted);

        // Even a small edit: what is saved is now theirs.
        $this->actingAs($this->staffWithContent())->put(route('admin.cms.pages.update', $page->id), [
            'title' => 'Landing', 'slug' => 'landing', 'publish' => true, 'editor' => 'html', 'html' => $page->html.'<script>more()</script>',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($page->fresh()->html_trusted);
    }

    public function test_the_builder_records_who_saved_too(): void
    {
        $page = $this->create();

        $this->actingAs($this->staffWithContent())->postJson(route('admin.cms.pages.builder.save', $page->id), [
            'data' => ['root' => ['props' => []], 'content' => [['type' => 'Html', 'props' => ['id' => 'x', 'layout' => 'auto', 'html' => '<script>x()</script>']]]],
        ])->assertOk();
        $this->assertFalse($page->fresh()->html_trusted);

        $this->actingAs($this->superadmin())->postJson(route('admin.cms.pages.builder.save', $page->id), [
            'data' => ['root' => ['props' => []], 'content' => [['type' => 'Html', 'props' => ['id' => 'x', 'layout' => 'auto', 'html' => '<script>x()</script>']]]],
        ])->assertOk();
        $this->assertTrue($page->fresh()->html_trusted);
    }

    public function test_pages_from_before_this_start_untrusted(): void
    {
        $page = CmsPage::create(['title' => 'Old', 'slug' => 'old', 'status' => 'published', 'published_at' => now(), 'editor' => 'html', 'html' => '<script>x()</script>']);

        $this->assertFalse($page->fresh()->html_trusted);
    }
}

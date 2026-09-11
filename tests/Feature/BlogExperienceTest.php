<?php

namespace Tests\Feature;

use App\Models\CmsCategory;
use App\Models\CmsPost;
use App\Models\Setting;
use App\Support\TableOfContents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Table of contents, the sidebar rail, category switching, and the home strip. */
class BlogExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(string $slug, string $body, ?CmsCategory $category = null): CmsPost
    {
        return CmsPost::create([
            'title' => ucfirst($slug), 'slug' => $slug, 'body' => $body,
            'category_id' => $category?->id, 'status' => 'published', 'published_at' => now()->subMinute(),
        ]);
    }

    // ---- table of contents -------------------------------------------------

    public function test_headings_are_given_stable_anchors_and_collected(): void
    {
        $out = TableOfContents::build('<h2>First Up</h2><p>x</p><h3>Details</h3><h2>Second</h2>');

        $this->assertSame(
            [['id' => 'first-up', 'text' => 'First Up', 'level' => 2],
                ['id' => 'details', 'text' => 'Details', 'level' => 3],
                ['id' => 'second', 'text' => 'Second', 'level' => 2]],
            $out['items'],
        );
        $this->assertStringContainsString('<h2 id="first-up">First Up</h2>', $out['html']);
    }

    public function test_duplicate_headings_get_distinct_anchors(): void
    {
        $out = TableOfContents::build('<h2>Setup</h2><h2>Setup</h2>');

        $this->assertSame(['setup', 'setup-2'], array_column($out['items'], 'id'));
    }

    public function test_an_author_supplied_heading_id_is_respected(): void
    {
        $out = TableOfContents::build('<h2 id="my-anchor">Hello</h2>');

        $this->assertSame('my-anchor', $out['items'][0]['id']);
        $this->assertStringNotContainsString('id="hello"', $out['html']);
    }

    public function test_the_inline_toc_block_is_replaced_with_the_real_list(): void
    {
        $out = TableOfContents::build('<div data-toc="1" class="post-toc"><p>placeholder</p></div><h2>Alpha</h2>');

        $this->assertStringNotContainsString('placeholder', $out['html']);
        $this->assertStringContainsString('<nav class="post-toc"', $out['html']);
        $this->assertStringContainsString('href="#alpha"', $out['html']);
    }

    public function test_a_toc_block_with_no_headings_is_dropped(): void
    {
        $out = TableOfContents::build('<div data-toc="1" class="post-toc"><p>placeholder</p></div><p>Just prose.</p>');

        $this->assertStringNotContainsString('post-toc', $out['html']);
        $this->assertSame([], $out['items']);
    }

    public function test_headings_are_escaped_in_the_rendered_list(): void
    {
        $out = TableOfContents::build('<div data-toc="1" class="post-toc"></div><h2>Tips &amp; <em>tricks</em></h2>');

        $this->assertStringContainsString('Tips &amp; tricks', $out['html']);
        $this->assertStringNotContainsString('<em>tricks</em></a>', $out['html']);
    }

    public function test_the_post_page_serves_anchored_html_and_the_contents_rail(): void
    {
        $this->makePost('guide', '<h2>Step One</h2><p>x</p><h3>Detail</h3>');

        $this->get('/en-my/blog/guide')->assertOk()
            ->assertInertia(fn ($p) => $p->component('public/blog/show')
                ->count('toc', 2)
                ->where('toc.0.id', 'step-one')
                ->where('post.reading_minutes', 1)
                ->where('post.body', fn (string $html) => str_contains($html, 'id="step-one"')));
    }

    // ---- sidebar + categories ---------------------------------------------

    public function test_the_sidebar_carries_categories_with_counts_and_recent_posts(): void
    {
        $news = CmsCategory::create(['name' => 'News', 'slug' => 'news']);
        $this->makePost('one', '<p>a</p>', $news);
        $this->makePost('two', '<p>b</p>', $news);

        $this->get('/en-my/blog')->assertOk()
            ->assertInertia(fn ($p) => $p->where('sidebar.categories.0.slug', 'news')
                ->where('sidebar.categories.0.count', 2)
                ->count('sidebar.recent', 2));
    }

    public function test_the_article_rail_excludes_the_post_being_read(): void
    {
        $this->makePost('one', '<p>a</p>');
        $this->makePost('two', '<p>b</p>');

        $this->get('/en-my/blog/one')->assertOk()
            ->assertInertia(fn ($p) => $p->count('sidebar.recent', 1)->where('sidebar.recent.0.slug', 'two'));
    }

    public function test_the_index_filters_by_category(): void
    {
        $news = CmsCategory::create(['name' => 'News', 'slug' => 'news']);
        $this->makePost('newsy', '<p>a</p>', $news);
        $this->makePost('other', '<p>b</p>');

        $this->get('/en-my/blog?category=news')->assertOk()
            ->assertInertia(fn ($p) => $p->count('posts.data', 1)
                ->where('posts.data.0.slug', 'newsy')
                ->where('activeCategory', 'news'));

        $this->get('/en-my/blog?category=nope')->assertNotFound();
    }

    public function test_a_filtered_listing_canonicalises_to_itself(): void
    {
        CmsCategory::create(['name' => 'News', 'slug' => 'news']);

        $this->get('/en-my/blog?category=news')
            ->assertSee('<link rel="canonical" href="'.rtrim(url('/'), '/').'/en-my/blog/?category=news"', false);
    }

    public function test_related_posts_come_from_the_same_category(): void
    {
        $news = CmsCategory::create(['name' => 'News', 'slug' => 'news']);
        $other = CmsCategory::create(['name' => 'Tips', 'slug' => 'tips']);
        $this->makePost('reading', '<p>a</p>', $news);
        $this->makePost('sibling', '<p>b</p>', $news);
        $this->makePost('unrelated', '<p>c</p>', $other);

        $this->get('/en-my/blog/reading')->assertOk()
            ->assertInertia(fn ($p) => $p->count('sidebar.related', 1)
                ->where('sidebar.related.0.slug', 'sibling'));
    }

    public function test_the_listing_rail_has_no_related_block(): void
    {
        $news = CmsCategory::create(['name' => 'News', 'slug' => 'news']);
        $this->makePost('one', '<p>a</p>', $news);

        $this->get('/en-my/blog')->assertInertia(fn ($p) => $p->count('sidebar.related', 0));
    }

    public function test_the_page_reports_whether_the_author_placed_their_own_contents_block(): void
    {
        $this->makePost('plain', '<h2>One</h2><h2>Two</h2>');
        $this->makePost('inline', '<div data-toc="1" class="post-toc"></div><h2>One</h2><h2>Two</h2>');

        $this->get('/en-my/blog/plain')->assertInertia(fn ($p) => $p->where('hasInlineToc', false)->count('toc', 2));
        $this->get('/en-my/blog/inline')->assertInertia(fn ($p) => $p->where('hasInlineToc', true));
    }

    /** The first, uncategorised post leaves every conditional widget empty. */
    public function test_a_solo_uncategorised_post_still_renders_with_an_empty_rail(): void
    {
        $this->makePost('only', '<h2>One</h2><h2>Two</h2>');

        $this->get('/en-my/blog/only')->assertOk()
            ->assertInertia(fn ($p) => $p->count('sidebar.categories', 0)
                ->count('sidebar.related', 0)
                ->count('sidebar.recent', 0)
                ->where('sidebar.ad.enabled', false));
    }

    // ---- the promo slot ----------------------------------------------------

    public function test_the_promo_slot_is_off_until_an_admin_turns_it_on(): void
    {
        $this->makePost('one', '<p>a</p>');

        $this->get('/en-my/blog')->assertInertia(fn ($p) => $p->where('sidebar.ad.enabled', false));

        Setting::putArray('blog_ad', ['enabled' => true, 'title' => 'Advertise', 'image' => '/storage/ad.png', 'url' => 'https://x.test', 'caption' => 'Reach readers']);

        $this->get('/en-my/blog')->assertInertia(fn ($p) => $p->where('sidebar.ad.enabled', true)
            ->where('sidebar.ad.image', '/storage/ad.png'));
    }

    // ---- footer column -----------------------------------------------------

    public function test_the_footer_carries_the_three_newest_post_titles(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->makePost($slug, '<p>x</p>');
        }

        // Shared on every page, not just the blog.
        $this->get('/en-my')->assertOk()
            ->assertInertia(fn ($p) => $p->count('footerPosts', 3)
                ->where('footerPosts.0.slug', 'd')
                ->where('footerPosts.0.title', 'D'));
    }

    public function test_the_footer_list_holds_no_scheduled_or_draft_posts(): void
    {
        CmsPost::create(['title' => 'Soon', 'slug' => 'soon', 'body' => 'x', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        CmsPost::create(['title' => 'Rough', 'slug' => 'rough', 'body' => 'x', 'status' => 'draft']);
        $this->makePost('live', '<p>x</p>');

        $this->get('/en-my')->assertInertia(fn ($p) => $p->count('footerPosts', 1)
            ->where('footerPosts.0.slug', 'live'));
    }

    public function test_the_footer_list_refreshes_when_a_post_is_published(): void
    {
        $this->get('/en-my')->assertInertia(fn ($p) => $p->count('footerPosts', 0));

        $this->makePost('fresh', '<p>x</p>');

        $this->get('/en-my')->assertInertia(fn ($p) => $p->count('footerPosts', 1));
    }

    // ---- homepage strip ----------------------------------------------------

    public function test_the_homepage_carries_the_three_latest_posts(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->makePost($slug, '<p>x</p>');
        }

        $this->get('/en-my')->assertOk()
            ->assertInertia(fn ($p) => $p->count('posts', 3)->has('sections.blog'));
    }
}

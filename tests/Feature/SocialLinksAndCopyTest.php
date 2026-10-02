<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use App\Support\SocialLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Organizer social links, and category copy that rendered its own HTML tags.
 */
class SocialLinksAndCopyTest extends TestCase
{
    use RefreshDatabase;

    // ---- normalising what people type ------------------------------------------

    public function test_every_way_people_type_a_profile_becomes_a_link(): void
    {
        $this->assertSame('https://www.instagram.com/boardlah', SocialLinks::normalise('instagram', 'https://www.instagram.com/boardlah'));
        $this->assertSame('https://instagram.com/boardlah', SocialLinks::normalise('instagram', 'instagram.com/boardlah'));
        $this->assertSame('https://www.instagram.com/boardlah', SocialLinks::normalise('instagram', '@boardlah'));
        $this->assertSame('https://www.tiktok.com/@boardlah', SocialLinks::normalise('tiktok', 'boardlah'));
        // The old domain still counts as X.
        $this->assertSame('https://twitter.com/boardlah', SocialLinks::normalise('x', 'twitter.com/boardlah'));
    }

    public function test_a_whatsapp_number_becomes_a_wa_me_link(): void
    {
        $this->assertSame('https://wa.me/60123456789', SocialLinks::normalise('whatsapp', '012-345 6789'));
        $this->assertSame('https://wa.me/60123456789', SocialLinks::normalise('whatsapp', '+60 12 345 6789'));
    }

    public function test_another_platforms_link_is_refused(): void
    {
        // Otherwise an Instagram logo would lead to a TikTok page.
        $this->assertFalse(SocialLinks::normalise('instagram', 'https://www.tiktok.com/@boardlah'));
        $this->assertFalse(SocialLinks::normalise('facebook', 'https://evil.test/facebook.com'));
    }

    public function test_a_dangerous_scheme_is_refused(): void
    {
        $this->assertFalse(SocialLinks::normalise('instagram', 'javascript:alert(1)'));
    }

    public function test_subdomains_and_country_domains_are_accepted(): void
    {
        $this->assertSame('https://m.facebook.com/boardlah', SocialLinks::normalise('facebook', 'https://m.facebook.com/boardlah'));
        $this->assertSame('https://www.pinterest.co.uk/boardlah', SocialLinks::normalise('pinterest', 'https://www.pinterest.co.uk/boardlah'));
    }

    public function test_blank_means_nothing_to_save(): void
    {
        $this->assertNull(SocialLinks::normalise('instagram', '   '));
    }

    // ---- saving --------------------------------------------------------------------

    public function test_an_organizer_saves_only_the_platforms_they_filled_in(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($organizer)->patch('/settings/branding', [
            'socials' => ['instagram' => '@boardlah', 'tiktok' => '', 'whatsapp' => '0123456789'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['instagram' => 'https://www.instagram.com/boardlah', 'whatsapp' => 'https://wa.me/60123456789'],
            $organizer->fresh()->organizerProfile->socials,
        );
    }

    public function test_a_wrong_link_is_reported_against_its_platform(): void
    {
        $organizer = $this->organizer();

        $this->actingAs($organizer)->patch('/settings/branding', [
            'socials' => ['instagram' => 'https://www.tiktok.com/@boardlah'],
        ])->assertSessionHasErrors('socials.instagram');
    }

    public function test_a_save_without_the_field_keeps_existing_links(): void
    {
        $organizer = $this->organizer();
        $organizer->organizerProfile()->create(['socials' => ['instagram' => 'https://www.instagram.com/keep']]);

        $this->actingAs($organizer)->patch('/settings/branding', ['bio' => 'Board games'])->assertSessionHasNoErrors();

        $this->assertSame(['instagram' => 'https://www.instagram.com/keep'], $organizer->fresh()->organizerProfile->socials);
    }

    public function test_a_non_organizer_cannot_set_socials(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch('/settings/branding', ['socials' => ['instagram' => '@x']])
            ->assertSessionHasErrors('socials');
    }

    // ---- showing ---------------------------------------------------------------------

    public function test_the_organizer_page_shows_the_logos_and_tells_search_engines(): void
    {
        $organizer = $this->organizer(['slug' => 'boardlah', 'name' => 'BoardLah']);
        $organizer->organizerProfile()->create([
            'status' => 'approved',
            'socials' => ['tiktok' => 'https://www.tiktok.com/@boardlah', 'instagram' => 'https://www.instagram.com/boardlah'],
        ]);
        Event::create([
            'user_id' => $organizer->id, 'title' => 'Night', 'slug' => 'night', 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(),
        ]);

        $response = $this->get('/en-my/o/boardlah/')->assertOk();

        // In platform order (Instagram before TikTok), whatever order they were saved in.
        $response->assertInertia(fn (Assert $p) => $p
            ->where('organizer.socials.0.platform', 'instagram')
            ->where('organizer.socials.1.platform', 'tiktok'));

        $this->assertStringContainsString('https://www.tiktok.com/@boardlah', $response->getContent());
    }

    public function test_a_stored_link_that_no_longer_passes_is_not_shown(): void
    {
        $organizer = $this->organizer(['slug' => 'oldrow']);
        $organizer->organizerProfile()->create(['status' => 'approved', 'socials' => ['instagram' => 'javascript:alert(1)']]);

        $this->assertSame([], SocialLinks::forDisplay($organizer->organizerProfile->socials));
    }

    // ---- category copy ---------------------------------------------------------------

    public function test_category_copy_is_rendered_as_html_not_shown_as_tags(): void
    {
        EventCategory::create(['name' => 'Business', 'slug' => 'business', 'content' => '<h2>Business events</h2><p>Talks &amp; networking.</p><script>alert(1)</script>']);

        $this->get('/en-my/all/business/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('categoryContent.0.content', fn ($html) => str_contains($html, '<h2>Business events</h2>') && ! str_contains($html, '<script')));
    }

    public function test_plain_text_category_copy_keeps_its_paragraphs(): void
    {
        EventCategory::create(['name' => 'Arts', 'slug' => 'arts', 'content' => "First paragraph.\n\nSecond <one>."]);

        $this->get('/en-my/all/arts/')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('categoryContent.0.content', '<p>First paragraph.</p><p>Second &lt;one&gt;.</p>'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WebsiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The organizer website field, and the 404 page.
 *
 * The website is optional, but the `url` rule rejects every way a person
 * actually writes an address, so filling it in correctly was a guessing game
 * and leaving it blank was the only reliable option.
 */
class WebsiteAndErrorPageTest extends TestCase
{
    use RefreshDatabase;

    private function organizerUser(): User
    {
        Role::findOrCreate('organizer', 'web');
        $user = User::factory()->create();
        $user->assignRole('organizer');

        return $user;
    }

    // ---- normalising --------------------------------------------------------

    public function test_an_address_written_the_way_people_write_one_is_accepted(): void
    {
        $this->assertSame('https://instagram.com/3dexpress', WebsiteUrl::normalise('instagram.com/3dexpress'));
        $this->assertSame('https://www.example.com', WebsiteUrl::normalise('www.example.com'));
        $this->assertSame('https://example.com', WebsiteUrl::normalise('  example.com  '));
    }

    public function test_a_complete_url_is_left_alone(): void
    {
        $url = 'https://www.instagram.com/3dexpress?utm_source=ig_web_button_share_sheet&stkn=ZDNlZDc0';

        $this->assertSame($url, WebsiteUrl::normalise($url));
        $this->assertSame('http://example.com/a', WebsiteUrl::normalise('http://example.com/a'));
    }

    public function test_blank_stays_blank_because_the_field_is_optional(): void
    {
        $this->assertNull(WebsiteUrl::normalise(''));
        $this->assertNull(WebsiteUrl::normalise('   '));
        $this->assertNull(WebsiteUrl::normalise(null));
    }

    public function test_a_scheme_that_is_not_a_website_is_refused(): void
    {
        // This value is rendered into an href on a public profile, so anything
        // but http(s) is a stored-XSS vector rather than a website.
        $this->assertNull(WebsiteUrl::normalise('javascript:alert(1)'));
        $this->assertNull(WebsiteUrl::normalise('data:text/html,<script>alert(1)</script>'));
        $this->assertNull(WebsiteUrl::normalise('file:///etc/passwd'));
        // A scheme with nothing behind it is not an address either.
        $this->assertNull(WebsiteUrl::normalise('https://'));
    }

    // ---- saving -------------------------------------------------------------

    public function test_an_organizer_can_save_a_website_without_typing_a_scheme(): void
    {
        $user = $this->organizerUser();

        $this->actingAs($user)
            ->patch(route('branding.update'), ['website' => 'instagram.com/3dexpress'])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://instagram.com/3dexpress', $user->fresh()->organizerProfile->website);
    }

    public function test_an_organizer_can_leave_the_website_blank(): void
    {
        $user = $this->organizerUser();

        $this->actingAs($user)
            ->patch(route('branding.update'), ['website' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->organizerProfile->website);
    }

    public function test_an_organizer_can_clear_a_website_they_had_saved(): void
    {
        $user = $this->organizerUser();
        $user->organizerProfile()->create(['website' => 'https://example.com']);

        $this->actingAs($user)
            ->patch(route('branding.update'), ['website' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->organizerProfile->website);
    }

    public function test_a_dangerous_scheme_never_reaches_the_profile(): void
    {
        $user = $this->organizerUser();

        $this->actingAs($user)
            ->patch(route('branding.update'), ['website' => 'javascript:alert(document.cookie)'])
            ->assertSessionHasNoErrors();

        // Normalised to null rather than stored: it would end up in an href.
        $this->assertNull($user->fresh()->organizerProfile?->website);
    }

    // ---- the 404 page -------------------------------------------------------

    public function test_the_404_hero_does_not_use_the_social_share_card(): void
    {
        // og-default.png has "Find your people. Fill your events." set into it,
        // so using it as the hero printed that headline through the 404 copy.
        $body = $this->get('/en-my/e/nothing-here-at-all/')->assertNotFound()->getContent();

        $this->assertStringNotContainsString('og-default.png', $body);
        $this->assertStringContainsString('ZEr1wBEmpjVFWZkhF9MK7THDTQuv2u2HK0AtxbfZ.jpg', $body);
    }

    public function test_rendering_the_404_page_costs_no_queries(): void
    {
        // An error page that needs the database is an error page that can fail
        // for the same reason the request did — and 404s are the most-served
        // page on any public site, most of them to bots.
        //
        // Asserted on the VIEW rather than through a request: reaching a 404
        // route means first querying for the thing that is missing, so a
        // request-level count would measure the lookup, not the page.
        $queries = 0;

        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $html = view('errors.404', [
            'exception' => new NotFoundHttpException,
        ])->render();

        $this->assertSame(0, $queries, 'The 404 page hit the database.');
        $this->assertStringContainsString('Sorry, this event has left the building.', $html);
    }
}

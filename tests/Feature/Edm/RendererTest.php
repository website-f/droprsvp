<?php

namespace Tests\Feature\Edm;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Edm\EmailHtml;
use App\Support\Edm\Renderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The email itself: what the editor's blocks turn into.
 *
 * This output is what lands in an inbox, so it has to be safe to publish on a
 * public "view in browser" page, readable in clients that ignore <style>, and
 * impossible to send without the unsubscribe footer.
 */
class RendererTest extends TestCase
{
    use RefreshDatabase;

    private function design(array $blocks, array $root = []): array
    {
        return ['root' => ['props' => $root], 'content' => $blocks];
    }

    public function test_every_email_carries_the_footer_whatever_its_blocks(): void
    {
        // No block can remove it: Gmail's bulk rules and PDPA both require it.
        $out = Renderer::render($this->design([]), ['address' => 'Lot 1, Kajang']);

        $this->assertStringContainsString('{{unsubscribe_url}}', $out['html']);
        $this->assertStringContainsString('{{view_url}}', $out['html']);
        $this->assertStringContainsString('Lot 1, Kajang', $out['html']);
        $this->assertStringContainsString('Unsubscribe: {{unsubscribe_url}}', $out['text']);
    }

    public function test_blocks_render_in_order_with_inline_styles(): void
    {
        $out = Renderer::render($this->design([
            ['type' => 'Heading', 'props' => ['text' => 'This weekend', 'level' => 'h1']],
            ['type' => 'Text', 'props' => ['html' => '<p>Three shows in <strong>Kajang</strong>.</p>']],
            ['type' => 'Button', 'props' => ['label' => 'Browse', 'url' => 'https://droprsvp.test/en-my/all/']],
        ]));

        $html = $out['html'];
        $this->assertTrue(strpos($html, 'This weekend') < strpos($html, 'Three shows') && strpos($html, 'Three shows') < strpos($html, 'Browse'));
        // Styles are on the elements: Gmail ignores <style> blocks.
        $this->assertStringContainsString('<p style="margin:0 0 14px 0;">', $html);
        $this->assertStringNotContainsString('<style', $html);

        $this->assertStringContainsString('THIS WEEKEND', $out['text']);
        $this->assertStringContainsString('Browse: https://droprsvp.test/en-my/all/', $out['text']);
    }

    public function test_text_is_allow_listed_not_deny_listed(): void
    {
        // Organizers will write campaign copy, and it is published on a public
        // page. Anything not explicitly allowed goes.
        $dirty = '<p onclick="steal()" class="x" style="position:fixed">Hi</p>'
            .'<script>alert(1)</script>'
            .'<img src=x onerror=alert(1)>'
            .'<a href="javascript:alert(1)">bad</a>'
            .'<iframe src="https://evil.test"></iframe>'
            .'<div><span>kept text</span></div>';

        $clean = EmailHtml::clean($dirty);

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringNotContainsString('position:fixed', $clean);
        $this->assertStringNotContainsString('class=', $clean);
        // Unknown tags are unwrapped, not deleted with their words.
        $this->assertStringContainsString('kept text', $clean);
        $this->assertStringContainsString('href="#"', $clean);
    }

    public function test_a_site_relative_link_becomes_absolute(): void
    {
        // An inbox has no "current site" to resolve "/en-my/all/" against.
        $clean = EmailHtml::clean('<a href="/en-my/all/">all events</a>');

        $this->assertStringContainsString('href="'.url('/en-my/all/').'"', $clean);
    }

    public function test_non_ascii_text_survives(): void
    {
        $clean = EmailHtml::clean('<p>Jom datang — café & 🎉</p>');

        $this->assertStringContainsString('Jom datang — café &amp; 🎉', $clean);
    }

    public function test_a_button_without_a_usable_link_is_left_out(): void
    {
        $out = Renderer::render($this->design([
            ['type' => 'Button', 'props' => ['label' => 'Click', 'url' => 'javascript:alert(1)']],
        ]));

        $this->assertStringNotContainsString('Click', $out['html']);
    }

    public function test_an_unknown_block_is_skipped_rather_than_failing_the_campaign(): void
    {
        $out = Renderer::render($this->design([
            ['type' => 'SomethingFromANewerEditor', 'props' => ['x' => 1]],
            ['type' => 'Heading', 'props' => ['text' => 'Still here']],
        ]));

        $this->assertStringContainsString('Still here', $out['html']);
    }

    public function test_heading_text_is_escaped(): void
    {
        $out = Renderer::render($this->design([['type' => 'Heading', 'props' => ['text' => '<b>Bold</b> & co']]]));

        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; &amp; co', $out['html']);
    }

    public function test_an_invalid_colour_falls_back_rather_than_injecting_css(): void
    {
        $out = Renderer::render($this->design(
            [['type' => 'Button', 'props' => ['label' => 'Go', 'url' => 'https://x.test', 'color' => 'red;background:url(evil)']]],
        ));

        $this->assertStringNotContainsString('url(evil)', $out['html']);
        $this->assertStringContainsString(Renderer::DEFAULT_BRAND, $out['html']);
    }

    public function test_an_event_card_fills_itself_from_the_live_event(): void
    {
        $event = Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Blood on the Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'venue_name' => 'HOL Cafe', 'city' => 'Kajang',
            // 15:00 in Kuala Lumpur, stored as UTC.
            'starts_at' => '2026-10-10 07:00:00',
            'cover_image' => 'https://img.test/cover.jpg',
        ]);
        TicketType::create(['event_id' => $event->id, 'name' => 'A', 'kind' => 'paid', 'price' => 20, 'currency' => 'MYR', 'quantity' => 10, 'is_active' => true]);
        TicketType::create(['event_id' => $event->id, 'name' => 'B', 'kind' => 'paid', 'price' => 35, 'currency' => 'MYR', 'quantity' => 10, 'is_active' => true]);

        $html = Renderer::render($this->design([['type' => 'EventCard', 'props' => ['event' => 'clocktower']]]))['html'];

        $this->assertStringContainsString('Blood on the Clocktower', $html);
        // In the event's own timezone, not the server's.
        $this->assertStringContainsString('Sat, 10 Oct 2026 · 3:00pm', $html);
        $this->assertStringContainsString('HOL Cafe, Kajang', $html);
        $this->assertStringContainsString('From RM 20.00', $html);
        $this->assertStringContainsString('https://img.test/cover.jpg', $html);
        $this->assertStringContainsString('/en-my/e/clocktower/', $html);
    }

    public function test_an_event_card_for_an_unpublished_event_is_left_out(): void
    {
        // Advertising something nobody can buy is worse than a shorter email.
        Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Draft Night', 'slug' => 'draft-night',
            'status' => 'draft', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $html = Renderer::render($this->design([['type' => 'EventCard', 'props' => ['event' => 'draft-night']]]))['html'];

        $this->assertStringNotContainsString('Draft Night', $html);
    }
}

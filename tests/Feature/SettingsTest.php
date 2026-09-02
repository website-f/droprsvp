<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_settings_page_renders_grouped_values(): void
    {
        $this->actingAs($this->superadmin())->get(route('admin.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/settings')->has('settings.fee_percent')->has('settings.tax_percent'));
    }

    public function test_saving_settings_persists_fees_and_tax(): void
    {
        $this->actingAs($this->superadmin())->post(route('admin.settings.save'), [
            'fee_type' => 'percent', 'fee_percent' => 7, 'fee_fixed' => 0, 'boost_price' => 60, 'boost_days' => 10, 'premium_price' => 29, 'premium_days' => 30,
            'tax_percent' => 8, 'tax_label' => 'SST', 'tax_inclusive' => true, 'support_email' => 'help@droprsvp.test',
        ])->assertRedirect();

        $this->assertSame('7', Setting::get('platform_fee_percent'));
        $this->assertSame('8', Setting::get('tax_percent'));
        $this->assertSame('1', Setting::get('tax_inclusive'));
        $this->assertSame('help@droprsvp.test', Setting::get('support_email'));
    }

    public function test_tax_is_applied_to_checkout_when_configured(): void
    {
        Setting::put('tax_percent', 10);
        $host = User::factory()->create();
        $event = Event::create(['user_id' => $host->id, 'title' => 'Taxed', 'slug' => 'taxed', 'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        $tt = TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'kind' => 'paid', 'price' => 100, 'min_per_order' => 1, 'max_per_order' => 4, 'is_active' => true, 'sort_order' => 0]);

        $this->post("/e/{$event->slug}/checkout", ['items' => [['ticket_type_id' => $tt->id, 'quantity' => 1]]])->assertRedirect();

        $order = Order::first();
        $this->assertEquals(100, (float) $order->subtotal);
        $this->assertEquals(10, (float) $order->tax);
        $this->assertEquals(110, (float) $order->total);
    }

    public function test_public_page_receives_posts_for_the_posts_widget(): void
    {
        CmsPost::create(['title' => 'Hello World', 'slug' => 'hello-world', 'body' => '<p>Body</p>', 'status' => 'published', 'published_at' => now()]);
        CmsPage::create(['title' => 'Blog page', 'slug' => 'blog-page', 'status' => 'published', 'published_at' => now(),
            'puck_data' => ['root' => [], 'content' => [['type' => 'Posts', 'props' => ['id' => 'p', 'heading' => 'Posts', 'limit' => 6]]]]]);

        $this->get('/blog-page')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('public/page')
            ->where('page.posts.0.title', 'Hello World'));
    }
}

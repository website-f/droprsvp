<?php

namespace Tests\Feature;

use App\Mail\ContactMessageMail;
use App\Models\ContactMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_contact_page_renders(): void
    {
        $this->get('/contact')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/contact')->has('categories', 3));
    }

    public function test_submitting_stores_the_message_and_emails_support(): void
    {
        Mail::fake();
        Setting::put('support_email', 'ops@droprsvp.test');

        $this->post('/contact', [
            'name' => 'Jane', 'email' => 'jane@example.com', 'phone' => '0123456789',
            'category' => 'sales', 'message' => 'Hello, we would like a demo.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_messages', ['email' => 'jane@example.com', 'category' => 'sales']);
        Mail::assertSent(ContactMessageMail::class, fn ($m) => $m->hasTo('ops@droprsvp.test'));
    }

    public function test_contact_requires_all_fields(): void
    {
        $this->post('/contact', [])->assertSessionHasErrors(['name', 'email', 'phone', 'category', 'message']);
    }

    public function test_category_must_be_one_of_the_allowed(): void
    {
        $this->post('/contact', ['name' => 'A', 'email' => 'a@b.com', 'phone' => '1', 'category' => 'spam', 'message' => 'hi'])
            ->assertSessionHasErrors('category');
    }

    public function test_superadmin_sees_the_inbox_and_can_toggle_handled(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $msg = ContactMessage::create(['name' => 'Zed', 'email' => 'z@z.com', 'phone' => '1', 'category' => 'support', 'message' => 'help']);

        $this->actingAs($admin)->get('/admin/contact')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/contact/index')->has('messages.data', 1)->where('unhandled', 1));

        $this->actingAs($admin)->post("/admin/contact/{$msg->id}/toggle")->assertRedirect();
        $this->assertNotNull($msg->fresh()->handled_at);
    }

    public function test_non_superadmin_cannot_view_the_inbox(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/contact')->assertForbidden();
    }
}

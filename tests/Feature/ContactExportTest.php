<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_superadmin_can_export_contact_messages_as_csv(): void
    {
        ContactMessage::create(['name' => 'Aisha', 'email' => 'aisha@example.test', 'category' => 'support', 'message' => 'Need help']);

        $res = $this->actingAs($this->admin())->get('/admin/contact/export');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));

        $csv = $res->streamedContent();
        $this->assertStringContainsString('Name,Email', $csv);
        $this->assertStringContainsString('Aisha', $csv);
        $this->assertStringContainsString('aisha@example.test', $csv);
    }

    public function test_the_export_respects_the_status_filter(): void
    {
        ContactMessage::create(['name' => 'Open One', 'email' => 'o@example.test', 'category' => 'support', 'message' => 'x']);
        ContactMessage::create(['name' => 'Done One', 'email' => 'd@example.test', 'category' => 'support', 'message' => 'y', 'handled_at' => now()]);

        $csv = $this->actingAs($this->admin())->get('/admin/contact/export?status=open')->streamedContent();

        $this->assertStringContainsString('Open One', $csv);
        $this->assertStringNotContainsString('Done One', $csv);
    }

    public function test_a_non_admin_cannot_export(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/contact/export')->assertForbidden();
    }
}

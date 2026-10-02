<?php

namespace Tests\Feature\Edm;

use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** EDM → Templates, and the EDM workspace pages around it. */
class TemplateLibraryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('superadmin', 'web');
        $this->admin = User::factory()->create(['name' => 'Aisyah Admin']);
        $this->admin->assignRole('superadmin');
    }

    private function design(string $text = 'Hello {{first_name}}'): array
    {
        return ['root' => ['props' => []], 'content' => [['type' => 'Text', 'props' => ['id' => 'T', 'html' => "<p>{$text}</p>"]]]];
    }

    public function test_the_library_lists_saved_templates_and_starters(): void
    {
        EmailTemplate::create(['name' => 'Monthly', 'design' => $this->design()]);

        $this->actingAs($this->admin)->get('/admin/edm/templates')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/edm/templates/index')
                ->where('templates.0.name', 'Monthly')
                ->has('starters', 4));
    }

    public function test_customising_a_starter_copies_it_and_opens_the_builder(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/edm/templates', ['name' => 'My spotlight', 'starter' => 'spotlight']);

        $t = EmailTemplate::firstOrFail();
        $response->assertRedirect("/admin/edm/templates/{$t->id}/editor");
        $this->assertSame('EventCard', $t->design['content'][2]['type']);
        $this->assertStringContainsString('{{first_name}}', (string) $t->subject);

        $this->actingAs($this->admin)->get("/admin/edm/templates/{$t->id}/editor")->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/edm/campaigns/editor')
                ->where('saveUrl', "/admin/edm/templates/{$t->id}/design")
                ->where('kind', 'template'));
    }

    public function test_the_builder_saves_into_the_template(): void
    {
        $t = EmailTemplate::create(['name' => 'X', 'design' => $this->design()]);

        $this->actingAs($this->admin)->postJson("/admin/edm/templates/{$t->id}/design", ['design' => $this->design('Changed')])->assertOk();

        $this->assertStringContainsString('Changed', json_encode($t->fresh()->design));
    }

    public function test_a_campaign_saved_as_a_template_and_a_campaign_started_from_one(): void
    {
        $campaign = EmailCampaign::create(['name' => 'Oct', 'subject' => 'Hi {{first_name}}', 'preheader' => 'P', 'design' => $this->design('From the campaign')]);

        $this->actingAs($this->admin)->post('/admin/edm/templates', ['name' => 'Reusable', 'campaign_id' => $campaign->id])->assertRedirect();
        $t = EmailTemplate::where('name', 'Reusable')->firstOrFail();
        $this->assertSame('Hi {{first_name}}', $t->subject);

        $this->actingAs($this->admin)->post('/admin/edm/templates/use', ['template_id' => $t->id, 'name' => 'November'])->assertRedirect();
        $new = EmailCampaign::where('name', 'November')->firstOrFail();
        $this->assertStringContainsString('From the campaign', json_encode($new->design));
        $this->assertSame('draft', $new->status);

        // The copy is independent: editing the template later leaves it alone.
        $t->update(['design' => $this->design('Template changed')]);
        $this->assertStringNotContainsString('Template changed', json_encode($new->fresh()->design));
    }

    public function test_a_campaign_from_a_starter(): void
    {
        $this->actingAs($this->admin)->post('/admin/edm/templates/use', ['starter' => 'roundup', 'name' => 'This week'])->assertRedirect();

        $this->assertSame(3, collect(EmailCampaign::firstOrFail()->design['content'])->where('type', 'EventCard')->count());
    }

    public function test_use_without_a_name_takes_the_templates(): void
    {
        // The "Use" button on a card posts no name at all.
        $t = EmailTemplate::create(['name' => 'Monthly letter', 'design' => $this->design()]);

        $this->actingAs($this->admin)->post('/admin/edm/templates/use', ['template_id' => $t->id])->assertRedirect();
        $this->actingAs($this->admin)->post('/admin/edm/templates/use', ['starter' => 'spotlight'])->assertRedirect();

        $this->assertSame(['Monthly letter', 'Event spotlight'], EmailCampaign::orderBy('id')->pluck('name')->all());
    }

    public function test_previews_render_as_email(): void
    {
        $t = EmailTemplate::create(['name' => 'X', 'design' => $this->design()]);

        $this->actingAs($this->admin)->get("/admin/edm/templates/{$t->id}/preview")->assertOk()->assertSee('Hello Aisyah', false);
        $this->actingAs($this->admin)->get('/admin/edm/templates/starters/letter/preview')->assertOk()->assertSee('Hi Aisyah', false);
        $this->actingAs($this->admin)->get('/admin/edm/templates/starters/nope/preview')->assertNotFound();
    }

    public function test_delete_and_duplicate(): void
    {
        $t = EmailTemplate::create(['name' => 'X', 'design' => $this->design()]);

        $this->actingAs($this->admin)->post("/admin/edm/templates/{$t->id}/duplicate")->assertRedirect();
        $this->assertSame(2, EmailTemplate::count());

        $this->actingAs($this->admin)->delete("/admin/edm/templates/{$t->id}")->assertRedirect('/admin/edm/templates');
        $this->assertSame(1, EmailTemplate::count());
    }

    public function test_an_organizers_template_is_not_reachable_from_the_platform_library(): void
    {
        $org = $this->organizer();
        $t = EmailTemplate::create(['name' => 'Theirs', 'organizer_id' => $org->id, 'design' => $this->design()]);

        $this->actingAs($this->admin)->get("/admin/edm/templates/{$t->id}/editor")->assertNotFound();
    }

    public function test_the_workspace_pages_render(): void
    {
        foreach (['/admin/edm' => 'admin/edm/overview', '/admin/edm/campaigns' => 'admin/edm/campaigns/index', '/admin/edm/subscribers?status=suppressed' => 'admin/edm/subscribers', '/admin/edm/settings' => 'admin/edm/settings'] as $url => $component) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn ($p) => $p->component($component));
        }

        $this->actingAs($this->admin)->get('/admin/edm/campaigns?status=draft&q=oct')->assertOk()
            ->assertInertia(fn ($p) => $p->where('filters.status', 'draft')->has('starters', 4));
    }

    public function test_staff_need_the_edm_section(): void
    {
        Role::findOrCreate('staff', 'web');
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/admin/edm')->assertForbidden();
        $this->actingAs($staff)->get('/admin/edm/templates')->assertForbidden();
        $this->actingAs($staff)->get('/admin/edm/deliverability')->assertForbidden();
    }
}

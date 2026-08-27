<?php

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeoAndMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lists_published_content_only(): void
    {
        $host = User::factory()->create();
        Event::create(['user_id' => $host->id, 'title' => 'Pub Ev', 'slug' => 'pub-ev', 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        Event::create(['user_id' => $host->id, 'title' => 'Draft Ev', 'slug' => 'draft-ev', 'status' => 'draft', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
        CmsPage::create(['title' => 'About', 'slug' => 'about', 'status' => 'published', 'published_at' => now()]);
        CmsPost::create(['title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'published_at' => now()]);

        $res = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', $res->headers->get('Content-Type'));
        $res->assertSee(url('/e/pub-ev'), false)
            ->assertSee(url('/about'), false)
            ->assertSee(url('/blog/hello'), false)
            ->assertDontSee(url('/e/draft-ev'), false);
    }

    public function test_robots_points_to_the_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:', false)->assertSee('/sitemap.xml', false);
    }

    public function test_superadmin_can_upload_an_image(): void
    {
        Storage::fake('public');
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->post(route('admin.cms.media.store'), ['file' => UploadedFile::fake()->image('cover.jpg', 800, 450)])
            ->assertOk()
            ->assertJsonStructure(['url']);

        $this->assertCount(1, Storage::disk('public')->allFiles('cms'));
    }

    public function test_non_superadmin_cannot_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('admin.cms.media.store'), ['file' => UploadedFile::fake()->image('x.jpg')])
            ->assertForbidden();
    }
}

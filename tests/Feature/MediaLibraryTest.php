<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Media Library: every upload recorded, so images can be found and
 * reused from the builders and the HTML editors instead of uploaded again.
 */
class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create(['name' => 'Aisyah Admin']);
        $user->assignRole('superadmin');

        return $user;
    }

    private function upload(User $user, string $name = 'Board game night.jpg', int $w = 1200, int $h = 800): Media
    {
        $this->actingAs($user)
            ->post(route('uploads'), ['file' => UploadedFile::fake()->image($name, $w, $h)])
            ->assertOk()
            ->assertJsonStructure(['url', 'media' => ['id', 'url', 'thumb', 'name']]);

        return Media::latest('id')->firstOrFail();
    }

    // ---- recording uploads ------------------------------------------------------

    public function test_every_upload_lands_in_the_library(): void
    {
        $organizer = $this->organizer();
        $media = $this->upload($organizer);

        $this->assertSame($organizer->id, $media->user_id);
        $this->assertSame('Board game night.jpg', $media->original_name);
        $this->assertSame([1200, 800], [$media->width, $media->height]);
        $this->assertGreaterThan(0, $media->size);
        $this->assertStringStartsWith('cms/', $media->path);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_the_upload_response_still_leads_with_the_url(): void
    {
        // Every existing uploader reads only `url`; that must not move.
        $response = $this->actingAs($this->organizer())
            ->post(route('uploads'), ['file' => UploadedFile::fake()->image('x.jpg', 100, 100)])
            ->assertOk();

        $this->assertSame(Media::firstOrFail()->url(), $response->json('url'));
    }

    public function test_a_strange_filename_is_tidied_not_trusted(): void
    {
        $media = $this->upload($this->organizer(), '../../etc/<script>evil</script>.png');

        $this->assertStringNotContainsString('/', $media->original_name);
        $this->assertStringNotContainsString('<', $media->original_name);
    }

    // ---- who sees what -------------------------------------------------------------

    public function test_organizers_see_only_their_own_images_and_admins_see_all(): void
    {
        $alice = $this->organizer();
        $bob = $this->organizer();
        $this->upload($alice, 'alice.jpg');
        $this->upload($bob, 'bob.jpg');

        $this->actingAs($alice)->getJson(route('media.index'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'alice.jpg');

        $this->actingAs($this->admin())->getJson(route('media.index'))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 2);
    }

    public function test_guests_get_nothing(): void
    {
        $this->getJson(route('media.index'))->assertUnauthorized();
    }

    public function test_search_finds_by_name_and_alt_text(): void
    {
        $organizer = $this->organizer();
        $this->upload($organizer, 'clocktower.jpg');
        $this->upload($organizer, 'gala.jpg')->update(['alt' => 'Ballroom tables']);

        $this->actingAs($organizer)->getJson(route('media.index', ['q' => 'clock']))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'clocktower.jpg');
        $this->actingAs($organizer)->getJson(route('media.index', ['q' => 'ballroom']))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'gala.jpg');
    }

    public function test_the_month_filter_and_its_options(): void
    {
        $organizer = $this->organizer();
        $this->travelTo('2026-09-15 10:00:00');
        $this->upload($organizer, 'september.jpg');
        $this->travelTo('2026-10-20 10:00:00');
        $this->upload($organizer, 'october.jpg');

        $this->actingAs($organizer)->getJson(route('media.index'))
            ->assertJsonPath('months', ['2026-10' => 'October 2026', '2026-09' => 'September 2026']);

        $this->actingAs($organizer)->getJson(route('media.index', ['month' => '2026-09']))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'september.jpg');
    }

    public function test_it_pages_forty_at_a_time(): void
    {
        $organizer = $this->organizer();
        foreach (range(1, 41) as $i) {
            Media::create(['user_id' => $organizer->id, 'path' => "cms/{$i}.jpg", 'original_name' => "{$i}.jpg"]);
        }

        $this->actingAs($organizer)->getJson(route('media.index'))
            ->assertJsonCount(40, 'data')->assertJsonPath('next', 2)->assertJsonPath('total', 41);
        $this->actingAs($organizer)->getJson(route('media.index', ['page' => 2]))
            ->assertJsonCount(1, 'data')->assertJsonPath('next', null)->assertJsonPath('months', null);
    }

    // ---- editing and deleting -------------------------------------------------------

    public function test_the_owner_can_rename_and_describe_an_image(): void
    {
        $organizer = $this->organizer();
        $media = $this->upload($organizer);

        $this->actingAs($organizer)->patchJson(route('media.update', $media), ['name' => 'Poster', 'alt' => 'Players around a table'])
            ->assertOk()->assertJsonPath('media.alt', 'Players around a table');

        $this->assertSame('Poster', $media->fresh()->original_name);
    }

    public function test_someone_elses_image_cannot_be_changed_or_deleted(): void
    {
        $media = $this->upload($this->organizer());
        $other = $this->organizer();

        $this->actingAs($other)->patchJson(route('media.update', $media), ['alt' => 'x'])->assertForbidden();
        $this->actingAs($other)->deleteJson(route('media.destroy', $media))->assertForbidden();
        $this->assertNotNull($media->fresh());
    }

    public function test_deleting_removes_the_file_its_thumbnail_and_the_entry(): void
    {
        $organizer = $this->organizer();
        $media = $this->upload($organizer);
        $thumb = 'cms/thumbs/'.basename($media->path);
        Storage::disk('public')->assertExists($thumb);

        $this->actingAs($organizer)->deleteJson(route('media.destroy', $media))->assertOk();

        Storage::disk('public')->assertMissing($media->path);
        Storage::disk('public')->assertMissing($thumb);
        $this->assertNull($media->fresh());
    }

    public function test_an_admin_can_tidy_anyones_image(): void
    {
        $media = $this->upload($this->organizer());

        $this->actingAs($this->admin())->deleteJson(route('media.destroy', $media))->assertOk();
    }

    // ---- the page and the import ------------------------------------------------------

    public function test_the_media_page_is_for_admins(): void
    {
        $this->actingAs($this->admin())->get(route('admin.cms.media'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/cms/media/index'));

        $this->actingAs($this->organizer())->get(route('admin.cms.media'))->assertForbidden();
    }

    public function test_images_from_before_the_library_are_imported_once(): void
    {
        $disk = Storage::disk('public');
        $disk->put('cms/old.jpg', UploadedFile::fake()->image('old.jpg', 300, 200)->getContent());
        $disk->put('cms/thumbs/old.jpg', 'thumb');
        $disk->put('cms/readme.txt', 'not an image');

        $this->artisan('media:import')->assertSuccessful();
        $this->artisan('media:import')->assertSuccessful();

        $this->assertSame(1, Media::count());
        $media = Media::firstOrFail();
        $this->assertSame('cms/old.jpg', $media->path);
        $this->assertNull($media->user_id, 'nobody recorded who uploaded it');
        $this->assertSame([300, 200], [$media->width, $media->height]);

        // Owned by nobody: an admin sees it, an organizer does not.
        $this->actingAs($this->admin())->getJson(route('media.index'))->assertJsonCount(1, 'data');
        $this->actingAs($this->organizer())->getJson(route('media.index'))->assertJsonCount(0, 'data');
    }
}

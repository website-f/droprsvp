<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploads were stored exactly as they arrived, so a 4000px phone photo was
 * being served into a 300px gallery thumbnail. These assert the downscale
 * actually happens — and, just as importantly, that it declines rather than
 * damages anything it cannot improve.
 */
class ImageOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! ImageOptimizer::available()) {
            $this->markTestSkipped('GD is not available on this PHP build.');
        }

        $this->dir = sys_get_temp_dir().'/img-opt-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    /** A photographic-looking JPEG, so it does not compress to nothing. */
    private function jpeg(int $width, int $height): string
    {
        $path = $this->dir.'/'.uniqid().'.jpg';
        $image = imagecreatetruecolor($width, $height);

        // Noise: a flat colour would compress to a few bytes at any size and
        // would prove nothing about the resize.
        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $colour = imagecolorallocate($image, ($x * 7) % 255, ($y * 13) % 255, ($x + $y) % 255);
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, $colour);
            }
        }

        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return $path;
    }

    public function test_an_oversized_photo_is_scaled_down_to_the_maximum_edge(): void
    {
        $path = $this->jpeg(3000, 1200);
        $before = filesize($path);

        $this->assertTrue(ImageOptimizer::optimise($path), 'Expected the image to be rewritten.');

        [$width, $height] = getimagesize($path);

        $this->assertSame(ImageOptimizer::MAX_EDGE, $width);
        // The aspect ratio is preserved — a squashed photo is worse than a big one.
        $this->assertSame(800, $height);
        $this->assertLessThan($before, filesize($path));
    }

    public function test_the_file_is_still_a_readable_image_afterwards(): void
    {
        $path = $this->jpeg(2600, 700);
        ImageOptimizer::optimise($path);

        $info = getimagesize($path);

        $this->assertNotFalse($info, 'The optimised file is no longer a readable image.');
        $this->assertSame(IMAGETYPE_JPEG, $info[2], 'The format changed, which would break the stored URL.');
    }

    public function test_a_small_image_is_left_exactly_as_it_was(): void
    {
        $path = $this->jpeg(400, 300);
        $before = file_get_contents($path);

        $this->assertFalse(ImageOptimizer::optimise($path));
        // Byte-for-byte: re-encoding something already small would only lose
        // quality for no saving.
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_a_file_that_is_not_an_image_is_left_alone(): void
    {
        $path = $this->dir.'/notes.jpg';
        file_put_contents($path, 'this is not an image');

        $this->assertFalse(ImageOptimizer::optimise($path));
        $this->assertSame('this is not an image', file_get_contents($path));
    }

    public function test_an_animated_gif_is_never_touched(): void
    {
        // Re-encoding through GD keeps the first frame only, which is not an
        // optimisation — it is destroying the image.
        $path = $this->dir.'/anim.gif';
        $image = imagecreatetruecolor(2400, 400);
        imagegif($image, $path);
        imagedestroy($image);

        $before = file_get_contents($path);

        $this->assertFalse(ImageOptimizer::optimise($path));
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_a_missing_file_does_not_throw(): void
    {
        $this->assertFalse(ImageOptimizer::optimise($this->dir.'/nope.jpg'));
    }

    public function test_transparency_survives_a_resize(): void
    {
        $path = $this->dir.'/alpha.png';
        $image = imagecreatetruecolor(2400, 600);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        // Some opaque content, so the file is big enough to be worth rewriting.
        imagefilledrectangle($image, 0, 0, 1200, 600, imagecolorallocate($image, 200, 30, 30));
        imagepng($image, $path);
        imagedestroy($image);

        ImageOptimizer::optimise($path);

        $result = imagecreatefrompng($path);
        $this->assertNotFalse($result);

        // A transparent corner must not have become black.
        $corner = imagecolorsforindex($result, imagecolorat($result, imagesx($result) - 2, imagesy($result) - 2));
        imagedestroy($result);

        $this->assertGreaterThan(100, $corner['alpha'], 'Transparency was flattened to black.');
    }

    public function test_an_upload_through_the_endpoint_is_optimised(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $source = $this->jpeg(3000, 1200);

        $response = $this->actingAs($user)->post(route('uploads'), [
            'file' => new UploadedFile($source, 'photo.jpg', 'image/jpeg', null, true),
        ]);

        $response->assertOk();

        // Two files now: the optimised original, and the grid thumbnail that
        // galleries load instead of it.
        $stored = Storage::disk('public')->allFiles('cms');
        $full = collect($stored)->reject(fn ($path) => str_contains($path, '/thumbs/'))->values();
        $thumbs = collect($stored)->filter(fn ($path) => str_contains($path, '/thumbs/'))->values();

        $this->assertCount(1, $full);
        $this->assertCount(1, $thumbs, 'The upload should also produce a thumbnail.');

        [$width] = getimagesize(Storage::disk('public')->path($full[0]));
        $this->assertSame(ImageOptimizer::MAX_EDGE, $width);

        // The thumbnail is the point: it must actually be small.
        [$thumbWidth] = getimagesize(Storage::disk('public')->path($thumbs[0]));
        $this->assertSame(ImageOptimizer::THUMB_EDGE, $thumbWidth);
        $this->assertSame(basename($full[0]), basename($thumbs[0]), 'The thumbnail keeps the original filename.');
    }
}

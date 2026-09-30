<?php

namespace App\Support;

/**
 * Downscale and re-encode an uploaded image in place.
 *
 * Uploads were stored exactly as they arrived. A phone camera produces a
 * 4000x3000 JPEG of three or four megabytes, and that same file was then served
 * into a 300px gallery thumbnail — which is why the galleries crawl. The pixels
 * beyond the largest size we ever display are pure transfer cost.
 *
 * Everything here is BEST EFFORT. GD is not guaranteed on shared hosting, a
 * malformed file can fail mid-decode, and a huge image can exhaust the memory
 * limit. In every one of those cases the original file is left exactly as it
 * was and the upload still succeeds: a large image is a slow page, but a failed
 * upload is a broken feature.
 */
class ImageOptimizer
{
    /** Longest edge we keep. Comfortably above any slot we render into. */
    public const MAX_EDGE = 2000;

    /** JPEG/WEBP quality. 82 is the point where further loss starts to show. */
    private const QUALITY = 82;

    /**
     * Optimise the file at $path in place.
     *
     * @return bool True when the file was rewritten smaller.
     */
    public static function optimise(string $path, int $maxEdge = self::MAX_EDGE): bool
    {
        if (! self::available() || ! is_file($path)) {
            return false;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return false;
        }

        [$width, $height, $type] = $info;

        // Animated GIFs would be flattened to a single frame, which is not an
        // optimisation, it is breaking the image.
        if ($type === IMAGETYPE_GIF) {
            return false;
        }

        $longest = max($width, $height);
        $scale = $longest > $maxEdge ? $maxEdge / $longest : 1.0;

        // Already small enough AND already cheap to send: leave it alone rather
        // than re-encoding, which would only lose quality for nothing.
        if ($scale === 1.0 && filesize($path) < 400 * 1024) {
            return false;
        }

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // Give ourselves room for the two bitmaps, then put the limit back.
        // Shared hosts often cap PHP at 128M, which a single phone photo can
        // exhaust — and without this the guard below would simply decline every
        // large image, which is precisely the case worth optimising.
        $restoreLimit = self::raiseMemoryLimit($width, $height, $targetWidth, $targetHeight);

        try {
            if (! self::hasHeadroom($width, $height, $targetWidth, $targetHeight)) {
                return false;
            }

            return self::resize($path, $type, $width, $height, $targetWidth, $targetHeight);
        } finally {
            if ($restoreLimit !== null) {
                @ini_set('memory_limit', $restoreLimit);
            }
        }
    }

    private static function resize(string $path, int $type, int $width, int $height, int $targetWidth, int $targetHeight): bool
    {
        $source = self::read($path, $type);

        if (! $source) {
            return false;
        }

        try {
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

            // PNG and WEBP can carry transparency, and the default black canvas
            // would turn every transparent pixel into a black one.
            if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            // Write beside the original and swap, so a failure part-way through
            // cannot leave a truncated file where a valid one used to be.
            $temp = $path.'.opt';
            $written = self::write($canvas, $temp, $type);

            if (! $written || ! is_file($temp)) {
                @unlink($temp);

                return false;
            }

            // Only keep the result if it is actually smaller. Re-encoding an
            // already-optimised image can grow it.
            if (filesize($temp) >= filesize($path)) {
                @unlink($temp);

                return false;
            }

            return @rename($temp, $path);
        } catch (\Throwable) {
            @unlink($path.'.opt');

            return false;
        } finally {
            if (isset($canvas) && $canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }

            imagedestroy($source);
        }
    }

    /** Is GD present with the codecs we need? */
    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('getimagesize');
    }

    /**
     * Will decoding this fit in the memory limit?
     *
     * GD holds the whole bitmap uncompressed at roughly 4 bytes per pixel, and
     * needs it twice over (source plus canvas). Running out mid-decode is a
     * fatal error, not an exception, so it cannot be caught — the only safe
     * move is to not start.
     */
    private static function hasHeadroom(int $width, int $height, int $targetWidth, int $targetHeight): bool
    {
        $limit = self::memoryLimitBytes();

        if ($limit <= 0) {
            return true; // unlimited
        }

        return (self::bitmapBytes($width, $height, $targetWidth, $targetHeight) + memory_get_usage(true)) < ($limit * 0.8);
    }

    /**
     * Roughly what GD will hold: the source bitmap plus the smaller target one.
     *
     * This used to charge for two bitmaps at SOURCE size, which over-stated the
     * cost by a wide margin on exactly the images worth shrinking — a 4x
     * downscale needs 1.06 source-bitmaps, not 2.
     */
    private static function bitmapBytes(int $width, int $height, int $targetWidth, int $targetHeight): int
    {
        return ($width * $height + $targetWidth * $targetHeight) * 4;
    }

    /**
     * Temporarily raise memory_limit if the current one is too small, returning
     * the old value to restore (or null if nothing was changed).
     *
     * Hosts may forbid this; ini_set then fails and we carry on with whatever
     * we have, which the headroom check will catch.
     */
    private static function raiseMemoryLimit(int $width, int $height, int $targetWidth, int $targetHeight): ?string
    {
        $current = self::memoryLimitBytes();

        if ($current <= 0) {
            return null; // already unlimited
        }

        $wanted = (int) ((self::bitmapBytes($width, $height, $targetWidth, $targetHeight) + memory_get_usage(true)) * 1.5);

        if ($wanted <= $current) {
            return null;
        }

        // Never hand over an unbounded limit — a corrupt header claiming
        // enormous dimensions should not be able to take the whole box down.
        $wanted = min($wanted, 512 * 1024 * 1024);
        $previous = (string) ini_get('memory_limit');

        return @ini_set('memory_limit', (int) ceil($wanted / (1024 * 1024)).'M') === false ? null : $previous;
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function read(string $path, int $type): ?\GdImage
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }

    private static function write(\GdImage $image, string $path, int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagejpeg') && @imagejpeg($image, $path, self::QUALITY),
            IMAGETYPE_PNG => function_exists('imagepng') && @imagepng($image, $path, 6),
            IMAGETYPE_WEBP => function_exists('imagewebp') && @imagewebp($image, $path, self::QUALITY),
            default => false,
        };
    }
}

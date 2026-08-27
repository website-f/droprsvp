<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Tiny wrapper over bacon/bacon-qr-code (already a transitive dependency) that
 * renders a QR code as an inline SVG string — pure PHP, no GD/Imagick needed.
 */
class Qr
{
    public static function svg(string $data, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));

        return $writer->writeString($data);
    }
}

<?php

namespace App\Support;

use BaconQrCode\Renderer\GDLibRenderer;
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
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($data);
    }

    /**
     * The same code as a PNG, for email: Gmail and Outlook do not render
     * inline SVG. Null when GD is not installed — the email then links to the
     * pass instead of showing the code.
     */
    public static function png(string $data, int $size = 240): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            return (new Writer(new GDLibRenderer($size, 2)))->writeString($data);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}

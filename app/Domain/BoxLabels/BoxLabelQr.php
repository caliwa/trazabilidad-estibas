<?php

namespace App\Domain\BoxLabels;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The same QR the Zebra prints, as SVG for the on-screen preview. Error
 * correction M and a four-module quiet zone, as the standard asks.
 */
final class BoxLabelQr
{
    public static function svg(string $payload, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd));

        return $writer->writeString($payload, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::M());
    }
}

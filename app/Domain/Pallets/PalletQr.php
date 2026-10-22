<?php

namespace App\Domain\Pallets;

use App\Models\Pallet;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * What the pallet QR carries: a version prefix and the opaque token, and
 * nothing else. No id, no code, no quantities. Reading it only tells the
 * server which pallet to look up; every rule is checked against the
 * database after that.
 */
final class PalletQr
{
    public const PREFIX = 'TRZ1:';

    public static function payload(Pallet $pallet): string
    {
        return self::PREFIX.$pallet->qr_token;
    }

    /**
     * The token inside a scanned value, or null when it is not one of ours.
     * Readers that act as keyboards may add spaces or a line break, type the
     * ":" as another symbol when the keyboard layout differs (Ñ, ;, >) or send
     * capitals with caps lock on: "TRZ1", any one separator and 32 hex digits.
     */
    public static function tokenFrom(string $scanned): ?string
    {
        if (preg_match('/^\s*TRZ1\X([0-9a-f]{32})\s*$/iu', $scanned, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    public static function svg(Pallet $pallet, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString(self::payload($pallet));
    }
}

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
     * Readers that act as keyboards may add spaces or a line break.
     */
    public static function tokenFrom(string $scanned): ?string
    {
        $scanned = trim($scanned);

        if (! str_starts_with($scanned, self::PREFIX)) {
            return null;
        }

        $token = substr($scanned, strlen(self::PREFIX));

        return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $token : null;
    }

    public static function svg(Pallet $pallet, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString(self::payload($pallet));
    }
}

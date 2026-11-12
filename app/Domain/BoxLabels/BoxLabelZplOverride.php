<?php

namespace App\Domain\BoxLabels;

use App\Models\BoxLabelBatch;
use Illuminate\Support\Facades\File;

/**
 * Almacena y resuelve modificaciones puntuales de texto sobre las etiquetas ZPL
 * antes de su impresión física en Zebra, sin alterar la estructura ni registros
 * inmutables (append-only) de `box_label_batches`.
 */
final class BoxLabelZplOverride
{
    private const DIR = 'app/box_label_overrides';

    public static function resolveZpl(BoxLabelBatch $batch): string
    {
        $override = self::get($batch->id);
        if ($override !== null && trim($override['zpl']) !== '') {
            return $override['zpl'];
        }

        return (string) ($batch->zpl ?? '');
    }

    /**
     * @return array{item_code: string, item_description: string, units_text: string, extra_text: string, zpl: string}|null
     */
    public static function get(int $batchId): ?array
    {
        $path = self::path($batchId);
        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return [
            'item_code' => (string) ($decoded['item_code'] ?? ''),
            'item_description' => (string) ($decoded['item_description'] ?? ''),
            'units_text' => (string) ($decoded['units_text'] ?? ''),
            'extra_text' => (string) ($decoded['extra_text'] ?? ''),
            'zpl' => (string) ($decoded['zpl'] ?? ''),
        ];
    }

    public static function save(
        int $batchId,
        string $itemCode,
        string $itemDescription,
        string $unitsText,
        string $extraText,
        string $zpl,
    ): void {
        $path = self::path($batchId);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'item_code' => $itemCode,
            'item_description' => $itemDescription,
            'units_text' => $unitsText,
            'extra_text' => $extraText,
            'zpl' => $zpl,
            'updated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function clear(int $batchId): void
    {
        $path = self::path($batchId);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    private static function path(int $batchId): string
    {
        return storage_path(self::DIR.'/batch_'.$batchId.'.json');
    }
}

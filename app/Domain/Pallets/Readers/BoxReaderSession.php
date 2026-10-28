<?php

namespace App\Domain\Pallets\Readers;

use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Exceptions\BoxAlreadyRead;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Models\Device;
use App\Services\TraceLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What a fixed reader sends, turned into counted boxes. The reader sends
 * text: one read per line, ended by CR, LF or both (some add STX/ETX).
 * Each line goes through the same rules as a box read by hand.
 *
 * - A box already counted is skipped quietly: a fixed reader may read the
 *   same label twice while the box goes by.
 * - Anything else that is not counted (a failed read such as "NoRead", a
 *   label this application did not print, an order no longer released)
 *   leaves a scan.rejected trace on the reader and a warning in the log,
 *   because that box went by without being counted.
 */
class BoxReaderSession
{
    private const MAX_LINE = 500;

    private string $buffer = '';

    public function __construct(
        private readonly Device $reader,
        private readonly RegisterManualBoxScan $scan,
        private readonly TraceLogger $trace,
    ) {}

    /**
     * Takes a chunk as it came from the reader and handles every complete
     * line in it. A partial line waits for the next chunk.
     *
     * @return list<BoxRead>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $lines = preg_split('/[\r\n]+/', $this->buffer) ?: [];
        $this->buffer = (string) array_pop($lines);

        // A reader that never sends a line end would grow the buffer forever.
        if (strlen($this->buffer) > self::MAX_LINE) {
            $lines[] = $this->buffer;
            $this->buffer = '';
        }

        $reads = [];
        foreach ($lines as $line) {
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $line));
            if ($text !== '') {
                $reads[] = $this->read($text);
            }
        }

        return $reads;
    }

    private function read(string $text): BoxRead
    {
        $ctx = TraceContext::forDevice($this->reader, (string) Str::uuid(), origin: Origin::Console);

        try {
            $scan = $this->scan->fromReader($this->reader, $text, $ctx);

            return new BoxRead(BoxReadOutcome::Counted, $scan->boxCode.' → '.($scan->result->palletCode ?? ''));
        } catch (BoxAlreadyRead $e) {
            return new BoxRead(BoxReadOutcome::AlreadyRead, $e->getMessage());
        } catch (PalletRuleViolation $e) {
            $shown = Str::limit($text, 60);
            $this->trace->record($ctx, TraceEventType::ScanRejected, $this->reader, result: TraceResult::Rejected, reason: $e->getMessage(), context: ['read' => $shown]);
            Log::warning('Caja no contada por el lector fijo', ['device' => $this->reader->code, 'read' => $shown, 'reason' => $e->getMessage()]);

            return new BoxRead(BoxReadOutcome::Rejected, $e->getMessage());
        }
    }
}

<?php

namespace App\Domain\Pallets\Readers;

use App\Domain\Pallets\Actions\RegisterManualBoxScan;
use App\Domain\Pallets\Exceptions\BoxAlreadyRead;
use App\Domain\Pallets\Exceptions\PalletRuleViolation;
use App\Domain\Pallets\Exceptions\ReaderNoRead;
use App\Domain\Shared\Origin;
use App\Domain\Shared\TraceContext;
use App\Domain\Shared\TraceEventType;
use App\Domain\Shared\TraceResult;
use App\Models\Device;
use App\Services\TraceLogger;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
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
    use DetectsLostConnections;

    private const MAX_LINE = 500;

    /** A complete box QR: prefix, the ":" (or what the keyboard layout typed instead) and the 32-character token. */
    private const BOX_QR = '/TRZB.{1,4}?[0-9a-f]{32}/i';

    /** Anything a reader puts between reads: line ends, STX/ETX, tabs and other control characters. */
    private const SEPARATOR = '/[\x00-\x1F\x7F]+/';

    /** Attempts of a read that hit a passing database error (deadlock, lost connection). */
    private const ATTEMPTS = 3;

    private string $buffer = '';

    public function __construct(
        private readonly Device $reader,
        private readonly RegisterManualBoxScan $scan,
        private readonly TraceLogger $trace,
    ) {}

    /**
     * Takes a chunk as it came from the reader and handles every read in it.
     *
     * A box QR is read the moment its token is complete, whatever comes
     * around it: a line end, STX/ETX, a tab, or nothing at all when two reads
     * arrive glued together on a fast line. Anything else (a failed read such
     * as "NoRead") is a read once a separator ends it. A partial read waits
     * for the next chunk.
     *
     * @return list<BoxRead>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $reads = [];

        // Byte-safe (no /u): the ":" may come as another symbol on another keyboard layout.
        while (preg_match(self::BOX_QR, $this->buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
            foreach ($this->segments(substr($this->buffer, 0, $m[0][1])) as $other) {
                $reads[] = $this->read($other);
            }
            $reads[] = $this->read($m[0][0]);
            $this->buffer = substr($this->buffer, $m[0][1] + strlen($m[0][0]));
        }

        $parts = preg_split(self::SEPARATOR, $this->buffer) ?: [];
        $this->buffer = (string) array_pop($parts);

        // A reader that never sends a separator would grow the buffer forever.
        if (strlen($this->buffer) > self::MAX_LINE) {
            $parts[] = $this->buffer;
            $this->buffer = '';
        }

        foreach ($parts as $part) {
            foreach ($this->segments($part) as $other) {
                $reads[] = $this->read($other);
            }
        }

        return $reads;
    }

    /**
     * The non-empty texts in $text between separators.
     *
     * @return list<string>
     */
    private function segments(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split(self::SEPARATOR, $text) ?: []), fn (string $t) => $t !== ''));
    }

    private function read(string $text): BoxRead
    {
        $text = mb_scrub($text);
        $ctx = TraceContext::forDevice($this->reader, (string) Str::uuid(), origin: Origin::Console);

        for ($attempt = 1; ; $attempt++) {
            try {
                $scan = $this->scan->fromReader($this->reader, $text, $ctx);

                return new BoxRead(BoxReadOutcome::Counted, $scan->boxCode.' → '.($scan->result->palletCode ?? ''));
            } catch (BoxAlreadyRead $e) {
                return new BoxRead(BoxReadOutcome::AlreadyRead, $e->getMessage());
            } catch (ReaderNoRead $e) {
                return $this->notCounted($ctx, $text, $e->getMessage(), noRead: true);
            } catch (PalletRuleViolation $e) {
                return $this->notCounted($ctx, $text, $e->getMessage());
            } catch (\Throwable $e) {
                // A deadlock or a dropped connection passes: try again. Anything else must not stop
                // the reader either, or the reads still in this chunk and those sent while it restarts are lost.
                if ($attempt < self::ATTEMPTS && $this->passing($e)) {
                    usleep(50_000 * $attempt);

                    continue;
                }

                report($e);

                return $this->notCounted($ctx, $text, 'Error al registrar la lectura: '.Str::limit($e->getMessage(), 150));
            }
        }
    }

    private function notCounted(TraceContext $ctx, string $text, string $reason, bool $noRead = false): BoxRead
    {
        $shown = Str::limit($text, 60);

        try {
            $this->trace->record($ctx, TraceEventType::ScanRejected, $this->reader, result: TraceResult::Rejected, reason: $reason, context: ['read' => $shown, ...($noRead ? ['no_read' => true] : [])]);
        } catch (\Throwable $e) {
            report($e);
        }
        Log::warning('Caja no contada por el lector fijo', ['device' => $this->reader->code, 'read' => $shown, 'reason' => $reason]);

        return new BoxRead(BoxReadOutcome::Rejected, $reason);
    }

    private function passing(\Throwable $e): bool
    {
        $state = $e instanceof QueryException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : '';

        // 40P01 deadlock, 40001 serialization failure, 08xxx connection errors.
        return in_array($state, ['40P01', '40001'], true) || str_starts_with($state, '08')
            || ($e instanceof QueryException && $this->causedByLostConnection($e));
    }
}

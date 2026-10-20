<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * Log channel tap: one JSON object per line, with secrets masked in the
 * context and extra fields. The correlation id comes from the shared
 * context (AssignCorrelationId).
 */
class StructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, appendNewline: true, includeStacktraces: false));
            }
        }

        $monolog->pushProcessor(fn (LogRecord $record) => $record->with(
            context: Redactor::redact($record->context),
            extra: Redactor::redact($record->extra),
        ));
    }
}

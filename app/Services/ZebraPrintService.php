<?php

namespace App\Services;

use App\Domain\BoxLabels\LabelFormatConfig;

class ZebraPrintService
{
    /**
     * Reemplaza variables parametrizables {variable} en una plantilla ZPL
     *
     * @param  array<string, mixed>  $variables
     */
    public function interpolate(string $templateZpl, array $variables): string
    {
        $replace = [];
        foreach ($variables as $key => $val) {
            $cleanVal = trim((string) $val);
            $replace['{'.$key.'}'] = $cleanVal;
            $replace['{{'.$key.'}}'] = $cleanVal;
            $replace['{{ '.$key.' }}'] = $cleanVal;
            $replace['{ '.$key.' }'] = $cleanVal;
        }

        return strtr($templateZpl, $replace);
    }

    /**
     * Extrae todas las variables {variable} encontradas en una plantilla ZPL
     *
     * @return list<string>
     */
    public function extractVariables(string $templateZpl): array
    {
        preg_match_all('/\{{1,2}\s*([a-zA-Z0-9_\-]+)\s*\}{1,2}/', $templateZpl, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Asegura que el flujo ZPL tenga configurada la velocidad de impresión (^PRp,s,b).
     * Si no la tiene, la inyecta después de cada ^XA; si ya la tiene, actualiza la velocidad
     * al valor configurado para garantizar que la Zebra imprima siempre al ritmo deseado.
     */
    public function ensurePrintSpeed(string $zpl, ?int $speed = null): string
    {
        $configured = $speed ?? LabelFormatConfig::printSpeed();
        $speedVal = max(2, min(14, $configured));

        // Si ya contiene algún comando ^PR explícito, actualizarlo a la velocidad parametrizada
        if (preg_match('/\^PR\d+/i', $zpl) === 1) {
            return preg_replace('/\^PR\d+(?:,\d+)?(?:,\d+)?/i', "^PR{$speedVal},{$speedVal},{$speedVal}", $zpl) ?? $zpl;
        }

        // Si no tiene ^PR, inyectar ^PR{speed},{speed},{speed} justo después de cada ^XA
        return preg_replace('/(\^XA)/i', "$1\n^PR{$speedVal},{$speedVal},{$speedVal}", $zpl) ?? $zpl;
    }

    /**
     * Divide una cadena ZPL multietiqueta en un arreglo de etiquetas individuales (^XA ... ^XZ).
     *
     * @return list<string>
     */
    public function splitZplIntoLabels(string $zpl): array
    {
        if (preg_match_all('/\^XA.*?\^XZ/is', $zpl, $matches)) {
            return $matches[0];
        }

        $trimmed = trim($zpl);

        return ! empty($trimmed) ? [$trimmed] : [];
    }

    /**
     * Consulta el odómetro de etiquetas físicas impresas en la Zebra vía SGD con reintentos para alta confiabilidad.
     */
    public function queryOdometer(string $ip, int $port = 9100, float $timeout = 2.5, int $retries = 3): ?int
    {
        $ip = trim($ip);
        if (empty($ip)) {
            return null;
        }

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);
            if ($socket) {
                stream_set_timeout($socket, (int) ceil($timeout), (int) (($timeout - floor($timeout)) * 1000000));
                @fwrite($socket, "{}{! U1 getvar \"odometer.total_label_count\"}\r\n");
                @fflush($socket);

                usleep(150000); // 150ms

                $response = '';
                while ($chunk = @fread($socket, 512)) {
                    $response .= $chunk;
                    if (strlen($chunk) < 512) {
                        break;
                    }
                }
                @fclose($socket);

                if (preg_match('/"?(\d+)"?/', $response, $matches)) {
                    return (int) $matches[1];
                }
            }

            if ($attempt < $retries) {
                usleep(300000); // 300ms antes del siguiente reintento
            }
        }

        return null;
    }

    /**
     * Escribe datos en un socket TCP de forma segura y fragmentada en bloques,
     * protegiendo contra desbordamientos y el error PHP errno=11 (EAGAIN/EWOULDBLOCK).
     *
     * @param  resource  $socket
     */
    public function writeSocketSafely($socket, string $data, int $timeoutSeconds = 20): bool
    {
        $totalBytes = strlen($data);
        $bytesSent = 0;
        $chunkSize = 8192; // 8 KB
        $startTime = microtime(true);

        stream_set_blocking($socket, false);

        while ($bytesSent < $totalBytes) {
            if ((microtime(true) - $startTime) > $timeoutSeconds) {
                return false;
            }

            $writeStreams = [$socket];
            $readStreams = null;
            $exceptStreams = null;

            $ready = @stream_select($readStreams, $writeStreams, $exceptStreams, 2);
            if ($ready === false || $ready === 0) {
                continue;
            }

            $slice = substr($data, $bytesSent, $chunkSize);
            $written = @fwrite($socket, $slice);

            if ($written === false) {
                return false;
            }

            if ($written > 0) {
                $bytesSent += $written;
            } else {
                usleep(50000); // 50ms
            }
        }

        @fflush($socket);

        return true;
    }

    /**
     * Envía ZPL directamente a la IP y puerto de la Zebra por Socket TCP RAW
     *
     * @return array<string, mixed>
     */
    public function printToSocket(string $ip, int $port = 9100, string $zpl = '', int $timeout = 3): array
    {
        $startTime = microtime(true);
        $errno = 0;
        $errstr = '';

        $ip = trim($ip);
        if (empty($ip)) {
            return [
                'success' => false,
                'status' => 'INVALID_IP',
                'message' => 'La dirección IP de la impresora no puede estar vacía.',
                'bytes_sent' => 0,
                'latency_ms' => 0,
            ];
        }

        // Asegurar velocidad de impresión configurada (evita que la Zebra imprima a máxima velocidad descontrolada)
        $zpl = $this->ensurePrintSpeed($zpl);
        $labels = $this->splitZplIntoLabels($zpl);
        $totalLabels = count($labels);

        $totalBytes = strlen($zpl);
        $effectiveTimeout = max($timeout, min(90, (int) ceil($totalBytes / 8000) + $timeout));

        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if (! $socket) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success' => false,
                'status' => 'CONNECTION_FAILED',
                'error_code' => $errno,
                'message' => "Fallo de conexión con la impresora Zebra en {$ip}:{$port}. ({$errstr})",
                'details' => 'Verifique que la impresora esté encendida, con cable de red conectado y en el mismo segmento IP.',
                'bytes_sent' => 0,
                'latency_ms' => $latency,
            ];
        }

        $sent = $this->writeSocketSafely($socket, $zpl, $effectiveTimeout);
        @fclose($socket);

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        if (! $sent) {
            return [
                'success' => false,
                'status' => 'WRITE_INCOMPLETE',
                'message' => "Transmisión incompleta a la Zebra: tiempo de espera superado tras {$latency} ms.",
                'details' => 'El búfer de la impresora no pudo recibir todos los datos a tiempo.',
                'bytes_sent' => 0,
                'ip' => $ip,
                'port' => $port,
                'latency_ms' => $latency,
            ];
        }

        return [
            'success' => true,
            'status' => 'PRINTED',
            'message' => "Trabajo transmitido exitosamente a la impresora Zebra ({$totalLabels} etiquetas en {$latency} ms).",
            'bytes_sent' => $totalBytes,
            'ip' => $ip,
            'port' => $port,
            'latency_ms' => $latency,
        ];
    }

    /**
     * Imprime un sub-lote (chunk) de etiquetas ZPL monitoreando activamente el estado físico
     * de la Zebra (odómetro, falta de papel, cabezal abierto, ribbon) para saber exactamente
     * cuántas etiquetas salieron físicamente y detectar cualquier interrupción.
     *
     * @return array{
     *     success: bool,
     *     status: string,
     *     message: string,
     *     start_odometer?: int|null,
     *     last_odometer?: int|null,
     *     printed_count: int,
     *     remaining_in_chunk: int,
     *     hardware_status?: string,
     *     error_reason?: string
     * }
     */
    public function printChunk(string $ip, int $port, string $chunkZpl, int $expectedLabels, ?int $knownStartOdo = null, int $maxWaitSeconds = 35): array
    {
        // 1. Verificar estado inicial de la impresora
        $initialStatus = $this->getPrinterStatus($ip, $port, 2.0);
        if (! ($initialStatus['online'] ?? false)) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "La impresora Zebra no responde en {$ip}:{$port}.",
                'start_odometer' => $knownStartOdo,
                'last_odometer' => $knownStartOdo,
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => $initialStatus['error_explanation'] ?? 'Impresora fuera de línea',
            ];
        }

        if ($initialStatus['head_open'] ?? false) {
            return [
                'success' => false,
                'status' => 'HEAD_OPEN',
                'message' => 'Cabezal de la impresora abierto. Por favor ciérrelo para continuar.',
                'start_odometer' => $knownStartOdo,
                'last_odometer' => $knownStartOdo,
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Cabezal abierto',
            ];
        }

        if ($initialStatus['paper_out'] ?? false) {
            return [
                'success' => false,
                'status' => 'PAPER_OUT',
                'message' => 'Falta de rollo de etiquetas o papel atascado en la impresora.',
                'start_odometer' => $knownStartOdo,
                'last_odometer' => $knownStartOdo,
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Sin papel / rollo de etiquetas',
            ];
        }

        if ($initialStatus['ribbon_out'] ?? false) {
            return [
                'success' => false,
                'status' => 'RIBBON_OUT',
                'message' => 'Cinta de impresión (Ribbon) agotada en la impresora.',
                'start_odometer' => $knownStartOdo,
                'last_odometer' => $knownStartOdo,
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Sin cinta ribbon',
            ];
        }

        // 2. Consultar odómetro inicial antes de la transmisión si no se conoce
        $startOdo = $knownStartOdo ?? $this->queryOdometer($ip, $port, 2.5, 3);

        // 3. Asegurar velocidad configurada e inyectar
        $chunkZpl = $this->ensurePrintSpeed($chunkZpl);

        // 4. Conectar y transmitir el chunk de forma segura
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($ip, $port, $errno, $errstr, 4.0);
        if (! $socket) {
            return [
                'success' => false,
                'status' => 'CONNECTION_FAILED',
                'message' => "No se pudo conectar a la impresora Zebra ({$errstr}).",
                'start_odometer' => $startOdo,
                'last_odometer' => $startOdo,
                'printed_count' => 0,
                'remaining_in_chunk' => $expectedLabels,
                'error_reason' => 'Fallo de conexión',
            ];
        }

        $sent = $this->writeSocketSafely($socket, $chunkZpl, 20);
        @fflush($socket);
        @fclose($socket);

        if (! $sent) {
            $currentOdo = $this->queryOdometer($ip, $port, 2.5, 3);
            $printed = ($startOdo !== null && $currentOdo !== null)
                ? max(0, min($expectedLabels, $currentOdo - $startOdo))
                : 0;

            return [
                'success' => false,
                'status' => 'TRANSMISSION_ERROR',
                'message' => 'Error durante el envío de datos a la impresora.',
                'start_odometer' => $startOdo,
                'last_odometer' => $currentOdo,
                'printed_count' => $printed,
                'remaining_in_chunk' => max(0, $expectedLabels - $printed),
                'error_reason' => 'Error de transmisión socket',
            ];
        }

        // 5. Monitorear hasta que las etiquetas salgan físicamente o se detecte error
        $startTime = microtime(true);
        $estimatedTime = max(2, (int) ceil($expectedLabels * 0.7));
        $effectiveMaxWait = max($maxWaitSeconds, $estimatedTime + 8);
        $lastObservedOdo = $startOdo;

        while ((microtime(true) - $startTime) < $effectiveMaxWait) {
            // Pausa de 1.5s entre revisiones para no saturar el stack de red de la Zebra
            usleep(1500000);

            // Verificar si el odómetro físico ya avanzó la cantidad esperada
            $currentOdo = $this->queryOdometer($ip, $port, 2.0, 2);
            if ($currentOdo !== null) {
                $lastObservedOdo = $currentOdo;
                if ($startOdo !== null) {
                    $printedSoFar = max(0, min($expectedLabels, $currentOdo - $startOdo));
                    if ($printedSoFar >= $expectedLabels) {
                        return [
                            'success' => true,
                            'status' => 'CHUNK_COMPLETED',
                            'message' => "Sub-lote de {$expectedLabels} etiquetas impreso exitosamente.",
                            'start_odometer' => $startOdo,
                            'last_odometer' => $currentOdo,
                            'printed_count' => $expectedLabels,
                            'remaining_in_chunk' => 0,
                        ];
                    }
                }
            }

            // Consultar estado de la máquina para detectar pausas o errores físicos
            $status = $this->getPrinterStatus($ip, $port, 1.8);
            if ($status['online'] ?? false) {
                if (($status['paper_out'] ?? false) || ($status['head_open'] ?? false) || ($status['ribbon_out'] ?? false)) {
                    $currentOdo = $this->queryOdometer($ip, $port, 2.5, 3) ?? $lastObservedOdo;
                    $printed = ($startOdo !== null && $currentOdo !== null)
                        ? max(0, min($expectedLabels, $currentOdo - $startOdo))
                        : 0;

                    $reason = ($status['paper_out'] ?? false) ? 'Falta de rollo de etiquetas o atascamiento' :
                              (($status['head_open'] ?? false) ? 'Cabezal abierto' : 'Cinta ribbon agotada');

                    return [
                        'success' => false,
                        'status' => 'HARDWARE_PAUSED',
                        'message' => "Impresora detenida: {$reason}.",
                        'start_odometer' => $startOdo,
                        'last_odometer' => $currentOdo,
                        'printed_count' => $printed,
                        'remaining_in_chunk' => max(0, $expectedLabels - $printed),
                        'error_reason' => $reason,
                    ];
                }

                // Si ya terminó de imprimir según el búfer y no quedan etiquetas pendientes
                if (($status['labels_remaining'] ?? 0) === 0 && ($status['formats_in_buffer'] ?? 0) === 0 && ! ($status['is_printing'] ?? false)) {
                    $currentOdo = $this->queryOdometer($ip, $port, 2.5, 3) ?? $lastObservedOdo;
                    $printed = ($startOdo !== null && $currentOdo !== null)
                        ? max(0, min($expectedLabels, $currentOdo - $startOdo))
                        : $expectedLabels;

                    $finalPrinted = ($printed > 0) ? $printed : $expectedLabels;

                    return [
                        'success' => true,
                        'status' => 'CHUNK_COMPLETED',
                        'message' => "Sub-lote de {$finalPrinted} etiquetas impreso exitosamente.",
                        'start_odometer' => $startOdo,
                        'last_odometer' => $currentOdo,
                        'printed_count' => $finalPrinted,
                        'remaining_in_chunk' => 0,
                    ];
                }
            }
        }

        // Si se agotó el tiempo máximo de espera
        $finalOdo = $this->queryOdometer($ip, $port, 2.5, 3) ?? $lastObservedOdo;
        $printed = ($startOdo !== null && $finalOdo !== null)
            ? max(0, min($expectedLabels, $finalOdo - $startOdo))
            : $expectedLabels;

        if ($printed >= $expectedLabels) {
            return [
                'success' => true,
                'status' => 'CHUNK_COMPLETED',
                'message' => "Sub-lote de {$expectedLabels} etiquetas impreso exitosamente.",
                'start_odometer' => $startOdo,
                'last_odometer' => $finalOdo,
                'printed_count' => $expectedLabels,
                'remaining_in_chunk' => 0,
            ];
        }

        return [
            'success' => false,
            'status' => 'HARDWARE_PAUSED',
            'message' => "Impresión pausada tras {$printed} etiquetas.",
            'start_odometer' => $startOdo,
            'last_odometer' => $finalOdo,
            'printed_count' => $printed,
            'remaining_in_chunk' => max(0, $expectedLabels - $printed),
            'error_reason' => 'Impresión en pausa o tiempo de espera superado',
        ];
    }

    /**
     * Consulta el estado en tiempo real de la Zebra mediante comandos nativos ~HS y SGD (Link-OS)
     *
     * @return array<string, mixed>
     */
    public function getPrinterStatus(string $ip, int $port = 9100, float $timeout = 1.5): array
    {
        $startTime = microtime(true);
        $errno = 0;
        $errstr = '';

        $ip = trim($ip);
        if (empty($ip)) {
            return [
                'online' => false,
                'status' => 'INVALID_IP',
                'status_label' => 'IP de impresora no definida',
                'badge_color' => 'slate',
                'message' => 'La dirección IP de la impresora no puede estar vacía.',
                'latency_ms' => 0,
            ];
        }

        $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if (! $socket) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $explanation = ($errno === 64 || $errno === 65 || str_contains(strtolower($errstr), 'route') || str_contains(strtolower($errstr), 'down'))
                ? "El dispositivo en {$ip} no responde en la red (ARP/Ruta no alcanzable)."
                : "No se pudo conectar a {$ip}:{$port}. ({$errstr})";

            return [
                'online' => false,
                'ip' => $ip,
                'port' => $port,
                'status' => 'OFFLINE',
                'status_label' => 'Sin enlace ('.$ip.')',
                'badge_color' => 'rose',
                'error_code' => $errno,
                'error_explanation' => $explanation,
                'message' => "La impresora física no responde en {$ip}:{$port}.",
                'troubleshooting' => 'La impresora física está encendida pero no responde en esa dirección IP.',
                'latency_ms' => $latency,
                'timestamp' => date('H:i:s'),
            ];
        }

        stream_set_timeout($socket, (int) ceil($timeout), (int) (($timeout - floor($timeout)) * 1000000));

        $cmd = "~HS\r\n! U1 getvar \"device.friendly_name\"\r\n! U1 getvar \"device.status\"\r\n";
        fwrite($socket, $cmd);
        fflush($socket);

        usleep(140000);

        $buffer = '';
        while ($chunk = fread($socket, 1024)) {
            $buffer .= $chunk;
            if (strlen($chunk) < 1024) {
                break;
            }
        }
        fclose($socket);

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        preg_match_all("/\x02([^\x03]+)\x03/", $buffer, $matches);
        $lines = $matches[1];

        $paperOut = false;
        $paused = false;
        $headOpen = false;
        $ribbonOut = false;
        $bufferFull = false;
        $formatsInBuffer = 0;
        $labelsRemaining = 0;

        if (count($lines) >= 2) {
            $l1 = explode(',', $lines[0]);
            $l2 = explode(',', $lines[1]);

            $paperOut = ($l1[1] ?? '0') === '1';
            $paused = ($l1[2] ?? '0') === '1';
            $formatsInBuffer = (int) ($l1[4] ?? 0);
            $bufferFull = ($l1[5] ?? '0') === '1';

            $headOpen = ($l2[2] ?? '0') === '1';
            $ribbonOut = ($l2[3] ?? '0') === '1';
            $labelsRemaining = (int) ($l2[8] ?? 0);
        }

        $deviceName = 'Zebra ZT411';
        $sgdStatus = 'ready';

        if (preg_match_all('/"([^"]+)"/', $buffer, $sgdMatches)) {
            $deviceName = $sgdMatches[1][0];
            $sgdStatus = strtolower(trim($sgdMatches[1][1] ?? 'ready'));
        }

        $statusKey = 'READY';
        $statusLabel = 'Lista para imprimir';
        $badgeColor = 'emerald';
        $isPrinting = false;

        if ($headOpen) {
            $statusKey = 'HEAD_OPEN';
            $statusLabel = 'Cabezal Abierto';
            $badgeColor = 'rose';
        } elseif ($paperOut || str_contains($sgdStatus, 'paper out') || str_contains($sgdStatus, 'media')) {
            $statusKey = 'PAPER_OUT';
            $statusLabel = 'Sin papel / Atascada';
            $badgeColor = 'rose';
        } elseif ($ribbonOut || str_contains($sgdStatus, 'ribbon')) {
            $statusKey = 'RIBBON_OUT';
            $statusLabel = 'Sin cinta Ribbon';
            $badgeColor = 'amber';
        } elseif ($labelsRemaining > 0 || str_contains($sgdStatus, 'printing')) {
            $statusKey = 'PRINTING';
            $statusLabel = "Imprimiendo (quedan {$labelsRemaining})";
            $badgeColor = 'blue';
            $isPrinting = true;
        } elseif ($paused || str_contains($sgdStatus, 'pause')) {
            $statusKey = 'PAUSED';
            $statusLabel = 'Pausada';
            $badgeColor = 'amber';
        } elseif ($formatsInBuffer > 0) {
            $statusKey = 'BUFFERED';
            $statusLabel = "Procesando ({$formatsInBuffer} en cola)";
            $badgeColor = 'indigo';
        }

        return [
            'online' => true,
            'ip' => $ip,
            'port' => $port,
            'device_name' => $deviceName,
            'status' => $statusKey,
            'status_label' => $statusLabel,
            'badge_color' => $badgeColor,
            'is_printing' => $isPrinting,
            'is_paused' => $paused,
            'paper_out' => $paperOut,
            'head_open' => $headOpen,
            'ribbon_out' => $ribbonOut,
            'buffer_full' => $bufferFull,
            'formats_in_buffer' => $formatsInBuffer,
            'labels_remaining' => $labelsRemaining,
            'sgd_status' => $sgdStatus,
            'latency_ms' => $latency,
            'timestamp' => date('H:i:s'),
        ];
    }
}

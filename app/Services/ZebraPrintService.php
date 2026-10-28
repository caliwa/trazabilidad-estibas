<?php

namespace App\Services;

class ZebraPrintService
{
    /**
     * Reemplaza variables parametrizables {variable} en una plantilla ZPL
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
     */
    public function extractVariables(string $templateZpl): array
    {
        preg_match_all('/\{{1,2}\s*([a-zA-Z0-9_\-]+)\s*\}{1,2}/', $templateZpl, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Envía ZPL directamente a la IP y puerto de la Zebra por Socket TCP RAW
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

        stream_set_timeout($socket, $timeout);

        $bytesSent = fwrite($socket, $zpl);
        fflush($socket);
        fclose($socket);

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'success' => true,
            'status' => 'PRINTED',
            'message' => "Trabajo transmitido exitosamente a la impresora Zebra ({$bytesSent} bytes en {$latency} ms).",
            'bytes_sent' => $bytesSent,
            'ip' => $ip,
            'port' => $port,
            'latency_ms' => $latency,
        ];
    }

    /**
     * Consulta el estado en tiempo real de la Zebra mediante comandos nativos ~HS y SGD (Link-OS)
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
        $lines = $matches[1] ?? [];

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

        if (preg_match_all('/"([^"]+)"/', $buffer, $sgdMatches) && ! empty($sgdMatches[1])) {
            $deviceName = $sgdMatches[1][0] ?? 'Zebra ZT411';
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

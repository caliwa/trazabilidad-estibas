<?php

namespace App\Integrations\Sap\ServiceLayer;

/**
 * Network checks before talking to Service Layer, so a failed connection
 * says where it breaks: the URL, the name resolution, the TCP port or a
 * proxy in the way. Never prints credentials (a proxy's user and password
 * are masked).
 */
final class NetworkProbe
{
    /**
     * @param  array<string, string|false>|null  $env  environment to read the proxy from (tests); the process environment by default
     * @return list<array{ok: bool|null, text: string}> ok null is a notice, not a failure
     */
    public static function run(string $baseUrl, int $timeoutSeconds = 5, ?array $env = null): array
    {
        $checks = [];
        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return [['ok' => false, 'text' => 'La URL no es válida. Debe ser como https://servidor:50000/b1s/v1']];
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $checks[] = ['ok' => true, 'text' => "URL: {$scheme}://{$host}:{$port}"];

        if (! str_contains((string) ($parts['path'] ?? ''), '/b1s/')) {
            $checks[] = ['ok' => null, 'text' => 'La URL no termina en /b1s/v1 (o /b1s/v2). Service Layer normalmente responde ahí.'];
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $checks[] = ['ok' => null, 'text' => 'El host es local. Si la aplicación corre en Docker o en otra máquina, "localhost" no es el servidor de SAP.'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $ips = gethostbynamel($host);
            if ($ips === false) {
                $checks[] = ['ok' => false, 'text' => "El nombre {$host} no se resuelve desde este servidor (DNS). Revisar el nombre, el DNS o usar la IP."];

                return $checks;
            }
            $checks[] = ['ok' => true, 'text' => "{$host} se resuelve a ".implode(', ', $ips)];
        }

        $proxy = self::proxyFor($scheme, $host, $env);
        if ($proxy !== null) {
            $checks[] = ['ok' => null, 'text' => "Las llamadas a SAP salen por el proxy {$proxy} (variable de entorno). Si SAP está en la red interna, agregar {$host} a NO_PROXY."];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);

        if ($socket === false) {
            $checks[] = ['ok' => false, 'text' => "No se pudo abrir conexión TCP a {$host}:{$port} ({$errstr}). "
                .'Causas usuales: el servidor no está en la misma red o VPN que SAP, un firewall bloquea el puerto para la IP de este servidor, o el puerto no es el de Service Layer (normalmente 50000 con https).'];

            return $checks;
        }

        fclose($socket);
        $checks[] = ['ok' => true, 'text' => "El puerto {$port} de {$host} acepta conexiones."];

        return $checks;
    }

    /**
     * The proxy Guzzle would use for this URL, with any credentials masked; null when none applies.
     *
     * @param  array<string, string|false>|null  $env
     */
    public static function proxyFor(string $scheme, string $host, ?array $env = null): ?string
    {
        $read = fn (string $name): string => (string) (($env !== null ? ($env[$name] ?? $env[strtolower($name)] ?? false) : (getenv($name) ?: getenv(strtolower($name)))) ?: '');

        $proxy = $scheme === 'https' ? $read('HTTPS_PROXY') : $read('HTTP_PROXY');
        if ($proxy === '') {
            return null;
        }

        foreach (array_filter(array_map('trim', explode(',', $read('NO_PROXY')))) as $rule) {
            $rule = ltrim($rule, '.');
            if ($rule === '*' || $host === $rule || str_ends_with($host, '.'.$rule)) {
                return null;
            }
        }

        $p = parse_url($proxy);

        return isset($p['host']) ? (($p['scheme'] ?? 'http').'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '')) : 'configurado';
    }
}

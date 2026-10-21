<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run on the server before switching a release live. Fails (exit 1) on
 * anything that would make production unsafe; also lists which pending
 * business decisions are still switched off.
 */
#[Signature('app:preflight')]
#[Description('Verifica la configuración de producción antes de activar una versión.')]
class Preflight extends Command
{
    private int $failures = 0;

    public function handle(): int
    {
        $this->components->info('Configuración');

        $this->check(app()->environment('production'), 'APP_ENV=production');
        $this->check(! config()->boolean('app.debug'), 'APP_DEBUG=false (sin trazas internas al usuario)');
        $this->check(filled(config('app.key')), 'APP_KEY definida');
        $this->check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL con https');
        $this->check(config('session.secure') === true, 'SESSION_SECURE_COOKIE=true');
        $this->check(config('session.http_only') === true, 'Cookie de sesión http_only');
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'Cookie de sesión same_site lax o strict');
        $this->check(! in_array(config('queue.default'), ['sync', 'null'], true), 'QUEUE_CONNECTION persistente (no sync)');
        $this->check(! in_array(config('cache.default'), ['array', 'null'], true), 'CACHE_STORE compartido (no array)');
        $this->check(config('sap.driver') !== 'fake', 'SAP_DRIVER real (el simulador no sirve en producción; DP-09)');
        if (config('sap.driver') === 'service_layer') {
            $env = config()->string('sap.environment');
            $sl = config()->array('sap.service_layer');
            $this->check(filled($sl['base_url'] ?? null) && filled($sl['company_db'] ?? null) && filled($sl['username'] ?? null) && filled($sl['password'] ?? null), "Credenciales de Service Layer completas (SAP_API_URL_{$env}, SAP_COMPANY_{$env}, SAP_USER_{$env}, SAP_PASSWORD_{$env})");
            $this->check(str_starts_with((string) ($sl['base_url'] ?? ''), 'https://'), "SAP_API_URL_{$env} con https");
            $this->check(($sl['verify_tls'] ?? true) === true, 'SAP_SL_VERIFY_TLS=true (certificado de SAP verificado)');
        }
        $this->check($this->databaseReachable(), 'PostgreSQL responde');
        $this->warnIf(config('logging.channels.'.config('logging.default').'.level') === 'debug', 'LOG_LEVEL=debug escribe demasiado en producción');
        $this->warnIf(in_array(config('mail.default'), ['log', 'array'], true), 'El correo no sale (MAIL_MAILER log o array): las alertas solo quedan en la app');
        $this->warnIf(config('session.encrypt') !== true, 'SESSION_ENCRYPT=false');

        $this->components->info('Decisiones pendientes (apagadas = comportamiento bloqueado)');
        $decisions = config()->array('traceability.decisions');
        $this->decision('DP-01 recepción de lecturas de sensores', $decisions['scan_ingestion_enabled'] ?? false);
        $this->decision('DP-03 confirmación de etiqueta aplicada', filled($decisions['label_confirmation'] ?? null));
        $this->decision('DP-04 reauditoría', $decisions['discrepancy_reaudit_enabled'] ?? false);
        $this->decision('DP-04 acciones de cierre de discrepancias', ($decisions['discrepancy_close_actions'] ?? []) !== []);

        if ($this->failures > 0) {
            $this->components->error("{$this->failures} verificaciones fallaron. No activar esta versión.");

            return self::FAILURE;
        }

        $this->components->info('Listo para activar.');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label): void
    {
        $this->components->twoColumnDetail($label, $ok ? '<fg=green>OK</>' : '<fg=red>FALLA</>');
        $this->failures += $ok ? 0 : 1;
    }

    private function warnIf(bool $problem, string $label): void
    {
        if ($problem) {
            $this->components->twoColumnDetail($label, '<fg=yellow>AVISO</>');
        }
    }

    private function decision(string $label, mixed $enabled): void
    {
        $this->components->twoColumnDetail($label, $enabled ? 'activada' : '<fg=yellow>apagada</>');
    }

    private function databaseReachable(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

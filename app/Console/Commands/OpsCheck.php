<?php

namespace App\Console\Commands;

use App\Domain\Operations\OperationalChecks;
use App\Enums\Permission;
use App\Models\User;
use App\Notifications\OperationalAlert;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

#[Signature('ops:check')]
#[Description('Revisa colas, workers, SAP, conciliaciones y discrepancias, y avisa a quienes tienen ops.alerts.')]
class OpsCheck extends Command
{
    public function handle(OperationalChecks $checks): int
    {
        $alerts = $checks->run();
        $recipients = User::permission(Permission::OpsAlerts->value)->active()->get();
        $cooldown = now()->addMinutes(config()->integer('operations.notify_cooldown_minutes'));

        foreach ($alerts as $alert) {
            Log::warning('ops.alert', ['key' => $alert->key, 'level' => $alert->level->value, 'count' => $alert->count, 'detail' => $alert->detail]);
            $this->line("[{$alert->level->value}] {$alert->title}: {$alert->detail}");

            // add() only succeeds once per cooldown, so a lasting problem is not mailed every 5 minutes.
            if ($recipients->isNotEmpty() && Cache::add("ops:notified:{$alert->key}", true, $cooldown)) {
                try {
                    Notification::sendNow($recipients, new OperationalAlert($alert));
                } catch (Throwable $e) {
                    Cache::forget("ops:notified:{$alert->key}");
                    Log::error('ops.alert_not_sent', ['key' => $alert->key, 'error' => $e::class]);
                }
            }
        }

        if ($alerts === []) {
            $this->info('Sin alertas.');
        }

        return self::SUCCESS;
    }
}

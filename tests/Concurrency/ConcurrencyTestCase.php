<?php

namespace Tests\Concurrency;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real parallel processes, each with its own PostgreSQL connection, run by
 * tests/Concurrency/worker.php. Uses its own database (<testing db>_concurrency)
 * because the workers must see committed data, which RefreshDatabase's
 * transaction would hide.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    protected string $baseDatabase;

    protected string $database;

    protected string $goFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDatabase = config()->string('database.connections.pgsql.database');
        $this->database = $this->baseDatabase.'_concurrency';

        if ((int) DB::scalar('SELECT count(*) FROM pg_database WHERE datname = ?', [$this->database]) === 0) {
            DB::statement('CREATE DATABASE "'.$this->database.'"');
        }

        $this->useDatabase($this->database);
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->goFile = sys_get_temp_dir().'/scan-concurrency-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->goFile);
        $this->useDatabase($this->baseDatabase);

        parent::tearDown();
    }

    protected function useDatabase(string $name): void
    {
        DB::purge('pgsql');
        config(['database.connections.pgsql.database' => $name]);
        DB::reconnect('pgsql');
    }

    /**
     * Starts every job as its own PHP process, releases them together and
     * returns their summaries.
     *
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array{accepted: int, duplicate: int, rejected: int, closed: int, close_refused: int, errors: list<string>}>
     */
    protected function runInParallel(array $jobs): array
    {
        $connection = config()->array('database.connections.pgsql');
        $env = [
            ...getenv(),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'null',
        ];

        $processes = [];
        foreach ($jobs as $job) {
            $command = [PHP_BINARY, base_path('tests/Concurrency/worker.php'), json_encode([...$job, 'go' => $this->goFile], JSON_THROW_ON_ERROR)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        // Let every worker boot and connect, then release them at once.
        usleep(1_500_000);
        touch($this->goFile);

        $summaries = [];
        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            $this->assertSame(0, $exit, "El worker falló: {$err}{$out}");
            $summary = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([], $summary['errors'], 'Errores en el worker');
            $summaries[] = $summary;
        }

        return $summaries;
    }

    /**
     * @param  list<array<string, mixed>>  $summaries
     */
    protected function total(array $summaries, string $key): int
    {
        return array_sum(array_column($summaries, $key));
    }
}

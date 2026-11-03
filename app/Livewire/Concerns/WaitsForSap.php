<?php

namespace App\Livewire\Concerns;

use App\Domain\Integration\SapWait;
use Livewire\Attributes\Locked;

/**
 * For pages that send documents to SAP: after the action the page shows
 * "Esperando respuesta de SAP" over everything (x-sap-wait) and polls until
 * SAP answered every document, or SapWait::TIMEOUT_SECONDS passed. Only
 * then can the person close it and go on.
 */
trait WaitsForSap
{
    /** @var array{title: string, documents: list<array{type: 'receipt'|'transfer', id: int}>, reconcile: bool, started_at: int}|null */
    #[Locked]
    public ?array $sapWait = null;

    /**
     * @param  list<array{type: 'receipt'|'transfer', id: int}>  $documents
     */
    protected function waitForSap(string $title, array $documents, bool $reconcile = false): void
    {
        $this->sapWait = $documents === [] ? null : SapWait::start($title, $documents, $reconcile);
    }

    /**
     * @return array{done: bool, timed_out: bool, ok: bool, seconds: int, rows: list<array{label: string, state: 'waiting'|'ok'|'failed'|'pending', message: string}>}|null
     */
    public function sapWaitStatus(): ?array
    {
        return $this->sapWait === null ? null : SapWait::status($this->sapWait);
    }

    /** Called by the poll: the render reads the status again. */
    public function checkSapWait(): void {}

    public function closeSapWait(): void
    {
        $status = $this->sapWaitStatus();

        // Not before SAP answered (or the wait gave up): that is the point of waiting.
        if ($status !== null && ! $status['done'] && ! $status['timed_out']) {
            return;
        }

        $this->sapWait = null;
    }
}

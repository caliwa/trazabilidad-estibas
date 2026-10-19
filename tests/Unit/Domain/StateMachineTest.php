<?php

namespace Tests\Unit\Domain;

use App\Domain\Audits\Enums\AuditStatus;
use App\Domain\Integration\Enums\SapDocumentStatus;
use App\Domain\Pallets\Enums\PhysicalStatus;
use App\Domain\Receipts\Enums\ReceiptStatus;
use App\Domain\Shared\Exceptions\InvalidTransition;
use App\Domain\Transfers\Enums\TransferStatus;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    public function test_physical_status_moves_forward_one_step_at_a_time(): void
    {
        $this->assertTrue(PhysicalStatus::Stacking->canTransitionTo(PhysicalStatus::Finished));
        $this->assertFalse(PhysicalStatus::Stacking->canTransitionTo(PhysicalStatus::Palletized));
        $this->assertFalse(PhysicalStatus::Labeled->canTransitionTo(PhysicalStatus::Palletized));
        $this->assertTrue(PhysicalStatus::Labeled->isTerminal());
    }

    public function test_confirmed_states_are_terminal(): void
    {
        $this->assertTrue(ReceiptStatus::Confirmed->isTerminal());
        $this->assertTrue(TransferStatus::Confirmed->isTerminal());
        $this->assertTrue(AuditStatus::Approved->isTerminal());
        $this->assertTrue(SapDocumentStatus::Confirmed->isTerminal());
    }

    public function test_an_uncertain_send_can_only_be_resent_after_reconciliation(): void
    {
        $this->assertFalse(SapDocumentStatus::Sending->canTransitionTo(SapDocumentStatus::Sending));
        $this->assertTrue(SapDocumentStatus::Sending->canTransitionTo(SapDocumentStatus::PendingReconciliation));
        $this->assertTrue(SapDocumentStatus::PendingReconciliation->canTransitionTo(SapDocumentStatus::Sending));
        $this->assertFalse(SapDocumentStatus::Failed->canTransitionTo(SapDocumentStatus::Sending));
    }

    public function test_an_invalid_transition_throws_with_both_states(): void
    {
        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('CONFIRMADO_04 → SOLICITADO');

        ReceiptStatus::Confirmed->assertCanTransitionTo(ReceiptStatus::Requested);
    }
}

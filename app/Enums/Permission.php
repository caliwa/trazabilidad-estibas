<?php

namespace App\Enums;

/**
 * Global permissions. Every use case checks its permission on the server;
 * hiding a button is never the protection.
 */
enum Permission: string
{
    /** Pallet board, pallet detail and indicators. */
    case PalletsView = 'pallets.view';
    /** Production station: watch the active pallet and close it manually. */
    case PalletsOperate = 'pallets.operate';
    case PalletsPalletize = 'pallets.palletize';
    /** Print the label and confirm it was applied. */
    case PalletsLabel = 'pallets.label';
    case PalletsReprint = 'pallets.reprint';
    /** Evaluate a rejected scan event again after fixing a master. */
    case ScansReprocess = 'scans.reprocess';
    case ReceiptsRequest = 'receipts.request';
    case ReceiptsReconcile = 'receipts.reconcile';
    /** Blind count at the warehouse. Never grants seeing expected quantities. */
    case AuditsCount = 'audits.count';
    case DiscrepanciesResolve = 'discrepancies.resolve';
    case TransfersRequest = 'transfers.request';
    case TransfersReconcile = 'transfers.reconcile';
    /** Register sensors and readers and rotate their tokens. */
    case DevicesManage = 'devices.manage';
    /** Traceability log (trace_events). */
    case TraceView = 'trace.view';
    /** Administration audit (users and roles). */
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
}

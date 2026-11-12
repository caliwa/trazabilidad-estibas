<?php

namespace App\Enums;

/**
 * Application roles (spatie/laravel-permission). Rules that depend on the
 * record, such as "the person who counted cannot resolve that discrepancy",
 * live in the use cases.
 */
enum Role: string
{
    case Admin = 'admin';
    case TraceabilityAdmin = 'traceability_admin';
    case ProductionSupervisor = 'production_supervisor';
    case Quality = 'quality';
    case ProductionOperator = 'production_operator';
    case ProductionSkater = 'production_skater';
    case LogisticsOperator = 'logistics_operator';
    case LogisticsCoordinator = 'logistics_coordinator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador del sistema',
            self::TraceabilityAdmin => 'Administrador de trazabilidad',
            self::ProductionSupervisor => 'Supervisor de producción',
            self::Quality => 'Calidad',
            self::ProductionOperator => 'Operario de producción',
            self::ProductionSkater => 'Patinador de producción',
            self::LogisticsOperator => 'Operario logístico',
            self::LogisticsCoordinator => 'Coordinador logístico',
            self::Viewer => 'Consulta',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::TraceabilityAdmin => [
                Permission::PalletsView,
                Permission::PalletsOperate,
                Permission::BoxesVoid,
                Permission::PalletsPalletize,
                Permission::PalletsLabel,
                Permission::PalletsReprint,
                Permission::BoxLabelsPrint,
                Permission::BoxLabelsChangeLot,
                Permission::OrdersView,
                Permission::ScansReprocess,
                Permission::ReceiptsRequest,
                Permission::ReceiptsReconcile,
                Permission::DiscrepanciesResolve,
                Permission::TransfersReconcile,
                Permission::OpsAlerts,
                Permission::TraceView,
            ],
            // Sees the discrepancies and enables a new blind count; follows the orders and pallets;
            // generates box labels and may change their lot; keeps the list of reasons
            // for closing an incomplete pallet.
            self::ProductionSupervisor => [
                Permission::DashboardKpis,
                Permission::CloseReasonsManage,
                Permission::OrdersUnlockReceipts,
                Permission::BoxLabelsPrint,
                Permission::BoxLabelsChangeLot,
                Permission::DiscrepanciesResolve,
                Permission::OrdersView,
                Permission::PalletsView,
                Permission::TraceView,
            ],
            // Holds pallets before the count and sees their quantities to decide.
            self::Quality => [
                Permission::QualityManage,
                Permission::PalletsView,
                Permission::TraceView,
            ],
            self::ProductionOperator => [
                Permission::PalletsOperate,
                Permission::BoxesVoid,
                Permission::PalletsPalletize,
                Permission::PalletsLabel,
                Permission::PalletsReprint,
                Permission::BoxLabelsPrint,
            ],
            // Takes closed pallets from the line: palletizes, labels them and requests the production receipt.
            // Watches the pallets in stacking but does not read boxes or close pallets.
            self::ProductionSkater => [
                Permission::PalletsSkate,
                Permission::PalletsView,
                Permission::PalletsPalletize,
                Permission::PalletsLabel,
                Permission::PalletsReprint,
                Permission::ReceiptsRequest,
                Permission::ReceiptsReconcile,
                Permission::TraceView,
            ],
            // No pallets.view: the board shows box counts, which would break the blind count.
            self::LogisticsOperator => [
                Permission::AuditsCount,
            ],
            // Counts like the logistics operator and, unlike them, follows the OF board and the pallets
            // (which show box counts: a trusted role, and the count records who did it).
            self::LogisticsCoordinator => [
                Permission::AuditsCount,
                Permission::OrdersView,
                Permission::PalletsView,
            ],
            self::Viewer => [
                Permission::PalletsView,
                Permission::TraceView,
            ],
        };
    }
}

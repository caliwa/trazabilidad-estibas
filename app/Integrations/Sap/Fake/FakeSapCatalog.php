<?php

namespace App\Integrations\Sap\Fake;

use App\Domain\BoxLabels\Itf14;
use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapProductionOrder;
use App\Integrations\Sap\Enums\ProductionOrderStatus;
use Carbon\CarbonImmutable;

/**
 * Master data the simulator answers with. The item codes and descriptions
 * are the real finished products (from the SAP item list the business
 * shared). The box barcodes (EAN-14 / GTIN-14) are NOT real: indicator 1
 * and the 200 prefix, reserved for internal use, so they can never be
 * mistaken for a real code.
 * The production orders are examples modelled on order 33128.
 */
final class FakeSapCatalog
{
    /** @var array<string, string> */
    public const ITEMS = [
        'PT1350002' => 'GELATINA AZUCARADA BOOM LIMON 1000 GR',
        'PT1350003' => 'GELATINA AZUCARADA BOOM MORA 1000 GR',
        'PT1350004' => 'GELATINA AZUCARADA BOOM FRESA 1000 GR',
        'PT1350005' => 'GELATINA AZUCARADA BOOM UVA 1000 GR',
        'PT1350006' => 'GELATINA AZUCARADA BOOM PIÑA 1000 GR',
        'PT1350007' => 'GELATINA AZUCARADA ARO NARANJA 1000 GR',
        'PT1350008' => 'GELATINA AZUCARADA ARO LIMON 1000 GR',
        'PT1350009' => 'GELATINA AZUCARADA ARO MORA 1000 GR',
        'PT1350010' => 'GELATINA AZUCARADA ARO FRESA 1000 GR',
        'PT1350011' => 'GELATINA AZUCARADA ARO UVA 1000 GR',
        'PT1350012' => 'GELATINA AZUCARADA ARO PIÑA 1000 GR',
        'PT1350013' => 'GELATINA AZUCARADA ALKOSTO NARANJA 1000 GR',
        'PT1350014' => 'GELATINA AZUCARADA ALKOSTO LIMON 1000 GR',
        'PT1350015' => 'GELATINA AZUCARADA ALKOSTO MORA 1000 GR',
        'PT1350016' => 'GELATINA AZUCARADA ALKOSTO FRESA 1000 GR',
        'PT1350017' => 'GELATINA AZUCARADA ALKOSTO UVA 1000 GR',
        'PT1350018' => 'GELATINA AZUCARADA ALKOSTO PIÑA 1000 GR',
        'PT1350019' => 'GELATINA ALKOSTO SIN SABOR 30 GR',
        'PT1350020' => 'GELATINA BOOM SIN SABOR 500 GR',
        'PT1350021' => 'GELATINA ALKOSTO SIN SABOR 500 GR',
        'PT1350022' => 'GELATINA AZUCARADA BOOM CEREZA 1000 GR',
        'PT1350023' => 'GELATINA OLIMPICA SIN SABOR 30 GR',
        'PT1350024' => 'GELATINA BAJA EN CALORIAS ALKOSTO FRESA 50 GR',
        'PT1350025' => 'GELATINA BAJA EN CALORIAS ALKOSTO MORA 50 GR',
        'PT1350026' => 'GELATINA BAJA EN CALORIAS ALKOSTO NARANJA 50 GR',
        'PT1350027' => 'GELATINA BAJA EN CALORIAS ALKOSTO PIÑA 50 GR',
        'PT1350028' => 'GELATINA BAJA EN CALORIAS ALKOSTO UVA 50 GR',
        'PT1350029' => 'GELATINA AZUCARADA BOOM FRAMBUESA 1000 GR',
        'PT1350030' => 'GELATINA MERCALDAS SIN SABOR 30 GR',
        'PT1350031' => 'GELATINA ALKOSTO SIN SABOR 30 GR PAQUETE X 3',
        'PT1350032' => 'GELATINA MERCACENTRO SIN SABOR 30 GR',
        'PT1350033' => 'GELATINA LIGHT BOOM NARANJA 1000 GR',
        'PT1350034' => 'GELATINA LIGHT BOOM MORA 1000 GR',
        'PT1350035' => 'GELATINA LIGHT BOOM LIMON 1000 GR',
        'PT1350036' => 'GELATINA LIGHT BOOM FRESA 1000 GR',
        'PT1350037' => 'GELATINA BAJA EN CALORIAS MERCACENTRO LIMON 2000 GR',
        'PT1350038' => 'GELATINA BAJA EN CALORIAS MERCACENTRO MORA 2000 GR',
        'PT1350039' => 'GELATINA BAJA EN CALORIAS MERCACENTRO FRESA 2000 GR',
        'PT1350040' => 'GELATINA ABURRA SIN SABOR 30 GR',
        'PT1350041' => 'GELATINA AZUCARADA SAN VALENTIN MORA 1000 GR',
    ];

    /**
     * A 14-digit box code (GTIN-14) with a valid check digit, in the internal-use range.
     */
    public static function barcode(string $itemCode): string
    {
        $body = '1200'.str_pad(substr(preg_replace('/\D/', '', $itemCode) ?? '', -9), 9, '0', STR_PAD_LEFT);

        return $body.Itf14::checkDigit($body);
    }

    /**
     * @return array<string, SapProductionOrder>
     */
    public static function productionOrders(): array
    {
        $order = fn (int $docNum, ProductionOrderStatus $status, string $item, string $planned, string $start, string $due) => new SapProductionOrder(
            docEntry: $docNum + 1000,
            docNum: (string) $docNum,
            type: 'Estándar',
            status: $status,
            itemCode: $item,
            itemDescription: self::ITEMS[$item],
            plannedQuantity: new Quantity($planned),
            uomName: 'UNIDAD',
            warehouse: '04',
            priority: 100,
            orderDate: CarbonImmutable::parse($start),
            startDate: CarbonImmutable::parse($start),
            dueDate: CarbonImmutable::parse($due),
            userName: 'Aux Producción 1',
            origin: 'Manual',
            linkedTo: 'Pedido de cliente',
            itemBarcode: self::barcode($item),
            itemInventoryUom: 'UNIDAD',
        );

        $orders = [
            $order(33128, ProductionOrderStatus::Released, 'PT1350023', '800', '2026-02-17', '2026-02-24'),
            $order(33129, ProductionOrderStatus::Planned, 'PT1350019', '500', '2026-02-18', '2026-02-25'),
            $order(33130, ProductionOrderStatus::Closed, 'PT1350002', '300', '2026-02-10', '2026-02-12'),
            $order(33131, ProductionOrderStatus::Released, 'PT1350019', '600', '2026-02-19', '2026-02-26'),
            $order(33132, ProductionOrderStatus::Released, 'PT1350002', '400', '2026-02-20', '2026-02-27'),
            $order(33133, ProductionOrderStatus::Cancelled, 'PT1350030', '200', '2026-02-20', '2026-02-27'),
        ];

        return array_combine(array_map(fn (SapProductionOrder $o) => $o->docNum, $orders), $orders);
    }
}

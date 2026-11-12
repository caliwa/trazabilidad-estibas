<?php

namespace Tests\Unit\Domain\BoxLabels;

use App\Domain\BoxLabels\BoxLabelPayload;
use App\Domain\BoxLabels\BoxLabelZpl;
use App\Domain\BoxLabels\BoxLabelZplOverride;
use App\Domain\BoxLabels\BoxQr;
use App\Models\BoxLabelBatch;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BoxLabelZplOverrideTest extends TestCase
{
    private int $testBatchId = 999888777;

    protected function tearDown(): void
    {
        BoxLabelZplOverride::clear($this->testBatchId);

        parent::tearDown();
    }

    public function test_renders_custom_extra_text_and_units_line_in_zpl(): void
    {
        $payload = new BoxLabelPayload(
            orderRef: '35176',
            itemCode: 'PT1350575-EXP',
            itemDescription: 'GELATINA BAJA EN CALORIAS KEDELI FRUTOS ROJOS 48G (PROMO)',
            unitsPerBox: '24',
            uomName: 'UNIDAD',
            lotCode: '35176315',
            barcode: '17700304357304',
            expirationDate: CarbonImmutable::parse('2028-11-11'),
            boxCode: '35176315-0001',
            extraText: 'EXPORTACION ^ SIN ~ DISPLAY',
            unitsText: 'CAJA : 24 DISPLAYS',
        );

        $zpl = BoxLabelZpl::render($payload, CarbonImmutable::parse('2026-11-11'), BoxQr::payload(str_repeat('a', 32)));

        $this->assertStringContainsString('^FDPT1350575-EXP^FS', $zpl);
        $this->assertStringContainsString('^FDCAJA : 24 DISPLAYS^FS', $zpl);
        $this->assertStringContainsString('^FDEXPORTACION   SIN   DISPLAY^FS', $zpl);
        $this->assertStringNotContainsString('CAJA : 24 UNIDADES', $zpl);
    }

    public function test_stores_resolves_and_clears_batch_override_without_mutating_batch(): void
    {
        $batch = new BoxLabelBatch;
        $batch->id = $this->testBatchId;
        $batch->zpl = '^XA^FDORIGINAL^FS^XZ';

        $this->assertSame('^XA^FDORIGINAL^FS^XZ', BoxLabelZplOverride::resolveZpl($batch));
        $this->assertNull(BoxLabelZplOverride::get($this->testBatchId));

        BoxLabelZplOverride::save(
            $this->testBatchId,
            'PT1350575',
            'GELATINA ESPECIAL (PROMO)',
            'CAJA : 24 UNIDADES',
            'SOLO EXPORTACION',
            '^XA^FDMODIFICADA^FS^XZ',
        );

        $this->assertSame('^XA^FDMODIFICADA^FS^XZ', BoxLabelZplOverride::resolveZpl($batch));
        $saved = BoxLabelZplOverride::get($this->testBatchId);
        $this->assertNotNull($saved);
        $this->assertSame('SOLO EXPORTACION', $saved['extra_text']);
        $this->assertSame('GELATINA ESPECIAL (PROMO)', $saved['item_description']);

        BoxLabelZplOverride::clear($this->testBatchId);

        $this->assertNull(BoxLabelZplOverride::get($this->testBatchId));
        $this->assertSame('^XA^FDORIGINAL^FS^XZ', BoxLabelZplOverride::resolveZpl($batch));
    }
}

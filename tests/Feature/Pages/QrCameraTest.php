<?php

namespace Tests\Feature\Pages;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The QR of the pallet can be read with the phone camera (any browser,
 * window.QrCamera) when confirming the label at the station, in the blind
 * count, in quality and when reading the boxes at the station.
 */
class QrCameraTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_the_pages_let_the_application_use_the_camera_and_nothing_else(): void
    {
        $response = $this->actingAs($this->userWithRole(Role::ProductionOperator))->get(route('production.station'))->assertOk();

        $this->assertSame('camera=(self), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_the_station_offers_the_camera_for_boxes_and_to_confirm_the_label(): void
    {
        $html = (string) $this->actingAs($this->userWithRole(Role::TraceabilityAdmin))->get(route('production.station'))->assertOk()->getContent();

        $this->assertStringContainsString('Etiqueta de la caja', $html);
        // The box scanner, the "Confirmar etiqueta pegada" scanner and the "Confirmar etiqueta aplicada" dialog.
        $this->assertSame(3, substr_count($html, 'data-qr-camera'));
        $this->assertStringContainsString('Leer con la cámara', $html);
    }

    public function test_the_blind_count_and_quality_offer_the_camera(): void
    {
        $count = (string) $this->actingAs($this->userWithRole(Role::LogisticsOperator))->get(route('cedi.count'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($count, 'data-qr-camera'));

        $quality = (string) $this->actingAs($this->userWithRole(Role::Quality))->get(route('quality.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($quality, 'data-qr-camera'));
    }
}

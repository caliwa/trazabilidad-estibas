# Informe de cumplimiento

Fecha: 20 de octubre de 2026. Versión: rama `main` más la Fase 7.

Cada requisito apunta a pruebas con nombre `Clase::metodo`. La prueba `ComplianceReportTest` lee este archivo y falla si alguna de esas pruebas no existe, así el informe no se queda viejo sin que nadie lo note.

## 1. Estado en una tabla

El prompt pide distinguir cuatro niveles. Este es el estado real:

| Nivel | Estado |
|---|---|
| **Terminado funcionalmente** | Sí, para todo lo que no depende de una decisión pendiente. Lo que depende de DP-01, DP-03, DP-04 y DP-05 está implementado y **apagado**. DP-08 no está implementado |
| **Probado en entorno simulado** | Sí. 289 pruebas contra PostgreSQL 16 real y el simulador de SAP (`FakeSapGateway`), incluidas pruebas con procesos paralelos |
| **Probado en integración** | No. No existe el conector real de Service Layer ni un SAP de pruebas (DP-09). Tampoco se probó con sensores, lectores ni impresoras reales |
| **Validado en SAP real** | No |

**No está listo para producción.** Faltan las decisiones de la sección 9, el conector SAP real y una prueba en planta. `php artisan app:preflight` falla a propósito mientras `SAP_DRIVER=fake`.

## 2. Requisitos funcionales

| ID | Requisito | Pruebas |
|---|---|---|
| RF-01 | Recibir eventos de sensores | `ScanEventApiTest::test_an_accepted_box_answers_201_with_the_pallet`, `ScanEventApiTest::test_unknown_wrong_and_inactive_devices_get_401` |
| RF-02 | Validar producto, lote y orden | `ProcessScanEventTest::test_every_master_problem_has_its_own_reason` |
| RF-03 | Crear estibas automáticamente | `ProcessScanEventTest::test_the_first_scan_creates_a_pallet_with_the_standard_frozen` |
| RF-04 | Acumular cajas con eventos únicos | `ProcessScanEventTest::test_each_valid_scan_adds_exactly_one_box`, `ProcessScanEventTest::test_a_repeated_event_is_not_counted_again` |
| RF-05 | Finalizar al llegar al estándar | `ProcessScanEventTest::test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` |
| RF-06 | Cierre manual | `CloseManuallyTest::test_it_closes_with_the_real_count_and_records_who_and_why` |
| RF-07 | Confirmar paletizado | `PalletizeAndLabelTest::test_a_finished_pallet_is_palletized_with_who_and_when` |
| RF-08 | Etiqueta con QR, impresión y reimpresión | `PalletizeAndLabelTest::test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity`, `PalletDetailAndLabelTest::test_the_label_shows_what_the_floor_needs_and_a_qr_with_only_the_token` |
| RF-09 | Confirmar etiqueta aplicada (DP-03, apagado) | `PalletizeAndLabelTest::test_label_confirmation_is_refused_while_dp03_is_open`, `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it` |
| RF-10 | Tablero administrativo | `PalletBoardTest::test_every_filter_narrows_the_list`, `PalletBoardTest::test_indicators_count_each_stage` |
| RF-11 | Recibos de producción en SAP | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| RF-12 | Conciliar recibos inciertos | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create` |
| RF-13 | Escanear QR en CEDI | `BlindCountTest::test_only_received_and_not_transferred_pallets_are_counted` |
| RF-14 | Validar identidad y estado en el servidor | ídem, más `BlindCountSecrecyTest::test_the_count_in_progress_cannot_be_swapped_from_the_browser` |
| RF-15 | Conteo ciego | `BlindCountTest::test_a_matching_count_approves_the_audit_and_readies_the_transfer` |
| RF-16 | Discrepancia bloquea el traslado | `BlindCountTest::test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter`, `InventoryTransferFlowTest::test_a_discrepancy_never_starts_a_transfer` |
| RF-17 | Novedades para revisión | `DiscrepanciesPageTest::test_admins_see_counted_and_system_quantities_and_the_pending_policy` |
| RF-18 | Traslado 04 → 05 | `InventoryTransferFlowTest::test_an_audited_pallet_moves_from_04_to_05_with_sap_references_and_links_its_audit` |
| RF-19 | Individual y agrupado (agrupado: DP-05, apagado) | `InventoryTransferFlowTest::test_grouping_needs_dp05_and_an_explicit_confirmation`, `InventoryTransferFlowTest::test_a_grouped_transfer_keeps_each_pallet_linked_to_its_line` |
| RF-20 | Conciliar traslados inciertos | `InventoryTransferFlowTest::test_a_lost_answer_is_reconciled_without_a_second_create` |
| RF-21 | Trazabilidad estiba → auditoría → documento → línea | `InventoryTransferFlowTest::test_the_full_chain_is_traceable_from_the_pallet` |

## 3. Reglas de negocio

| ID | Regla | Pruebas |
|---|---|---|
| RN-01 | Una estiba = una orden, un producto, un lote; identidad inmutable | `PalletInvariantsTest::test_identity_is_immutable`, `ProcessScanEventTest::test_orders_items_and_lots_never_mix` |
| RN-02 | Un evento cuenta una vez | `ScanEventInvariantsTest::test_an_event_id_is_unique_per_device`, `ScanConcurrencyTest::test_the_same_events_sent_by_many_workers_count_once` |
| RN-03 | Sin identificador confiable no hay producción (DP-01) | `ProcessScanEventTest::test_nothing_is_processed_while_dp01_is_open`, `ScanEventApiTest::test_ingestion_answers_503_while_dp01_is_open` |
| RN-04 | Estándar vigente y positivo | `MasterInvariantsTest::test_current_values_must_be_positive_and_invalid_rows_say_why`, `ProcessScanEventTest::test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason` |
| RN-05 | Estándar congelado | `PalletInvariantsTest::test_a_later_standard_change_does_not_touch_existing_pallets`, `ProcessScanEventTest::test_a_new_standard_applies_to_new_pallets_only` |
| RN-06 | Consecutivo único, sin huecos | `PalletInvariantsTest::test_pallet_codes_are_unique`, `ProcessScanEventTest::test_a_rolled_back_pallet_gives_its_number_back`, `ScanConcurrencyTest::test_parallel_combinations_never_mix_and_share_one_gapless_sequence` |
| RN-07 | Nada confirmado sin verificar en SAP | `SapDocumentInvariantsTest::test_confirmation_requires_doc_entry_and_doc_num`, `ProductionReceiptFlowTest::test_a_document_that_does_not_match_the_request_is_never_confirmed_nor_resent` |
| RN-08 | No recibir dos veces | `SapDocumentInvariantsTest::test_a_pallet_cannot_be_held_by_two_receipts`, `ReceiptConcurrencyTest::test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once` |
| RN-09 | No trasladar dos veces | `SapDocumentInvariantsTest::test_a_pallet_cannot_be_held_by_two_transfers_and_warehouses_must_differ`, `TransferConcurrencyTest::test_parallel_transfer_requests_for_the_same_pallets_hold_each_pallet_once` |
| RN-10 | Dimensiones de estado independientes | `PalletInvariantsTest::test_each_dimension_needs_the_previous_process` |
| RN-11 | SAP es la fuente del inventario | No hay ajustes locales. `BlindCountTest::test_closing_a_case_needs_an_approved_action_a_different_person_and_never_adjusts_anything` |
| RN-12 | Concurrencia protegida | `ScanConcurrencyTest::test_parallel_scans_of_one_combination_fill_pallets_exactly`, `ScanConcurrencyTest::test_manual_closes_racing_scans_never_lose_or_overcount_boxes`, `ReceiptConcurrencyTest::test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once`, `TransferConcurrencyTest::test_parallel_transfer_requests_for_the_same_pallets_hold_each_pallet_once` |
| RN-13 | Operaciones críticas auditadas | `TraceEventsTest::test_the_logger_records_actor_subject_states_and_correlation`, `TraceEventsTest::test_traces_cannot_be_updated_deleted_or_truncated` |
| RN-14 | Discrepancias sin ajuste automático | `BlindCountTest::test_closing_a_case_needs_an_approved_action_a_different_person_and_never_adjusts_anything` |
| RN-15 | No retroceder estados confirmados | `PalletInvariantsTest::test_confirmed_states_cannot_go_back`, `StateTransitionsMatchDatabaseTest::test_enum_and_database_agree_on_every_pair` |
| RN-16 | Sin secretos en logs | `RedactorTest::test_secret_keys_are_masked_at_any_depth_and_the_rest_is_kept`, `TraceEventsTest::test_context_and_log_lines_never_carry_secrets`, `HardeningTest::test_log_lines_are_json_with_the_correlation_id_and_no_secrets` |
| RN-17 | Nada pendiente por suposición | `SapGatewayBindingTest::test_the_real_connector_does_not_exist_until_sap_is_verified`, `BlindCountTest::test_a_discrepancy_blocks_new_counts_until_dp04_allows_a_reaudit`, `HealthAndPreflightTest::test_preflight_fails_on_an_unsafe_configuration` |
| RN-18 | Toda regla con prueba | Este informe y `ComplianceReportTest::test_every_test_cited_in_the_report_exists` |

## 4. Criterios de aceptación

Los textos de CA-01 a CA-18 los propuse en la Fase 0 porque el prompt no los trae. Si existe un documento oficial, hay que ajustar esta tabla.

| ID | Criterio | Pruebas | Resultado |
|---|---|---|---|
| CA-01 | El primer evento crea la estiba con el estándar congelado | `ProcessScanEventTest::test_the_first_scan_creates_a_pallet_with_the_standard_frozen` | Pasa |
| CA-02 | Cada evento suma una caja; un duplicado no | `ProcessScanEventTest::test_each_valid_scan_adds_exactly_one_box`, `ScanEventApiTest::test_a_resend_answers_200_and_changes_nothing` | Pasa |
| CA-03 | Al estándar se finaliza una vez y el siguiente abre otra | `ProcessScanEventTest::test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` | Pasa |
| CA-04 | Eventos concurrentes: una estiba activa y suma exacta | `ScanConcurrencyTest::test_parallel_scans_of_one_combination_fill_pallets_exactly` | Pasa |
| CA-05 | No se mezclan órdenes, productos ni lotes | `ProcessScanEventTest::test_orders_items_and_lots_never_mix`, `ScanConcurrencyTest::test_parallel_combinations_never_mix_and_share_one_gapless_sequence` | Pasa |
| CA-06 | Estándar ausente, inválido o vencido rechaza con causa | `ProcessScanEventTest::test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason`, `ProcessScanEventTest::test_every_master_problem_has_its_own_reason` | Pasa |
| CA-07 | Cierre manual entre 1 y estándar − 1, conserva la cantidad | `CloseManuallyTest::test_it_closes_with_the_real_count_and_records_who_and_why`, `CloseManuallyTest::test_an_empty_pallet_cannot_be_closed`, `PalletInvariantsTest::test_manual_close_needs_between_one_box_and_standard_minus_one_and_who_closed` | Pasa |
| CA-08 | No paletizar sin finalizar ni etiquetar sin paletizar | `PalletizeAndLabelTest::test_a_pallet_in_process_cannot_be_palletized_nor_palletized_twice`, `PalletizeAndLabelTest::test_a_label_needs_a_palletized_pallet` | Pasa |
| CA-09 | Reimpresión sin cambiar identidad ni cantidad, auditada | `PalletizeAndLabelTest::test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity` | Pasa |
| CA-10 | El QR solo identifica | `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it`, `PalletizeAndLabelTest::test_the_qr_carries_only_the_opaque_token` | Pasa |
| CA-11 | Tres estibas agrupadas y separadas, conversión correcta | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references`, `ProductionReceiptFlowTest::test_three_pallets_in_separate_receipts`, `ProductionReceiptFlowTest::test_boxes_convert_with_the_current_factor_and_the_line_keeps_which_one` | Pasa (simulador) |
| CA-12 | Timeout → pendiente, sin segunda creación ciega | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create`, `ProductionReceiptFlowTest::test_a_send_left_hanging_by_a_dead_worker_goes_to_reconciliation_not_to_a_new_send` | Pasa (simulador) |
| CA-13 | La conciliación encuentra el documento y no duplica | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create`, `ProductionReceiptFlowTest::test_a_document_proved_absent_is_resent_only_after_the_grace_period` | Pasa (simulador) |
| CA-14 | Solo se audita con recibo confirmado y sin trasladar | `BlindCountTest::test_only_received_and_not_transferred_pallets_are_counted` | Pasa |
| CA-15 | El operario no obtiene la cantidad por ninguna vía | `BlindCountSecrecyTest::test_the_page_state_and_responses_never_carry_the_expected_quantity`, `BlindCountSecrecyTest::test_the_full_http_page_does_not_carry_it_either`, `BlindCountSecrecyTest::test_no_other_screen_or_endpoint_shows_quantities_to_the_operator`, `BlindCountSecrecyTest::test_the_operator_never_receives_the_discrepancy_notification` | Pasa |
| CA-16 | Conteo distinto: discrepancia, bloqueo, novedad, aviso | `BlindCountTest::test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter` | Pasa |
| CA-17 | Traslado solo de aprobadas, sin duplicados, con relación estiba-documento-línea | `InventoryTransferFlowTest::test_only_audited_and_free_pallets_can_be_transferred`, `InventoryTransferFlowTest::test_a_grouped_transfer_keeps_each_pallet_linked_to_its_line`, `TransferConcurrencyTest::test_parallel_transfer_requests_for_the_same_pallets_hold_each_pallet_once` | Pasa (simulador) |
| CA-18 | Solo una confirmación verificada marca CONFIRMADO_05 | `InventoryTransferFlowTest::test_a_document_that_does_not_match_is_never_confirmed_nor_resent`, `InventoryTransferFlowTest::test_a_rejected_transfer_fails_releases_the_pallet_and_it_can_be_requested_again`, `InventoryTransferFlowTest::test_when_sap_cannot_be_asked_nothing_changes` | Pasa (simulador) |

## 5. Escenarios mínimos de la sección 15

### Producción

| Escenario | Prueba |
|---|---|
| Primer escaneo crea una estiba | `ProcessScanEventTest::test_the_first_scan_creates_a_pallet_with_the_standard_frozen` |
| Cada escaneo válido suma una caja | `ProcessScanEventTest::test_each_valid_scan_adds_exactly_one_box` |
| Alcanzar el estándar finaliza una vez | `ProcessScanEventTest::test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` |
| El escaneo siguiente crea otra | ídem |
| Cierre manual conserva la cantidad | `CloseManuallyTest::test_it_closes_with_the_real_count_and_records_who_and_why` |
| No se cierra una estiba vacía | `CloseManuallyTest::test_an_empty_pallet_cannot_be_closed` |
| No se supera el estándar | `PalletInvariantsTest::test_boxes_never_exceed_the_frozen_standard_nor_go_negative` |
| Duplicados no suman | `ProcessScanEventTest::test_a_repeated_event_is_not_counted_again` |
| Concurrencia sin duplicados ni sobreconteo | `ScanConcurrencyTest::test_parallel_scans_of_one_combination_fill_pallets_exactly` |
| No se mezclan órdenes, productos ni lotes | `ProcessScanEventTest::test_orders_items_and_lots_never_mix` |
| Estándar ausente bloquea | `ProcessScanEventTest::test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason` |
| Cambio de estándar no altera estibas | `ProcessScanEventTest::test_a_new_standard_applies_to_new_pallets_only` |

### Paletizado y etiquetado

| Escenario | Prueba |
|---|---|
| No se paletiza en proceso | `PalletizeAndLabelTest::test_a_pallet_in_process_cannot_be_palletized_nor_palletized_twice` |
| No se etiqueta sin paletizar | `PalletizeAndLabelTest::test_a_label_needs_a_palletized_pallet` |
| Reimpresión conserva identidad y cantidad | `PalletizeAndLabelTest::test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity` |
| El QR no evade validaciones | `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it` |
| Confirmaciones físicas auditadas | `PalletizeAndLabelTest::test_a_finished_pallet_is_palletized_with_who_and_when` |

### Recibos SAP

| Escenario | Prueba |
|---|---|
| Tres estibas agrupadas | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| Tres estibas separadas | `ProductionReceiptFlowTest::test_three_pallets_in_separate_receipts` |
| No se reutilizan estibas recibidas | `ProductionReceiptFlowTest::test_a_received_or_held_pallet_cannot_be_requested_again` |
| Factor de conversión correcto | `ProductionReceiptFlowTest::test_boxes_convert_with_the_current_factor_and_the_line_keeps_which_one` |
| DocEntry y DocNum tras verificar | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| Timeout sin segunda creación ciega | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create` |
| Conciliación encuentra documentos | ídem |
| Orden parcialmente recibida sujeta a SAP | `ProductionReceiptFlowTest::test_successive_partial_receipts_of_one_order`. **Parcial:** se revisa la orden contra la copia local; el estado real en SAP depende de DP-06 y DP-09 |

### Auditoría logística

| Escenario | Prueba |
|---|---|
| No se audita sin recibo confirmado | `BlindCountTest::test_only_received_and_not_transferred_pallets_are_counted` |
| No se audita una estiba trasladada | ídem |
| El operario no recibe la cantidad | `BlindCountSecrecyTest::test_the_page_state_and_responses_never_carry_the_expected_quantity` |
| Conteo diferente genera discrepancia | `BlindCountTest::test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter` |
| La discrepancia bloquea el traslado | ídem, más `InventoryTransferFlowTest::test_a_discrepancy_never_starts_a_transfer` |
| Conteo correcto habilita el traslado | `BlindCountTest::test_a_matching_count_approves_the_audit_and_readies_the_transfer` |
| Permisos bloquean la resolución | `BlindCountTest::test_people_without_the_permissions_are_refused_on_the_server` |

### Traslados SAP

| Escenario | Prueba |
|---|---|
| Traslado 04 → 05 | `InventoryTransferFlowTest::test_an_audited_pallet_moves_from_04_to_05_with_sap_references_and_links_its_audit` |
| No se trasladan no auditadas | `InventoryTransferFlowTest::test_only_audited_and_free_pallets_can_be_transferred` |
| No se duplican traslados | `InventoryTransferFlowTest::test_a_pallet_held_by_a_pending_transfer_cannot_be_requested_again`, `InventoryTransferFlowTest::test_running_the_send_twice_sends_once` |
| El agrupado conserva estiba-documento-línea | `InventoryTransferFlowTest::test_a_grouped_transfer_keeps_each_pallet_linked_to_its_line` |
| Errores y timeouts mantienen el estado real | `InventoryTransferFlowTest::test_a_rejected_transfer_fails_releases_the_pallet_and_it_can_be_requested_again`, `InventoryTransferFlowTest::test_when_sap_cannot_be_asked_nothing_changes` |
| La conciliación evita duplicados | `InventoryTransferFlowTest::test_a_lost_answer_is_reconciled_without_a_second_create` |
| Solo la confirmación verificada marca trasladada | `InventoryTransferFlowTest::test_a_document_that_does_not_match_is_never_confirmed_nor_resent` |

### Seguridad

| Escenario | Prueba |
|---|---|
| Acceso no autorizado a acciones críticas | `CloseManuallyTest::test_people_without_the_permission_are_refused_on_the_server`, `PalletizeAndLabelTest::test_operators_without_permission_are_refused_on_the_server`, `InventoryTransferFlowTest::test_only_people_with_the_permission_request_transfers`, `ProductionReceiptFlowTest::test_only_people_with_the_permission_request_receipts` |
| Manipulación de propiedades Livewire | `ProductionStationTest::test_the_pallet_being_closed_cannot_be_swapped_from_the_browser`, `BlindCountSecrecyTest::test_the_count_in_progress_cannot_be_swapped_from_the_browser` |
| Acceso cruzado a estibas de otros procesos o usuarios | `BlindCountTest::test_a_count_belongs_to_whoever_started_it`, `PalletDetailAndLabelTest::test_a_print_that_does_not_exist_or_belongs_to_another_pallet_is_404_and_access_needs_permission`, `NotificationBellTest::test_people_only_open_their_own_notifications` |
| Consultar cantidades durante el conteo ciego | `BlindCountSecrecyTest::test_no_other_screen_or_endpoint_shows_quantities_to_the_operator` |
| Repetición de solicitudes HTTP | `ScanEventApiTest::test_a_resend_answers_200_and_changes_nothing`, `InventoryTransferFlowTest::test_a_pallet_held_by_a_pending_transfer_cannot_be_requested_again`, `ProductionReceiptFlowTest::test_running_the_send_twice_sends_once` |
| Manipulación de QR | `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it` |
| Credenciales en logs | `TraceEventsTest::test_context_and_log_lines_never_carry_secrets`, `HardeningTest::test_log_lines_are_json_with_the_correlation_id_and_no_secrets` |
| Recibir o trasladar la misma estiba a la vez | `ReceiptConcurrencyTest::test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once`, `TransferConcurrencyTest::test_parallel_transfer_requests_for_the_same_pallets_hold_each_pallet_once` |

## 6. Seguridad (sección 12)

| Control | Cómo | Evidencia |
|---|---|---|
| Autorización en el servidor | Permisos granulares por caso de uso (`Gate::authorize`) y por página (`boot()`) | Pruebas de la sección 5, Seguridad |
| CSRF | Middleware de Laravel en todas las rutas web; Livewire lo incluye en cada petición | Configuración del framework. Las pruebas lo omiten por diseño de Laravel; no tiene prueba propia |
| Validación estricta | Form Requests en la API, `validate()` en Livewire, CHECK en la base | `ScanEventApiTest::test_malformed_requests_are_refused_without_storing_anything` |
| Asignación masiva | Modelos de dominio sin `fillable`; los casos de uso usan `forceFill` con campos explícitos | `HardeningTest::test_domain_models_ignore_mass_assignment` |
| Acceso por objeto | Conteo de quien lo empezó, impresiones de su estiba, notificaciones propias | Sección 5, acceso cruzado |
| Rate limiting de sensores | `throttle:scan-events` por dispositivo | `ScanEventApiTest::test_each_device_is_rate_limited` |
| Autenticación de dispositivos | Token por dispositivo, guardado como hash, rotación | `DeviceCommandsTest::test_a_registered_device_gets_a_token_once_and_only_its_hash_is_stored`, `DeviceCommandsTest::test_rotating_the_token_invalidates_the_old_one` |
| TLS | Nginx solo TLS 1.2/1.3, redirección, HSTS | `deploy/nginx/trazabilidad.conf`. Sin prueba automática |
| Secretos | `.env` fuera del repo y de `public/`, enmascarado en logs | `HardeningTest::test_nothing_private_lives_under_the_web_root`, `RedactorTest::test_secret_keys_are_masked_at_any_depth_and_the_rest_is_kept` |
| Cookies y sesión | `SESSION_SECURE_COOKIE`, `http_only`, `same_site`, verificados al desplegar | `HealthAndPreflightTest::test_preflight_fails_on_an_unsafe_configuration` |
| Errores internos ocultos | `APP_DEBUG=false` exigido, `display_errors` bloqueado en el pool | `HardeningTest::test_internal_errors_are_not_exposed_in_html_or_json` |
| Bitácora inmutable | Triggers contra UPDATE, DELETE y TRUNCATE | `TraceEventsTest::test_traces_cannot_be_updated_deleted_or_truncated` |
| Respaldo y recuperación | `deploy/scripts/backup.sh`, procedimiento en `docs/despliegue.md` y `docs/operacion.md` | Script verificado con `bash -n`; no ejecutado contra un servidor |
| Dependencias | `composer audit`: sin avisos. `npm audit`: se corrigieron `shell-quote` y `source-map-js`; queda `tinypool` dentro de `vite-plus` 0.3.0 | Solo se usa al compilar assets; no llega al servidor ni al navegador |

## 7. Auditoría y observabilidad (sección 13)

| Requisito | Cómo |
|---|---|
| Usuario o dispositivo, fecha, evento, entidad, estados, motivo, origen, correlación, resultado, referencias SAP | Columnas de `trace_events`, `TraceEventsTest::test_the_logger_records_actor_subject_states_and_correlation` |
| Intentos y errores normalizados, latencias | `integration_attempts`, `ProductionReceiptFlowTest::test_every_call_to_sap_is_recorded` |
| Logs estructurados con correlación | JSON por línea con `correlation_id`, `HardeningTest::test_log_lines_are_json_with_the_correlation_id_and_no_secrets`, `CorrelationIdTest::test_every_response_carries_a_correlation_id` |
| Alerta: eventos rechazados | `OperationalChecksTest::test_rejected_scans_above_the_threshold_are_reported` |
| Alerta: duplicados anormales | `OperationalChecksTest::test_abnormal_duplicates_are_reported` |
| Alerta: estándares ausentes | `OperationalChecksTest::test_missing_standards_are_critical_and_open_orders_without_one_are_flagged` |
| Alerta: errores SAP, pendientes de conciliación | `OperationalChecksTest::test_sap_errors_pending_reconciliations_and_mismatches_are_reported` |
| Alerta: discrepancias abiertas | `OperationalChecksTest::test_open_discrepancies_are_reported_without_quantities` |
| Alerta: cola acumulada | `OperationalChecksTest::test_queue_backlog_and_failed_jobs_are_reported` |
| Alerta: fallos de workers | `OperationalChecksTest::test_a_dead_scheduler_or_worker_is_critical`, `HealthAndPreflightTest::test_health_is_ok_only_when_database_scheduler_and_workers_are_alive` |
| Aviso a responsables sin repetir | `OperationalChecksTest::test_the_check_notifies_ops_people_once_per_cooldown_and_never_the_operators` |

## 8. Despliegue (sección 16)

Todo en `docs/despliegue.md` y `deploy/`:

| Tema | Dónde |
|---|---|
| Requisitos, dependencias, extensiones PHP | Secciones 1 y 5 |
| Variables de entorno | Sección 4 |
| Migraciones | Secciones 7 y 10 (regla de compatibilidad hacia atrás) |
| Usuario de sistema y permisos mínimos | Sección 2 |
| Nginx y TLS | Sección 6, `deploy/nginx/trazabilidad.conf` |
| PHP-FPM | Sección 5, `deploy/php/` |
| Colas y supervisión de workers | Sección 8, `deploy/supervisor/trazabilidad.conf` |
| Scheduler | Sección 9, `deploy/cron/trazabilidad` |
| Reinicio seguro | Sección 15 |
| Logs | Sección 12 |
| Respaldos y restauración | Sección 13, `deploy/scripts/backup.sh` |
| Actualizaciones y rollback sin perder trabajos | Secciones 10 y 11, `deploy/scripts/deploy.sh`, `deploy/scripts/rollback.sh` |
| Health checks | Sección 14, `/up` y `/api/health` |
| Conciliación operativa | `docs/operacion.md` |

**No probado en un servidor.** Las configuraciones y scripts se revisaron a mano y con `bash -n`, pero esta sesión no tiene un servidor Linux con Nginx y Supervisor. Ninguna prueba automática los ejecuta.

## 9. Decisiones pendientes

| DP | Tema | Estado en el código | Bloquea producción |
|---|---|---|---|
| DP-01 | Identificador del sensor y eventos tardíos | Motor completo, `TRACE_SCAN_INGESTION_ENABLED=false` | Sí: sin esto no entra producción |
| DP-02 | Fuente de órdenes, lotes y factores | Tablas y validación; sin sincronización con SAP | Sí |
| DP-03 | Confirmación de etiqueta aplicada | Tres modos implementados, ninguno activo | Sí: sin esto no hay recibos |
| DP-04 | Resolución de discrepancias | Reauditoría y cierre implementados, apagados | No para arrancar, pero las estibas con discrepancia quedan retenidas |
| DP-05 | Traslado agrupado | Implementado, apagado | No: el individual funciona |
| DP-06 | Cierre de órdenes en SAP con recibos parciales | Se revisa la copia local | Depende de cómo opere SAP |
| DP-07 | Etiqueta física | Etiqueta HTML 100 × 150 mm | Depende de la impresora |
| DP-08 | Reversión de documentos y estibas devueltas | No implementado | Sí, para operar devoluciones |
| DP-09 | Acceso a SAP, correlación, almacenes | Solo simulador | Sí |

## 10. Definición de terminado

| Punto | Estado |
|---|---|
| Requisitos aprobados implementados | Sí, salvo DP-08 y lo que espera DP-01 a DP-05 (implementado y apagado) |
| CA-01 a CA-18 con pruebas satisfactorias | Sí, en simulador. CA-11 a CA-13, CA-17 y CA-18 falta repetirlos contra SAP |
| Reglas transversales con pruebas | Sí (sección 3) |
| Operaciones concurrentes seguras | Sí, con procesos paralelos reales contra PostgreSQL; además se comprobó que las pruebas fallan al quitar los bloqueos |
| SAP con idempotencia y conciliación | Sí, en simulador |
| Estados locales con evidencia de SAP | Sí: confirmación solo después de leer el documento; restricción en la base |
| Conteo ciego protegido en todas las interfaces | Sí (CA-15) |
| Procedimiento autorizado de discrepancias | Implementado; falta aprobarlo (DP-04) |
| Trazabilidad reconstruible | Sí (RF-21) |
| Permisos y auditoría | Sí |
| Despliegue reproducible | Documentado y con scripts; sin ejecutar en un servidor |
| Documentación técnica y operativa | Sí: `docs/fase-0` a `fase-7`, `despliegue.md`, `operacion.md`, este informe |
| Sin decisiones críticas abiertas | **No.** Ver sección 9 |

## 11. Resultados de esta verificación

PHP 8.3.6 y PostgreSQL 16.15, en la sesión de la Fase 7:

```
php artisan test             → 289 pruebas, 289 pasan (dos corridas seguidas)
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
composer audit               → sin avisos
npm audit --omit=dev         → 1 aviso restante (tinypool, herramienta de compilación)
bash -n deploy/scripts/*.sh  → sin errores
```

No ejecutado: compilación de assets (fonts.bunny.net está bloqueado en esta sesión; el CI de GitHub sí compila), pruebas en navegador, despliegue en un servidor, SAP real.

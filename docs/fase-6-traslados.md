# Fase 6: traslados del almacén 04 al 05

## 1. Objetivo

Mover en SAP las estibas que pasaron el conteo ciego, del almacén 04 al 05, con las mismas garantías de los recibos: nunca trasladar dos veces, nunca confirmar sin leer el documento en SAP, y poder seguir cada estiba hasta su línea en el traslado. Igual que en la Fase 4, todo corre contra el simulador (DP-09).

## 2. Cómo funciona

1. **Solicitud** (`RequestInventoryTransfers`).
   - Las estibas se bloquean en orden fijo y se validan una por una: auditoría APROBADA, traslado LISTO o FALLIDO, recibo CONFIRMADO con su línea confirmada.
   - **Individual por defecto:** un documento por estiba.
   - **Agrupado** solo si `TRACE_GROUPED_TRANSFERS_ENABLED=true` (DP-05), con una confirmación explícita y estibas del mismo producto y lote. Sin cualquiera de las tres se rechaza y no se crea nada.
   - Cada línea copia del recibo confirmado las cajas, el factor y la cantidad SAP, y guarda el conteo aprobado (`pallet_audit_id`) que la hizo elegible. Lo que se traslada es exactamente lo que se recibió.
   - El índice único parcial de la Fase 1 impide que una estiba quede en dos traslados activos o confirmados.
   - El job de envío se encola después del commit.
2. **Envío, verificación y conciliación.** Es el mismo ciclo de los recibos, ahora en una clase común (`SapDocumentLifecycle`) que usan `ProductionReceiptLifecycle` e `InventoryTransferLifecycle`:
   - ENVIANDO se guarda antes de llamar a SAP;
   - solo se confirma si el documento leído por correlación coincide (producto, lote, cantidad, almacén destino y almacén origen);
   - timeout o respuesta ambigua → PENDIENTE_CONCILIACION;
   - reenvío solo con ausencia demostrada, después del tiempo de gracia y hasta el límite de intentos;
   - un documento que no coincide queda marcado para revisión y nunca se confirma.
3. **Barrido.** `sap:sweep` ahora recorre recibos y traslados con la misma lógica.
4. **Cierre manual como fallido.** Con `transfers.reconcile`, con motivo, y solo si SAP demuestra que el documento no existe. La estiba vuelve a quedar libre (FALLIDO) para pedirse otra vez.

## 3. Pantalla

| Ruta | Para | Permiso |
|---|---|---|
| `/transfers` | Estibas listas para trasladar, solicitud individual (o agrupada con DP-05), últimos 50 traslados con estado y DocNum, conciliar y marcar fallido | `transfers.request`, y `transfers.reconcile` para las acciones de conciliación |

La pantalla no espera a SAP: guarda la solicitud y el job la envía. El operario logístico no entra (muestra cajas).

## 4. Requisitos cubiertos

| Requisito | Pruebas |
|---|---|
| RF-18 traslado 04 → 05 con DocEntry y DocNum verificados | `test_an_audited_pallet_moves_from_04_to_05_with_sap_references_and_links_its_audit` |
| Solo estibas auditadas y libres; discrepancia bloquea (RF-16) | `test_only_audited_and_free_pallets_can_be_transferred`, `test_a_discrepancy_never_starts_a_transfer` |
| RN-09 no trasladar dos veces | `test_a_pallet_held_by_a_pending_transfer_cannot_be_requested_again`, `TransferConcurrencyTest` (6 procesos, selecciones cruzadas, individual y agrupado mezclados) |
| RF-19 individual por defecto, agrupado solo con DP-05 y confirmación | `test_grouping_needs_dp05_and_an_explicit_confirmation`, `test_grouping_refuses_pallets_of_different_lots`, `test_a_grouped_transfer_keeps_each_pallet_linked_to_its_line`, `TransfersPageTest` |
| Cantidades iguales a lo recibido | `test_what_is_transferred_is_exactly_what_was_received` |
| RF-20 conciliación sin segunda creación | `test_a_lost_answer_is_reconciled_without_a_second_create`, `test_a_transfer_proved_absent_is_resent_only_after_the_grace_period`, `test_when_sap_cannot_be_asked_nothing_changes` |
| Documento distinto nunca se confirma | `test_a_document_that_does_not_match_is_never_confirmed_nor_resent` (almacén origen cambiado) |
| Rechazo libera la estiba | `test_a_rejected_transfer_fails_releases_the_pallet_and_it_can_be_requested_again` |
| Worker muerto y job duplicado | `test_the_sweeper_handles_transfers_too`, `test_running_the_send_twice_sends_once` |
| RF-21 estiba → recibo → auditoría → traslado → línea | `test_the_full_chain_is_traceable_from_the_pallet` |
| Permisos en el servidor | `test_only_people_with_the_permission_request_transfers`, `TransfersPageTest` (5) |

## 5. Decisiones y supuestos

- **Refactor de los recibos.** La lógica de envío y conciliación se movió a `SapDocumentLifecycle` antes de escribir los traslados. Las 41 pruebas de recibos y conteo pasaron sin cambios después del refactor.
- **Traslado automático después del conteo, apagado.** `TRACE_AUTO_TRANSFER_AFTER_AUDIT=true` pide el traslado individual en cuanto un conteo se aprueba (job `StartTransferAfterAudit`, después del commit). La traza queda como acción del sistema; el documento queda a nombre de quien contó. Si alguien ya lo pidió, no hace nada. Por defecto está apagado y el traslado se pide desde la pantalla.
- **DP-05.** Implementé la recomendación de la Fase 0: agrupado manual, con confirmación, mismo producto y lote. No hay límite de estibas por documento; si el negocio quiere uno, va con DP-05.
- **Almacenes.** 04 y 05 salen de `SAP_TRANSFER_FROM_WAREHOUSE` y `SAP_TRANSFER_TO_WAREHOUSE`. Los códigos reales en SAP dependen de DP-09.
- **Comprobé que la prueba de concurrencia detecta el error.** Quité el `lockForUpdate` de la solicitud y la prueba falló en tres corridas de tres. Después restauré el archivo.

## 6. Resultados reales

PHP 8.3.6 y PostgreSQL 16.15, en esta sesión:

```
php artisan test             → 268 pruebas, 268 pasan (dos corridas seguidas)
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
```

La pantalla no se revisó en un navegador: el build de assets sigue sin correr en esta sesión.

## 7. Pendientes

- **DP-05:** confirmar quién agrupa y si hay límite.
- **DP-09:** conector real de Service Layer, campo de correlación en el traslado y códigos de almacén.
- **DP-08:** qué pasa con una estiba ya trasladada que se rechaza o se devuelve. Hoy CONFIRMADO_05 es final.
- **Alertas activas** de traslados pendientes o que no coinciden: Fase 7.

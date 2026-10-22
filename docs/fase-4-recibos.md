# Fase 4: recibos de producción en SAP

## 1. Objetivo

Recibir en el almacén 04 las estibas etiquetadas, sin recibir nunca dos veces ni dar por confirmado algo que SAP no tiene. Todo corre contra el simulador: el conector real de Service Layer no existe hasta tener acceso a SAP (DP-09).

## 2. Cómo funciona

1. **Solicitud** (`RequestProductionReceipts`).
   - Se eligen estibas etiquetadas de una sola orden. Puede ser un recibo con todas o uno por estiba.
   - Dentro de una transacción, las estibas se bloquean en orden fijo y se validan: etiquetadas, sin recibo o con un recibo anterior fallido, orden abierta, factor de conversión vigente.
   - Las cajas se convierten a unidades con el factor del producto (`Quantity`, aritmética exacta) y se agrupan en una línea SAP por producto + lote.
   - **Lotes.** SAP pide el lote de cada línea con sus fechas (pantalla "Lotes: Definir"). Se mandan así:
     - *Lote*: el de las estibas, que es el mismo de las cajas leídas.
     - *Vencimiento*: el de las etiquetas de caja generadas para esa OF y ese lote (`LotDates`). Un lote tiene una sola fecha de vencimiento: si una tanda nueva del mismo lote trae otra fecha, se rechaza al generar las etiquetas.
     - *Fecha de fabricación*: la misma fecha de admisión, que es la fecha de contabilización del recibo.
     - La fecha de contabilización se fija al pedir el recibo (`posting_date` en el payload). Un reenvío después de medianoche sale con la misma fecha.
     - Si un lote no tiene etiquetas de caja, el recibo se rechaza. No se inventa un vencimiento.
   - Se guardan el `request_uuid`, el payload exacto, su hash y una línea ACTIVA por estiba; el índice único parcial impide que una estiba quede en dos solicitudes.
   - El job de envío se encola **después del commit**.
2. **Envío** (`ProductionReceiptLifecycle::send`).
   - Pasa de SOLICITADO a ENVIANDO y lo confirma en la base **antes** de llamar a SAP.
   - Hace la llamada y registra el intento.
3. **Respuesta.**
   - *Creado*: se toma el documento que SAP respondió (o, si la respuesta no lo trae, se lee por su DocEntry) y se compara línea por línea (producto, lote, almacén, cantidad, vencimiento y fabricación del lote), la orden y la fecha de contabilización. Solo si coincide se confirma, guardando DocEntry, DocNum y número de línea por estiba; las estibas pasan a CONFIRMADO_04.
   - *Rechazado*: el recibo queda FALLIDO, las líneas LIBERADAS y las estibas se pueden pedir otra vez.
   - *Timeout o respuesta ambigua*: PENDIENTE_CONCILIACION, sin reintento.
4. **Conciliación** (`reconcile`).
   - Si SAP ya había respondido "creado", se lee por ese DocEntry. Si no, se busca el documento por correlación.
   - Si existe y coincide, se confirma.
   - Si existe pero no coincide, queda pendiente con las diferencias anotadas para que lo vea una persona. Nunca se confirma ni se reenvía.
   - Si SAP demuestra que no existe **y** ya pasaron `SAP_RESEND_AFTER_MINUTES` desde el último envío, se reenvía con el mismo `request_uuid`, hasta `SAP_MAX_SEND_ATTEMPTS`.
   - Si no se pudo consultar, no cambia nada.
5. **Barrido** (`sap:sweep`, cada minuto con el scheduler).
   - Vuelve a encolar las solicitudes cuyo job se perdió.
   - Pasa a conciliación los envíos que un worker dejó colgados en ENVIANDO (nunca los reenvía).
   - Concilia los pendientes cada `SAP_CHECK_EVERY_MINUTES`.
6. **Cierre manual como fallido.** Una persona con `receipts.reconcile` puede cerrar un pendiente como fallido, con motivo. Solo se acepta si SAP demuestra que el documento no existe y ya pasó el tiempo de gracia.

## 3. Requisitos cubiertos

| Requisito | Pruebas |
|---|---|
| Tres estibas en un recibo agrupado | `test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| Tres estibas en recibos separados | `test_three_pallets_in_separate_receipts` |
| No reutilizar estibas recibidas o retenidas (RN-08, RN-09) | `test_a_received_or_held_pallet_cannot_be_requested_again`, `ReceiptConcurrencyTest` (6 procesos con selecciones cruzadas) |
| Conversión con el factor correcto y versionado | `test_boxes_convert_with_the_current_factor_and_the_line_keeps_which_one` |
| DocEntry y DocNum solo después de verificar (RN-07) | `test_three_pallets_in_one_receipt...`, `test_a_document_that_does_not_match_the_request_is_never_confirmed_nor_resent` |
| Un timeout no produce una segunda creación ciega | `test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create` (1 sola escritura en SAP) |
| La conciliación encuentra documentos existentes | ídem |
| Reenvío solo con ausencia demostrada y tiempo de gracia | `test_a_document_proved_absent_is_resent_only_after_the_grace_period`, `test_resending_stops_at_the_attempt_limit` |
| Consulta fallida no es "no existe" | `test_when_sap_cannot_be_asked_nothing_changes_and_nothing_is_resent` |
| Worker muerto a mitad de envío | `test_a_send_left_hanging_by_a_dead_worker_goes_to_reconciliation_not_to_a_new_send` |
| Job encolado solo después del commit; los perdidos se recuperan | `test_the_send_job_is_queued_only_after_commit_and_a_lost_one_is_queued_again_by_the_sweeper` (con la cola de base de datos real) |
| Enviar dos veces el mismo job envía una vez | `test_running_the_send_twice_sends_once` |
| Recepciones parciales sucesivas de una orden | `test_successive_partial_receipts_of_one_order` |
| Rechazo de SAP libera las estibas | `test_a_rejected_receipt_fails_releases_the_pallets_and_they_can_be_requested_again` |
| Cada llamada a SAP queda registrada | `test_every_call_to_sap_is_recorded` |
| Permisos | `test_only_people_with_the_permission_request_receipts`, `ReceiptsPageTest` (5) |
| La interfaz no espera a SAP | `test_requesting_from_the_page_never_waits_for_sap_and_lists_the_result` |

## 4. Decisiones y supuestos

- **No hay outbox aparte.** El job se encola después del commit y `sap:sweep` recoge lo que se pierda entre el commit y la cola. Así no depende del driver de cola.
- **ENVIANDO es una barrera.** Un envío que empezó y no registró su resultado solo puede ir a conciliación.
- **Tiempo de gracia antes de reenviar** (10 minutos por defecto). Debe ser mayor que el timeout del conector a SAP, para que un envío lento no alcance a crear el documento después de que la consulta dijo "no existe".
- **Un documento con nuestra correlación pero distinto nunca se confirma.** Queda marcado `mismatch` con las diferencias, para revisión humana. No hay acción automática para aceptarlo.
- **Orden cerrada.** Se revisa contra la copia local (`production_orders.is_open`). Consultar el estado real en SAP antes de cada recibo depende de DP-06 y de DP-09.
- **Factor de conversión.** Sin factor vigente no hay recibo. La base exige `cantidad SAP = cajas × factor` exacto; si SAP necesita redondeo por unidad de medida, se ajusta con DP-02.
- **Campo de correlación en SAP.** El simulador busca por `request_uuid`. En SAP real hace falta un campo (UDF) filtrable en la cabecera del documento (DP-09). Sin eso no se habilita el conector.
- **Fechas del lote en SAP real.** El simulador guarda el vencimiento y la fabricación de cada lote y la fecha del documento. Con qué propiedades de Service Layer se mandan y se leen se define al escribir el conector (DP-09). Supuse que SAP crea el lote con el recibo, como pasa hoy en la pantalla de SAP.
- **Simulador.** Ahora numera los DocEntry por proceso, para que, como en SAP, nunca se repitan aunque corran varios procesos al tiempo.

## 5. Resultados reales

PHP 8.3.6 y PostgreSQL 16.15, en esta sesión:

```
php artisan test             → 224 pruebas, 224 pasan (tres corridas seguidas)
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
```

**Prueba intermitente, encontrada y corregida.** La nueva prueba de concurrencia de recibos falló en algunas corridas. Tenía dos causas, las dos en la prueba y no en el código de recibos:

1. Cada proceso tenía su propio simulador numerando desde 1000. Cuando dos procesos ganaban con estibas distintas, los dos documentos salían con el mismo DocEntry.
2. La prueba esperaba que toda estiba terminara recibida. Si `[2, 0]` gana primero, todas las demás selecciones se cruzan con ella y se rechazan, así que la estiba 1 legítimamente queda sin recibo.

Después de corregir las dos, 12 corridas seguidas pasaron.

## 6. Pendientes

- **DP-09:** acceso a SAP, versión, Service Layer, campo de correlación, almacenes 04 y 05 reales. Sin eso no se escribe el conector real.
- **DP-02:** de dónde salen los factores y la unidad de inventario. La tabla existe; falta la sincronización.
- **DP-06:** qué pasa con la orden en SAP después de recepciones parciales.
- **DP-03:** sin confirmación de etiqueta, ninguna estiba llega a ETIQUETADO, y por eso todavía no se puede recibir nada en planta.
- **Alertas:** las conciliaciones pendientes y los documentos que no coinciden se ven en el tablero y en la pantalla de recibos. El aviso activo (correo o Teams) va en la Fase 7.

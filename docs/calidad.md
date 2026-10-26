# Calidad antes del conteo ciego

## 1. Objetivo

Calidad puede intervenir en el proceso **después de que se confirma la etiqueta pegada** en la estiba y **antes del conteo ciego**. Lee el QR, ve la estiba con sus cantidades en cajas y en unidades, y decide:

- **Retener:** la estiba no se cuenta ni se traslada a 05 mientras siga retenida.
- **Liberar:** sigue el flujo normal, al conteo ciego.
- **Enviar a no conforme:** traslado de stock en SAP de la bodega **04** a la **11C** (almacén no conforme). La estiba sale del flujo.

Calidad no es un paso obligatorio. Las estibas que nadie retiene siguen al conteo ciego como antes.

## 2. Pantalla y permisos

| Ruta | Permiso | Quién |
|---|---|---|
| `/quality` (menú CEDI → Calidad) | `quality.manage` | Rol nuevo **Calidad** y el administrador |

El rol Calidad también ve las estibas (`pallets.view`) y la bitácora (`trace.view`). No cuenta: no tiene `audits.count`, así que nunca ve una estiba en el conteo ciego.

La pantalla tiene el lector de QR (lector de mano o cámara) y la lista de estibas retenidas. Al leer una estiba muestra:
- código, OF, producto y lote;
- cajas y unidades. Las unidades salen del recibo confirmado en SAP; si todavía no hay recibo, del factor de conversión vigente;
- recepción en 04, conteo ciego y estado de calidad;
- quién la retuvo, cuándo y por qué;
- el traslado a 11C, si ya existe, con su estado y DocNum.

Retener, liberar y enviar a no conforme piden siempre un **motivo**.

## 3. Reglas

- **Se puede retener** una estiba con la etiqueta confirmada (ETIQUETADO), que no se esté contando y que no tenga el conteo aprobado. Una estiba con discrepancia también se puede retener.
- **Una retenida** no se cuenta y no se traslada a 05.
- **Para enviarla a 11C** necesita el recibo de producción confirmado en SAP, porque tiene que estar en 04. Si se retiene antes del recibo, se puede pedir el recibo normalmente y después enviarla.
- **Lo que se traslada** es exactamente lo que SAP recibió de esa estiba: cajas × unidades por caja de su línea de recibo confirmada.
- **Una liberada** se puede volver a retener mientras no se haya contado.
- **En 11C** la estiba ya no se cuenta, no se libera ni se vuelve a retener.

## 4. Estados de calidad de la estiba

| Estado | Significa |
|---|---|
| Sin novedad | Calidad no ha intervenido |
| Retenida por calidad | Retenida, esperando decisión |
| Liberada por calidad | Revisada, sigue el flujo |
| Traslado a no conforme solicitado | Se pidió el traslado 04 → 11C |
| Traslado a no conforme por conciliar | SAP no respondió; se verifica antes de confirmar |
| En no conforme | SAP confirmó el traslado: la estiba está en 11C |

Es una dimensión propia de la estiba (`pallets.quality_status`), aparte del traslado a CEDI (`transfer_status`). Así el tablero de OF sigue sumando como "transferido a CEDI" solo lo que llegó a 05. La base de datos valida cada cambio de estado, igual que en las otras dimensiones.

## 5. Traslado a 11C en SAP

- Es el mismo documento `StockTransfers` del traslado 04 → 05, con los mismos datos fijos: `CardCode` P811022882 y normas de reparto PCC, PRODUCCI y ANTIOQUI. Solo cambia el destino: `SAP_NON_CONFORMING_WAREHOUSE` (11C por defecto).
- Se guarda en `inventory_transfers` con `purpose = NO_CONFORME`. Se envía y se verifica con las mismas reglas del traslado a CEDI:
  - con un 201 se compara lo que SAP respondió y se confirma con su DocEntry y DocNum;
  - un rechazo claro deja la estiba **retenida otra vez**, para reintentar o liberar;
  - sin respuesta de SAP queda por conciliar. Como el traslado no lleva campo de correlación, la conciliación solo lo confirma si SAP ya había dado su DocEntry.
- La retención se cierra como **NO_CONFORME** cuando SAP confirma el traslado.

## 6. Trazabilidad

- `quality_holds`: cada retención con quién y cuándo retuvo, el motivo, cómo terminó (liberada o no conforme), quién la cerró, sus notas y el traslado a 11C. Una retención cerrada no se modifica ni se borra, y solo puede haber una abierta por estiba.
- Bitácora de la estiba: `quality.held`, `quality.released`, `quality.non_conforming_requested`, `..._pending`, `..._confirmed` y `..._failed`. Todos llevan el usuario y el motivo.
- El detalle de la estiba muestra el estado de calidad.

## 7. Pruebas

| Prueba | Qué demuestra |
|---|---|
| `QualityTest::test_a_held_pallet_is_neither_counted_nor_sent_to_05` | Retener la saca del conteo, con trazabilidad |
| `QualityTest::test_a_released_pallet_goes_on_to_the_count_and_to_05` | Liberada, se cuenta y se traslada a 05 |
| `QualityTest::test_a_held_pallet_goes_from_04_to_11c_with_what_sap_received` | Traslado 04 → 11C con la cantidad del recibo; queda en no conforme sin tocar el traslado a CEDI; la retención se cierra |
| `QualityTest::test_when_sap_refuses_the_transfer_the_pallet_stays_held` | Un rechazo de SAP la deja retenida y se puede reintentar |
| `QualityTest::test_a_transfer_without_an_answer_is_reconciled_by_its_own_lifecycle` | La conciliación usa el ciclo de calidad, no el de CEDI |
| `QualityTest::test_quality_acts_after_the_label_and_before_the_count` | Antes de la etiqueta o después del conteo no se retiene; sin recibo no se traslada |
| `QualityTest::test_only_quality_holds_and_quality_never_counts` | Permisos |
| `QualityTest::test_the_page_reads_the_qr_shows_boxes_and_units_and_holds` | Pantalla: lectura, cajas y unidades, retener y enviar |
| `StateTransitionsMatchDatabaseTest` (calidad) | El enum y la base permiten los mismos cambios de estado |

## 8. Lo que falta o está supuesto

- **Una estiba con discrepancia abierta** que calidad envía a 11C deja la discrepancia abierta. El supervisor la ve en Discrepancias; cerrarla con acción sigue pendiente (DP-04).
- **Probar el traslado a 11C contra el SAP DEV.** El cuerpo es el del traslado 04 → 05 con otro destino.

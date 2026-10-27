# Calidad antes del conteo ciego

## 1. Objetivo

Calidad puede intervenir **después de que la estiba se recibe en 04** (con su etiqueta confirmada) y **antes del conteo ciego**. Lee el QR de una o varias estibas, ve cada una con sus cantidades en cajas y en unidades, y decide:

- **Retener:** la estiba se traslada en SAP de **04** a **11C** (almacén no conforme). *Retenida por calidad* quiere decir *está en 11C*. Mientras siga retenida no se cuenta ni se traslada a 05.
- **Liberar:** después de investigar, si el producto está conforme, calidad la libera. En SAP no se mueve nada en ese momento: la estiba sigue al conteo ciego del CEDI y, cuando el conteo se aprueba, su traslado sale de **11C → 05** en vez de 04 → 05.

Calidad no es un paso obligatorio. Las estibas que nadie retiene siguen al conteo ciego y van de 04 → 05 como siempre.

## 2. Pantalla y permisos

| Ruta | Permiso | Quién |
|---|---|---|
| `/quality` (menú CEDI → Calidad) | `quality.manage` | Rol **Calidad** y administrador |

El rol Calidad también ve estibas (`pallets.view`) y la bitácora (`trace.view`). No cuenta (`audits.count`).

- **Varias estibas a la vez.** Cada QR leído se suma a la lista "Estibas leídas". Desde la lista de "Retenidas" se pueden agregar una por una, o todas las que están en 11C con un botón.
- Por cada estiba se ven:
  - código, OF, producto y lote;
  - cajas y unidades (del recibo en SAP);
  - recepción, conteo y estado de calidad;
  - quién la retuvo y por qué;
  - el traslado a 11C con su DocNum.
- **Retener** y **Liberar** se aplican a todas las estibas leídas, con un solo motivo. Cada una se procesa por separado y lleva **su propio traslado** en SAP. Si una no se puede (por ejemplo, no tiene recibo), se dice por qué y las demás siguen.

## 3. Reglas

- **Para retener:** la estiba debe tener etiqueta confirmada y recibo confirmado en SAP, porque tiene que estar en 04. No se puede retener si se está contando o si el conteo ya se aprobó.
- **Lo que se traslada** a 11C es exactamente lo que SAP recibió: cajas × unidades por caja de su línea de recibo confirmada.
- **Liberar** solo aplica a una estiba que ya está en 11C.
- **Volver a retener una liberada** (antes de contarla) no mueve nada en SAP, porque sigue en 11C.
- **Si SAP rechaza el traslado a 11C**, la estiba no se movió: vuelve a "sin novedad", la retención queda anulada con el mensaje de SAP y se puede volver a retener.
- **Si SAP no responde**, queda por conciliar y no se cuenta mientras tanto. Como el traslado no lleva campo de correlación, se confirma solo si SAP ya había dado su DocEntry.

## 4. Estados de calidad de la estiba

| Estado | Dónde está en SAP | ¿Se cuenta? |
|---|---|---|
| Sin novedad | 04 | Sí |
| Retención: traslado a no conforme solicitado / por conciliar | 04 → 11C en curso | No |
| Retenida por calidad (en no conforme) | 11C | No |
| Liberada por calidad | 11C, hasta que el conteo la lleva a 05 | Sí |

Es una dimensión propia de la estiba (`pallets.quality_status`) y la base de datos valida cada cambio. El traslado a CEDI (`transfer_status`) sigue significando solo "llegó a 05". El tablero de OF y la columna "Doc. SAP › Traslado" del tablero de estibas cuentan solo ese.

## 5. SAP

- **04 → 11C (retener):** `StockTransfers` con los mismos datos fijos del traslado a CEDI (`CardCode` P811022882; normas PCC, PRODUCCI y ANTIOQUI) y destino `SAP_NON_CONFORMING_WAREHOUSE` (11C). Se guarda con `purpose = NO_CONFORME` y se verifica igual que cualquier traslado (`NonConformingTransferLifecycle`).
- **11C → 05 (después de liberar y contar):** es el traslado normal del conteo aprobado (`purpose = CEDI`). Solo cambia el origen, porque la estiba tiene un traslado a 11C confirmado y calidad la liberó.
- Una estiba puede tener a la vez un traslado vivo hacia no conforme y otro hacia CEDI. La regla de un solo traslado activo o confirmado por estiba aplica por destino.

## 6. Trazabilidad

- `quality_holds`, una fila por retención:
  - quién la retuvo, cuándo y el motivo;
  - el traslado a 11C y cuándo lo confirmó SAP;
  - quién la liberó, cuándo y sus notas;
  - si quedó anulada, el mensaje de SAP.
  
  Solo puede haber una retención abierta por estiba. Una cerrada no se modifica ni se borra.
- **Bitácora de la estiba:** `quality.held`, `quality.released` y `quality.non_conforming_*` (solicitado, por conciliar, confirmado, fallido), con usuario y motivo.

## 7. Pruebas

| Prueba | Qué demuestra |
|---|---|
| `QualityTest::test_holding_moves_the_pallet_from_04_to_11c_with_what_sap_received` | Retener traslada 04 → 11C con la cantidad del recibo; retenida no se cuenta |
| `QualityTest::test_a_released_pallet_is_counted_and_goes_from_11c_to_05` | Liberar no mueve nada; el conteo aprobado la lleva de 11C a 05 |
| `QualityTest::test_a_pallet_quality_never_touched_still_goes_from_04_to_05` | Sin calidad, todo sigue igual |
| `QualityTest::test_holding_a_released_pallet_again_moves_nothing` | Volver a retener una liberada no hace otro traslado |
| `QualityTest::test_when_sap_refuses_the_transfer_nothing_moved_and_it_can_be_held_again` | Un rechazo de SAP anula la retención y la estiba queda como estaba |
| `QualityTest::test_a_transfer_without_an_answer_is_reconciled_by_its_own_lifecycle` | Sin respuesta, no se cuenta; la conciliación usa el ciclo de calidad |
| `QualityTest::test_quality_acts_on_received_pallets_before_the_count` | Sin recibo, sin etiqueta o ya contada: no se retiene |
| `QualityTest::test_only_quality_holds_and_quality_never_counts` | Permisos |
| `QualityTest::test_the_page_reads_several_pallets_and_holds_and_releases_them_together` | Pantalla: varias estibas, cajas y unidades, retener y liberar juntas, cada una por separado |
| `StateTransitionsMatchDatabaseTest` (calidad) | El enum y la base permiten los mismos cambios de estado |

## 8. Cambio respecto a la primera versión

Antes "retener" solo marcaba la estiba y el traslado a 11C era un segundo paso. Al desplegar este cambio:
- Las estibas que estaban retenidas sin traslado vuelven a "sin novedad", y su retención queda anulada con el motivo.
- Las que ya están en 11C quedan como "Retenida por calidad (en no conforme)" y se pueden liberar.

## 9. Lo que falta o está supuesto

- **Una estiba con discrepancia abierta** que calidad retiene deja la discrepancia abierta. El supervisor la ve en Discrepancias.
- **Probar 11C → 05 contra el SAP DEV.** Es el mismo `StockTransfers` del conteo con otro origen.

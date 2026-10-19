# Fase 5: auditoría logística, conteo ciego y discrepancias

## 1. Objetivo

En el CEDI, el operario lee el QR de la estiba, cuenta las cajas y registra lo que contó sin saber cuántas debería haber. El servidor compara. Si coincide, la estiba queda lista para trasladar. Si no, se abre una discrepancia, se bloquea el traslado y se avisa al administrador. Nada se ajusta solo.

## 2. Pantallas

| Ruta | Para | Permiso |
|---|---|---|
| `/cedi/count` | Conteo ciego: leer QR, ver la identidad de la estiba, registrar cajas, ver el resultado | `audits.count` |
| `/discrepancies` | Casos abiertos, en revisión y resueltos, con cada intento (contado y sistema), y las acciones aprobadas por DP-04 | `discrepancies.resolve` |

## 3. Requisitos cubiertos

| Requisito | Pruebas |
|---|---|
| RF-13 y RF-14: leer QR y validar en el servidor | `test_only_received_and_not_transferred_pallets_are_counted` |
| CA-14: solo estibas con recibo confirmado y no trasladadas | ídem |
| CA-15: el operario no recibe la cantidad esperada | `BlindCountSecrecyTest` (5 pruebas), `test_what_the_operator_gets_has_no_quantity_at_all` |
| RF-15: conteo comparado en el servidor | `test_a_matching_count_approves_the_audit_and_readies_the_transfer` |
| CA-16: diferencia → novedad, traslado bloqueado, aviso | `test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter` |
| RF-16: discrepancia bloquea el traslado | ídem, más `test_closing_a_case_needs_an_approved_action...` |
| RF-17: novedades para revisión administrativa | `DiscrepanciesPageTest` (3) |
| RN-14: sin ajustes automáticos | `test_closing_a_case_needs_an_approved_action_a_different_person_and_never_adjusts_anything` |
| El operario no resuelve su propia discrepancia | ídem, más `test_an_authorized_reaudit_by_another_operator...` (la reauditoría la hace otra persona) |
| Permisos en el servidor | `test_people_without_the_permissions_are_refused_on_the_server`, `test_no_other_screen_or_endpoint_shows_quantities_to_the_operator` |
| Conteos simultáneos o abandonados | `test_a_count_belongs_to_whoever_started_it`, `test_cancelling_or_expiring_a_count_frees_the_pallet` |

## 4. Cómo se protege el conteo ciego

El prompt pide que la protección cubra la interfaz, el estado de Livewire, las respuestas, los mensajes y cualquier otro endpoint. Esto es lo que hay:

- **El caso de uso no devuelve cantidades.** `StartBlindCount` entrega un `BlindCountView` con identidad y nada más. Una prueba revisa por reflexión que no tenga otros campos. `SubmitBlindCount` devuelve solo `APROBADA` o `DISCREPANCIA`.
- **El estado de la página solo guarda identidad.** El componente guarda el código, la orden, el producto, la descripción, el lote, el número que escribió el operario y el resultado, nunca un modelo `Pallet`. Las propiedades sensibles son `#[Locked]`.
- **Los modelos ocultan los números.** `Pallet` oculta `boxes_count` y `standard_boxes_snapshot` al serializar, y `PalletAudit` oculta `expected_boxes`.
- **Los mensajes no llevan números.** Los de error y validación no mencionan la cantidad esperada.
- **El operario no entra a ninguna pantalla con cantidades.** No tiene `pallets.view`, `trace.view` ni ningún permiso de esas pantallas, y la notificación de discrepancia nunca le llega.
- **La prueba lee lo que sale al navegador.** `BlindCountSecrecyTest` usa una cantidad rara (8861) y la busca en el HTML, el snapshot y los effects de Livewire en cada paso (inicio, lectura, errores de validación, resultado), en la página HTTP completa y en las respuestas de 8 rutas prohibidas.
- **Comprobé que la prueba detecta una fuga.** Agregué a propósito un `<span class="hidden">` con la cantidad y la prueba falló. Después restauré el archivo.

## 5. Decisiones y supuestos

- **Una sola persona por conteo.** Si el mismo operario vuelve a leer la etiqueta, retoma su conteo. Si lo intenta otra persona, se le rechaza. Un conteo abierto más de `TRACE_COUNT_TIMEOUT_MINUTES` (60) se cancela solo con `audits:expire-counts`, que corre cada 5 minutos.
- **Conteo registrado es evidencia.** No se puede cambiar (trigger de la Fase 1). Para corregir hay que hacer otro conteo.
- **DP-04 apagado por defecto.** Con todo apagado, las discrepancias se ven y se registran, pero no se resuelven, y las estibas quedan retenidas. Hay dos interruptores:
  - `TRACE_DISCREPANCY_REAUDIT_ENABLED`: el administrador autoriza un segundo conteo ciego, que debe hacer alguien que no haya contado esa estiba. Si coincide, el caso se cierra (`REAUDITORIA_COINCIDE`) y la estiba queda lista. Si vuelve a diferir, se cierra ese caso (`REAUDITORIA_DIFIERE`) y se abre uno nuevo.
  - `TRACE_DISCREPANCY_CLOSE_ACTIONS`: lista de acciones con las que un administrador puede cerrar un caso, con notas y referencia. **Cerrar un caso no desbloquea la estiba ni ajusta nada**: el ajuste de inventario, si hace falta, lo hace en SAP quien corresponda, y la referencia del documento queda en la evidencia. Qué pasa después con esa estiba (reauditar, rechazar, devolver) es DP-04 y DP-08.

  Mi recomendación sigue igual: reauditoría obligatoria por otra persona antes de cualquier otra acción.
- **Segregación.** Ni quien contó ni quien contó antes la estiba puede autorizar la reauditoría ni cerrar el caso, aunque tenga el permiso.
- **Traslado automático.** Un conteo aprobado deja la estiba en LISTO. Crear el traslado solo es de la Fase 6.

## 6. Resultados reales

PHP 8.3.6 y PostgreSQL 16.15, en esta sesión:

```
php artisan test             → 243 pruebas, 243 pasan (dos corridas seguidas)
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
```

Las pantallas no se revisaron en un navegador: el build de assets sigue sin correr en esta sesión.

## 7. Pendientes

- **DP-04:** qué acciones se aprueban y quién las aprueba. Sin eso, una discrepancia deja la estiba retenida indefinidamente.
- **DP-08:** qué pasa con una estiba rechazada físicamente o devuelta.
- **Escáner real.** La pantalla supone un lector que funciona como teclado y manda Enter al final. Falta probarlo con el equipo del CEDI.

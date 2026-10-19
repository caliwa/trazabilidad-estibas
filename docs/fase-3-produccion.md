# Fase 3: pantalla de producción, paletizado, etiquetas y tablero

## 1. Objetivo

Que la planta vea y opere sus estibas: cuáles se están llenando, cerrarlas a mano, confirmar el paletizado, imprimir y reimprimir la etiqueta con QR, y que administración tenga un tablero con filtros, indicadores y la historia completa de cada estiba.

## 2. Pantallas

| Ruta | Pantalla | Permiso |
|---|---|---|
| `/production` | Estación de producción: estibas en estibado (cajas, estándar, porcentaje, barra), cierre manual con motivo, cola de paletizado y etiquetado, lecturas rechazadas de las últimas 8 horas. Se refresca cada 5 s mientras está visible | `pallets.operate` |
| `/pallets` | Tablero: 8 indicadores, 11 filtros combinables, 15 columnas, paginado | `pallets.view` |
| `/pallets/{id}` | Detalle: identidad, las cuatro dimensiones, lecturas, historia cronológica, impresiones, documentos SAP, reimpresión | `pallets.view` |
| `/pallets/rejected-scans` | Lecturas rechazadas con motivo y botón de reproceso | `pallets.view`, y `scans.reprocess` para reprocesar |
| `/pallets/{id}/labels/{n}` | Etiqueta imprimible de una impresión ya registrada | `pallets.label` |

El operario logístico no tiene acceso a ninguna de estas pantallas. Todas muestran cantidades, y eso dañaría el conteo ciego de la Fase 5.

## 3. Requisitos cubiertos

| Requisito | Componente | Pruebas |
|---|---|---|
| RF-07 paletizado | `ConfirmPalletized` | `test_a_finished_pallet_is_palletized_with_who_and_when` |
| RF-08 etiqueta, impresión y reimpresión | `PrintLabel`, `PalletLabelController`, `labels/pallet.blade.php` | `test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity`, `PalletDetailAndLabelTest` |
| RF-09 etiqueta aplicada (DP-03) | `ConfirmLabeled`, `LabelConfirmation` | 4 pruebas, una por modo más el modo sin decidir |
| RF-10 tablero | `PalletBoardQuery`, `pages::pallets.index` | `PalletBoardTest` (5) |
| CA-08 no paletizar en proceso ni etiquetar sin paletizar | | `test_a_pallet_in_process_cannot_be_palletized_nor_palletized_twice`, `test_a_label_needs_a_palletized_pallet` |
| CA-09 la reimpresión conserva identidad y cantidad | | `test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity`, `test_opening_the_label_again_records_nothing_and_a_reprint_is_marked` |
| CA-10 el QR no evade validaciones | `PalletQr` | `test_with_scan_mode_only_this_pallets_qr_confirms_it` (6 manipulaciones), `test_the_qr_carries_only_the_opaque_token` |
| Confirmaciones físicas auditadas | trazas `pallet.palletized`, `label.printed`, `label.reprinted`, `pallet.labeled` | en las mismas pruebas |
| Manipulación de propiedades Livewire | `#[Locked]` en los ids del componente | `test_the_pallet_being_closed_cannot_be_swapped_from_the_browser` |
| Permisos en el servidor | `Gate::authorize` en cada acción y `boot()` en cada página | `test_only_people_who_operate_production_open_the_station`, `test_operators_without_permission_are_refused_on_the_server`, `test_reprinting_needs_its_own_permission` |
| Consultas eficientes | subconsultas en la misma consulta paginada | `test_the_number_of_queries_does_not_grow_with_the_rows` (3 consultas con 2 o con 22 estibas) |

## 4. Decisiones y supuestos

- **Contenido del QR.** `TRZ1:` más los 32 caracteres hexadecimales del token opaco. No lleva id, código, lote ni cantidades. Al leerlo, el servidor busca la estiba y revalida todo. El prefijo con versión permite cambiar el formato sin confundir etiquetas viejas.
- **Imprimir es una acción, ver no.** "Imprimir" registra la impresión (número, usuario, fecha y, si es reimpresión, el motivo) y después abre la etiqueta. Abrir la página de la etiqueta otra vez no registra nada y muestra el mismo número. Toda reimpresión pasa por el motivo.
- **Reimprimir es un permiso aparte** (`pallets.reprint`) de imprimir la primera vez (`pallets.label`).
- **Etiqueta aplicada (DP-03): los tres modos están implementados y ninguno activo.** Con `TRACE_LABEL_CONFIRMATION` vacío la estiba no llega a ETIQUETADO y la pantalla lo indica. Los valores posibles son:
  - `print`: la primera impresión basta;
  - `button`: el operario confirma con un botón después de imprimir;
  - `scan`: el operario escanea el QR ya pegado, y tiene que ser el de esa estiba.

  Mi recomendación sigue siendo `scan`. Esto bloquea los recibos de la Fase 4, que solo aceptan estibas etiquetadas.
- **Etiqueta física (DP-07).** Hoy es una página HTML autocontenida de 100 × 150 mm (`LABEL_WIDTH_MM` y `LABEL_HEIGHT_MM`) que se imprime desde el navegador. Si la impresora es una Zebra se reemplaza por ZPL directo; el registro de impresiones no cambia.
- **Indicadores globales.** Los 8 indicadores no dependen de los filtros. "Finalizadas" cuenta toda estiba cerrada, en cualquier estado posterior.
- **Reproceso de lecturas rechazadas.** Reconstruye el evento original desde lo guardado y lo pasa por el mismo `ProcessScanEvent`, que decide si cuenta. Funciona solo con la recepción encendida (DP-01).
- **Bug corregido de la Fase 1.** La página de usuarios usaba `Role::Member`, que ya no existe, como rol por defecto. Ahora es "Consulta", el de menos privilegios. Prueba: `test_new_users_and_users_without_a_role_default_to_the_least_privileged_one`.

## 5. Comandos ejecutados y resultados reales

PHP 8.3.6 y PostgreSQL 16.15, en esta sesión:

```
php artisan test             → 200 pruebas, 200 pasan, 881 aserciones
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
```

**Sin verificación visual.** No abrí las pantallas en un navegador: el build de los assets no corre en esta sesión porque `fonts.bunny.net` está bloqueado. Las pruebas cubren el HTML que se genera, los permisos y el comportamiento, pero no cómo se ve. Hay que revisarlo en una estación real (monitor y pantalla táctil) antes de dar la fase por buena en planta.

## 6. Riesgos y pendientes

- **DP-03** bloquea que una estiba llegue a ETIQUETADO y, con eso, la Fase 4.
- **DP-07** define el formato final de la etiqueta y el consecutivo.
- **Refresco por consulta.** La estación consulta cada 5 s. Con muchas estaciones abiertas puede convenir pasar a eventos en tiempo real; hoy no hace falta.
- **Accesibilidad.** Las pantallas tienen etiquetas, roles ARIA en la barra de progreso y botones grandes, pero no se probaron con lector de pantalla.

## 7. Próxima tarea

Fase 4: recibos de producción contra el simulador SAP. Incluye la selección individual y agrupada de estibas etiquetadas de una orden, la reserva, el job de envío con `request_uuid`, la verificación del documento, la conciliación de resultados inciertos y la conversión de cajas a unidades. Depende de DP-02 (factor y unidad), DP-06 (cierre de órdenes) y DP-09 (acceso a SAP) para pasar del simulador a SAP real.

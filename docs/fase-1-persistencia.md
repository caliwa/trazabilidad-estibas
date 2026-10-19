# Fase 1: persistencia, estados e invariantes

## 1. Objetivo

Dejar la base del sistema: tablas, estados por dimensión, reglas que la base de datos garantiza por sí misma, bitácora inmutable, roles, el contrato con SAP y su simulador. Sin pantallas ni casos de uso todavía: eso empieza en la Fase 2.

## 2. Requisitos cubiertos

| Regla | Cómo queda garantizada | Pruebas |
|---|---|---|
| RN-01 identidad única e inmutable | Columnas no nulas, trigger `pallets_guard` rechaza cambiar orden, producto, lote, código, QR, estándar o inicio | `PalletInvariantsTest::test_identity_is_immutable` |
| RN-02 un evento cuenta una vez | Único `(device_id, external_event_id)`, eventos inmutables salvo RECHAZADO → ACEPTADO una sola vez | `ScanEventInvariantsTest` (4) |
| RN-04 estándar positivo y vigente | CHECK en `pallet_standards` y en el snapshot de la estiba, una versión VIGENTE por producto | `MasterInvariantsTest` (3), `test_the_standard_must_be_positive` |
| RN-05 estándar congelado | `standard_boxes_snapshot` + `pallet_standard_id` inmutables | `test_a_later_standard_change_does_not_touch_existing_pallets` |
| RN-06 consecutivo único | Único en `pallet_code`, sin borrado (trigger), tabla `pallet_code_counters` lista para la Fase 2 | `test_pallet_codes_are_unique`, `test_pallets_are_never_deleted` |
| RN-07 nada confirmado sin SAP | CHECK: CONFIRMADO ⇔ DocEntry + DocNum + fecha. Solo se llega a CONFIRMADO desde ENVIANDO o PENDIENTE_CONCILIACION | `SapDocumentInvariantsTest::test_confirmation_requires_doc_entry_and_doc_num`, `test_a_request_cannot_jump_to_confirmed_without_being_sent` |
| RN-08 / RN-09 no recibir ni trasladar dos veces | Índice único parcial `(pallet_id) WHERE hold_status IN ('ACTIVA','CONFIRMADA')` en las dos tablas de líneas | `test_a_pallet_cannot_be_held_by_two_receipts`, `test_a_pallet_cannot_be_held_by_two_transfers_and_warehouses_must_differ` |
| RN-10 dimensiones independientes | Cuatro columnas con su CHECK; cada dimensión exige la anterior terminada | `test_each_dimension_needs_the_previous_process` |
| RN-12 concurrencia | Índice único parcial: una sola estiba EN_ESTIBADO por orden + producto + lote. Las pruebas con procesos paralelos van en la Fase 2 junto con el motor | `test_only_one_open_pallet_per_order_item_and_lot` |
| RN-13 auditoría | `trace_events` + `TraceLogger`, con actor, origen, correlación, estados, resultado y referencias SAP | `TraceEventsTest` (4) |
| RN-15 no retroceder estados confirmados | Función `state_transition_allowed()` usada por los triggers; enums con `next()` | `StateTransitionsMatchDatabaseTest`, `StateMachineTest`, `test_confirmed_states_cannot_go_back` |
| RN-16 sin secretos en logs | `Redactor` en contexto de trazas y en la línea de log | `RedactorTest`, `test_context_and_log_lines_never_carry_secrets` |
| RN-17 nada pendiente por suposición | `config/traceability.php` con banderas apagadas (DP-01, DP-03, DP-05); SAP real no existe (DP-09) | `SapGatewayBindingTest` (2) |
| CA-07 cierre manual entre 1 y estándar − 1 | CHECK `manual_close_is_partial` | `test_manual_close_needs_between_one_box_and_standard_minus_one_and_who_closed` |
| CA-08 no paletizar sin finalizar | Transiciones de un paso | `test_physical_steps_cannot_be_skipped_or_reversed` |
| CA-12 / CA-13 timeout y conciliación | El simulador reproduce respuesta perdida, timeout sin documento, rechazo y consulta fallida | `FakeSapGatewayTest` (7) |
| CA-15 conteo ciego (base) | `boxes_count`, `standard_boxes_snapshot`, `qr_token` y `expected_boxes` ocultos en serialización; el operario logístico no tiene `pallets.view` | `test_quantities_and_qr_token_are_not_serialized`, `test_expected_boxes_is_never_serialized`, `RolesTest` |

## 3. Decisiones y supuestos

- **Base del proyecto.** Se partió del esqueleto de GestProy ya corregido (Laravel 13, Livewire 4, Fortify, Flux, permisos, CI con PostgreSQL). Se quitó todo el módulo de proyectos. También se quitó la opción de que un usuario borre su propia cuenta: aquí los usuarios se desactivan, nunca se borran, porque estibas, conteos y documentos apuntan a ellos.
- **La base de datos es la última defensa.** Los casos de uso van a validar todo antes. Los CHECK, índices parciales y triggers están para que un error de código o un `UPDATE` manual no puedan dejar una estiba en un estado imposible.
- **Estados duplicados a propósito en PHP y SQL.** La función `state_transition_allowed()` está escrita a mano en la migración y no se genera desde los enums, para que las migraciones no dependan del código. `StateTransitionsMatchDatabaseTest` revisa todas las combinaciones: si alguien cambia un enum sin migración, falla.
- **Documentos SAP con estado ENVIANDO.** Marca que ya empezó un envío y no se sabe el resultado. Si un worker muere ahí, lo único permitido es pasar a conciliación; no se puede volver a enviar a ciegas. Reenviar solo es posible desde PENDIENTE_CONCILIACION, después de demostrar en SAP que no existe el documento.
- **Reserva de estibas = línea ACTIVA.** Una estiba queda tomada por un documento cuando tiene una línea en estado ACTIVA. Si SAP demuestra que el documento falló, la línea pasa a LIBERADA y la estiba se puede volver a pedir. El índice único parcial impide dos líneas activas o confirmadas.
- **Cantidades exactas.** `numeric(18,6)` en la base, `Quantity` en PHP con aritmética de enteros escalados (no hace falta `bcmath`). La base exige `sap_quantity = boxes × units_per_box` exacto. Si SAP necesita redondeo por unidad de medida, se revisa con DP-02.
- **Unicidad del evento por dispositivo.** Hasta cerrar DP-01, `external_event_id` es único por dispositivo.
- **Un evento rechazado se puede aceptar después, una sola vez.** Así se reprocesa tras corregir un maestro sin contar dos veces. El trigger impide cambiar lo que envió el sensor.
- **Outbox.** Con la cola `database` en la misma conexión de PostgreSQL, encolar un job dentro de la transacción ya es atómico. Se documenta como invariante; la prueba va con el primer job real (envío a SAP, Fase 4).
- **`trace_events` separada de `audit_logs`.** `audit_logs` queda para usuarios y roles. `trace_events` es la bitácora operativa, con trigger contra UPDATE, DELETE y TRUNCATE. Es inmutabilidad a nivel de aplicación: un superusuario de PostgreSQL la puede saltar, para eso están respaldos y control de acceso.
- **Morph map forzado.** `subject_type` guarda alias cortos (`pallet`, `production_receipt`...) y no nombres de clase.
- **Contrato SAP sin detalles de Service Layer.** `SapGateway` define solo lo que el sistema necesita. Dos reglas del contrato: un timeout se devuelve como `UNCERTAIN`, nunca como rechazo; y una consulta devuelve `null` solo si SAP demostró que no existe, si no se pudo preguntar lanza `SapLookupFailed`.
- **El simulador no deduplica**, igual que un sistema real sin idempotencia. Así las pruebas de las fases 4 y 6 demuestran que la aplicación nunca envía dos veces.
- **Lección de las pruebas.** Después de que PostgreSQL rechaza un `save()`, el modelo de Eloquent conserva el cambio rechazado en memoria y el siguiente `save()` lo vuelve a mandar. Los casos de uso deben recargar el modelo o abortar la transacción completa ante un rechazo.

## 4. Archivos principales

| Área | Archivos |
|---|---|
| Estados | `app/Domain/{Pallets,Receipts,Audits,Transfers,Integration}/Enums/*`, `app/Domain/Shared/HasTransitions.php` |
| Bitácora | `app/Domain/Shared/{TraceContext,TraceEventType,ActorType,Origin,TraceResult}.php`, `app/Services/TraceLogger.php`, `app/Models/TraceEvent.php` |
| Correlación y logs | `app/Support/Correlation/CorrelationId.php`, `app/Http/Middleware/AssignCorrelationId.php`, `app/Support/Logging/Redactor.php` |
| SAP | `app/Integrations/Sap/Contracts/SapGateway.php`, `Dto/*`, `Exceptions/SapLookupFailed.php`, `Fake/*` |
| Modelos | `app/Models/*` (16 nuevos) |
| Migraciones | `database/migrations/2026_10_20_00000{1..5}_*.php` |
| Configuración | `config/sap.php`, `config/traceability.php` |
| Roles | `app/Enums/{Role,Permission}.php` |
| Factories | `database/factories/*` (la de estiba recorre las transiciones reales) |

## 5. Migraciones y restricciones

| Tabla | Restricciones clave |
|---|---|
| `devices` | código y hash de token únicos; tipo SENSOR/LECTOR |
| `items`, `production_orders` | caché de SAP; referencia y DocEntry únicos |
| `pallet_standards`, `box_conversion_factors` | versión única por producto, una sola VIGENTE, valor > 0 salvo INVALIDO con motivo, ventana de vigencia ordenada |
| `pallet_code_counters` | contador por prefijo, sin huecos |
| `pallets` | 17 CHECK, índice parcial de estiba activa, triggers de inserción, actualización y borrado |
| `pallet_scan_events` | único por dispositivo, resultado coherente, inmutable |
| `pallet_label_prints` | primera impresión ORIGINAL, reimpresión con motivo, append-only |
| `pallet_audits` | un conteo abierto por estiba, resultado coherente con los números, conteo terminado inmutable |
| `discrepancy_cases` | resolución con quién, cuándo, acción y notas; resuelta = cerrada |
| `production_receipts`, `inventory_transfers` | `request_uuid` único, transiciones validadas, CONFIRMADO con DocEntry/DocNum, DocEntry único, solicitud inmutable, cerrados congelados |
| `*_pallets` | una retención activa o confirmada por estiba, cantidad SAP exacta |
| `integration_attempts` | append-only, intento único por documento |
| `trace_events` | actor identificado, append-only (UPDATE, DELETE, TRUNCATE) |

## 6. Comandos ejecutados y resultados

En esta sesión, con PHP 8.3.6 y PostgreSQL 16.15:

```
php artisan test                  → 127 pruebas, 127 pasan, 441 aserciones
phpstan analyse (nivel 7)         → 0 errores
pint --test                       → pasa
```

Limitaciones del entorno de esta sesión (no del CI): `phpstan/phpstan` solo se descarga como zip desde un host bloqueado, así que se clonó con git en la versión del lock; `npm run build` no corre porque `fonts.bunny.net` está bloqueado, así que las pruebas usaron un manifest de Vite mínimo. En el CI de GitHub nada de esto aplica.

> **Corrección posterior (Fase 2).** Las columnas `timestamptz` se crearon con precisión de segundos y las fechas se enviaban sin zona horaria, lo que las corría 5 horas. Se corrigió en la Fase 2; ver `docs/fase-2-motor-estibado.md`, sección 5.

## 7. Riesgos y pendientes

- **Concurrencia real sin probar todavía.** Las garantías existen en la base, pero las pruebas con procesos paralelos llegan con el motor de la Fase 2.
- **Maestros sin fuente confirmada (DP-02).** Las tablas existen; la sincronización se escribe cuando se sepa qué campos de SAP usar.
- **Formato del consecutivo (DP-07).** Prefijo y dígitos configurables; el formato real depende de la etiqueta.
- **Guía de despliegue heredada.** Sirve para un entorno de pruebas; se reescribe en la Fase 7.

## 8. Próxima tarea

Fase 2: endpoint de sensores con autenticación por dispositivo, `ProcessScanEvent` con advisory lock + `FOR UPDATE` + índice parcial, generador de consecutivo, cierre automático y manual, y pruebas de concurrencia con procesos paralelos contra PostgreSQL. Todo detrás de `TRACE_SCAN_INGESTION_ENABLED=false` hasta cerrar DP-01.

# Fase 2: motor de estibado

## 1. Objetivo

Recibir lecturas de los sensores, contarlas una sola vez, armar y cerrar estibas solas, y permitir el cierre manual, de forma segura cuando varios procesos escriben al tiempo.

**Sigue apagado.** El endpoint responde 503 mientras `TRACE_SCAN_INGESTION_ENABLED=false`, y así debe quedar en producción hasta aprobar DP-01 (identificador del sensor y su alcance).

## 2. Requisitos cubiertos

| Requisito | Componente | Pruebas |
|---|---|---|
| RF-01 recibir eventos | `POST /api/v1/scan-events`, `ScanEventController`, `StoreScanEventRequest` | `ScanEventApiTest` (9) |
| RF-02 validar orden, producto y lote | `ScanMasterResolver` | `test_every_master_problem_has_its_own_reason` |
| RF-03 crear estibas | `ProcessScanEvent::open`, `PalletCodeGenerator` | `test_the_first_scan_creates_a_pallet_with_the_standard_frozen` |
| RF-04 eventos únicos | `insertOrIgnore` sobre único `(device_id, external_event_id)` | `test_a_repeated_event_is_not_counted_again`, `test_the_same_events_sent_by_many_workers_count_once` |
| RF-05 cierre automático | `ProcessScanEvent::accept` | `test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` |
| RF-06 cierre manual | `CloseManually` | `CloseManuallyTest` (5) |
| CA-01 | | `test_the_first_scan_creates_a_pallet_with_the_standard_frozen` |
| CA-02 | | `test_each_valid_scan_adds_exactly_one_box`, `test_a_repeated_event_is_not_counted_again` |
| CA-03 | | `test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` |
| CA-04 concurrencia | advisory lock + `FOR UPDATE` + índices únicos | `ScanConcurrencyTest` (4, procesos reales) |
| CA-05 no mezclar | | `test_orders_items_and_lots_never_mix`, `test_parallel_combinations_never_mix_and_share_one_gapless_sequence` |
| CA-06 estándar ausente | | `test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason` |
| CA-07 cierre manual | | `test_it_closes_with_the_real_count_and_records_who_and_why`, `test_an_empty_pallet_cannot_be_closed` |
| RN-03 sin id confiable no hay producción | bandera DP-01 | `test_nothing_is_processed_while_dp01_is_open`, `test_ingestion_answers_503_while_dp01_is_open` |
| RN-05 estándar congelado | | `test_a_new_standard_applies_to_new_pallets_only` |
| RN-06 consecutivo sin huecos | contador con upsert dentro de la transacción | `test_a_rolled_back_pallet_gives_its_number_back`, `PalletCodeGeneratorTest`, `assertCodesHaveNoGapsOrRepeats` |
| Seguridad de dispositivos | token por dispositivo (solo hash), límite por dispositivo | `test_unknown_wrong_and_inactive_devices_get_401`, `test_each_device_is_rate_limited`, `DeviceCommandsTest` (3) |
| Permisos en el servidor | `Gate::authorize` dentro de `CloseManually` | `test_people_without_the_permission_are_refused_on_the_server` |

## 3. Cómo funciona

1. El dispositivo manda `Authorization: Bearer <token>` y un JSON con `event_id`, `production_order`, `item_code`, `lot` y `occurred_at` (ISO 8601 con zona horaria).
2. `AuthenticateDevice` busca el hash del token. Un dispositivo desconocido o inactivo recibe 401.
3. `StoreScanEventRequest` valida la forma. Si la forma está mal no se guarda nada, porque no hay un id confiable.
4. `ProcessScanEvent`:
   - Si el evento ya existe y fue aceptado, o si existe con otro contenido, responde "duplicado" sin tocar nada. El caso de otro contenido deja una traza `scan.duplicate_mismatch`.
   - Valida contra los maestros locales. Si algo falla, guarda el evento como RECHAZADO con su motivo y deja una traza.
   - Si es válido, dentro de una transacción:
     - toma un advisory lock de la combinación orden + producto + lote;
     - lee la estiba abierta con `FOR UPDATE` o crea una nueva (consecutivo, QR opaco, estándar congelado);
     - inserta el evento con `ON CONFLICT DO NOTHING`. Si otro proceso lo insertó primero, se revierte todo, incluida la estiba y el número;
     - suma una caja y cierra la estiba si llegó exactamente al estándar.

**Respuestas:**

| Código | Significado |
|---|---|
| 201 | Aceptado |
| 200 | Ya procesado. El dispositivo puede reintentar tranquilo |
| 422 | Rechazado, o la forma está mal |
| 401 | Dispositivo desconocido o inactivo |
| 429 | Demasiadas lecturas en el minuto |
| 503 | Recepción apagada (DP-01) |

**Dispositivos:**
- `php artisan devices:register SEN-L1 "Sensor línea 1"` muestra el token una sola vez.
- `devices:rotate-token` invalida el token anterior y entrega uno nuevo.
- `devices:deactivate` desactiva el dispositivo.

## 4. Decisiones y supuestos

- **Eventos fuera de orden.** Un evento se asigna a la estiba abierta cuando se procesa, no según su `occurred_at`. Si su hora es anterior al inicio de esa estiba, cuenta ahí y queda marcado `is_late` para revisión. Esto queda así hasta que DP-01 diga otra cosa.
- **Eventos rechazados.** Si el dispositivo reenvía el mismo evento con el mismo contenido, se evalúa otra vez. Si ya se corrigió el maestro, cuenta, una sola vez. Un reenvío que sigue siendo inválido no duplica el registro ni la traza.
- **Unicidad por dispositivo.** El mismo `event_id` desde dos dispositivos son dos cajas. Si DP-01 dice que el id es global, se cambia el índice.
- **Lote.** Solo se valida el formato (letras, números, `.`, `_`, `/`, `-`, hasta 50). Si el lote debe existir en SAP antes de producir es parte de DP-02.
- **Vigencia del estándar.** Se evalúa en el momento de procesar.
- **Inicio de la estiba.** Es la hora de la primera caja, salvo que venga en el futuro (reloj del dispositivo adelantado): ahí se usa la hora del servidor. Se aceptan hasta 5 minutos de adelanto; más que eso es un 422.
- **Cierre manual.** El motivo es opcional. Usa el mismo bloqueo que el escaneo, así que nunca se intercalan: si una lectura llena la estiba primero, el cierre se rechaza; si el cierre gana, la lectura abre la estiba siguiente.
- **Límite por dispositivo.** 300 lecturas por minuto, configurable con `TRACE_SCAN_RATE_PER_MINUTE`.
- **Notificaciones posteriores.** Todavía nadie las consume. La pantalla de producción (Fase 3) va a consultar la estiba activa. El primer job real (envío a SAP, Fase 4) trae la prueba de que encolar dentro de la transacción es atómico.
- **HMAC y mTLS.** No se implementaron: dependen de lo que soporte el sensor (DP-01). El token por dispositivo sobre TLS es la base.

## 5. Dos problemas encontrados y corregidos (afectaban la Fase 1)

1. **Fechas corridas 5 horas.** La sesión de PostgreSQL estaba en UTC, y Laravel manda las fechas sin zona horaria. Una hora de Bogotá se guardaba como si fuera UTC. Se corrigió con una conexión propia (`App\Support\Database\PostgresConnection`) que manda las fechas con offset y microsegundos. Como segunda capa, la sesión ahora usa la zona horaria de la app.
2. **Precisión de segundos.** `timestampTz()` de Laravel crea `timestamp(0)`: redondea al segundo, pierde el orden dentro del segundo y puede romper el CHECK de orden de fechas. Ahora la precisión por defecto es 6, y la migración `2026_10_21_000001` sube las columnas que ya existían.

Pruebas: `DatabaseTimeTest` (3), `test_times_with_offset_are_stored_as_the_same_instant_with_microseconds`.

## 6. Comandos ejecutados y resultados reales

PHP 8.3.6 y PostgreSQL 16.15, en esta sesión:

```
php artisan test                          → 166 pruebas, 166 pasan, 766 aserciones
php artisan test --testsuite=Concurrency  → 4 de 4, tres corridas seguidas
phpstan analyse (nivel 7)                 → 0 errores
pint --test                               → pasa
```

**Prueba de que las pruebas de concurrencia detectan fallas.** Quité temporalmente el advisory lock y el `FOR UPDATE` de `ProcessScanEvent` y corrí la suite: 3 de 4 fallaron. Hubo índices únicos violados y triggers que rechazaron cantidades que bajaban. Después restauré el código. Un detalle útil: sin los bloqueos la base convierte la carrera en un error, no en datos corruptos.

**Cómo funcionan las pruebas de concurrencia.** Cada una lanza entre 5 y 6 procesos PHP reales (`tests/Concurrency/worker.php`), cada uno con su propia conexión. Esperan una señal para salir al tiempo y corren contra una base aparte (`<base de pruebas>_concurrency`), porque necesitan ver datos ya confirmados. Escenarios:
- 6 procesos × 30 lecturas sobre la misma combinación con estándar 40: resultan exactamente 4 estibas llenas y una con 20;
- 6 procesos mandando los mismos 40 eventos: 40 aceptados y 200 duplicados;
- 4 combinaciones en paralelo: no se mezclan y comparten un consecutivo sin huecos;
- 4 procesos escaneando mientras otro cierra a mano: ni una caja de más ni de menos, y cada cierre cumple su regla.

## 7. Riesgos y pendientes

- **DP-01 bloquea producción.** Falta saber qué id manda el sensor, si es único por equipo o global, si se reinicia, y cómo se conecta (HTTP directo, PLC, OPC-UA).
- **Maestros (DP-02).** Las tablas `items`, `production_orders` y `pallet_standards` todavía no se llenan desde SAP; en pruebas se llenan con factories. La sincronización se escribe cuando se sepa qué campos usar.
- **Reproceso de rechazados.** Hoy solo ocurre si el dispositivo reenvía. Una pantalla o comando para reprocesar desde administración queda para la Fase 3.
- **Volumen de trazas.** Cada rechazo deja una traza. Con un sensor mal configurado eso puede crecer rápido; las alertas por volumen van en la Fase 7.

## 8. Próxima tarea

Fase 3: pantalla de producción (estiba activa, porcentaje, cierre manual, historial y errores de lectura), paletizado, etiqueta con QR (vista HTML hasta definir DP-07), reimpresión auditada y tablero administrativo con filtros e indicadores. La confirmación de etiqueta aplicada queda apagada hasta DP-03.

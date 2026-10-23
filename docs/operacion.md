# Operación y soporte

Qué hacer cuando algo sale mal. Para instalar y desplegar, ver `docs/despliegue.md`.

## 1. Dónde mirar primero

| Pregunta | Dónde |
|---|---|
| ¿Está todo vivo? | `/operations` (permiso `ops.alerts`) o `curl https://.../api/health` |
| ¿Qué le pasó a esta estiba? | `/pallets/{id}`: historia, lecturas, impresiones, documentos SAP con DocNum y línea |
| ¿Qué hizo esta petición? | Cada respuesta trae `X-Correlation-Id`. Buscarlo en `trace_events.correlation_id` y en `laravel.log` |
| ¿Qué respondió SAP? | Tabla `integration_attempts`: operación, resultado, latencia, código de error |

```sql
-- Todo lo que pasó con una correlación
SELECT occurred_at, event, subject_type, subject_id, result, reason FROM trace_events WHERE correlation_id = '...' ORDER BY id;

-- Llamadas a SAP de un documento
SELECT attempt_number, operation, outcome, latency_ms, error_code, error_message FROM integration_attempts WHERE request_uuid = '...' ORDER BY attempt_number;
```

## 2. Alertas

`ops:check` corre cada 5 minutos y avisa por la campana de la aplicación (y por correo a quien lo tenga activo) a quienes tienen `ops.alerts`. La misma alerta no se repite antes de `OPS_NOTIFY_COOLDOWN_MINUTES` (60).

| Alerta | Nivel | Qué significa | Qué hacer |
|---|---|---|---|
| El scheduler no está corriendo | Crítica | No corre el cron de `schedule:run` | Revisar `/etc/cron.d/trazabilidad` y el usuario. Sin scheduler no hay barrido SAP ni alertas |
| No hay worker en la cola X | Crítica | Ningún worker tomó el latido de esa cola en 3 min | `supervisorctl status`; revisar `worker-*.log`; `supervisorctl restart trazabilidad:*` |
| Trabajos acumulados | Crítica | Más de 100 jobs listos o el más viejo espera más de 10 min | Workers caídos o SAP lento. Ver latencias en `integration_attempts`. Subir `numprocs` si es volumen |
| Trabajos fallidos | Atención | Hay filas en `failed_jobs` | `php artisan queue:failed`. Los de la cola `sap` no se reintentan a mano: el barrido y la conciliación ya cubren esos documentos. Revisar el motivo y después `queue:forget` |
| Lecturas rechazadas | Atención | Más de 10 en 15 min | `/pallets/rejected-scans`: el motivo dice qué maestro falta (orden, lote, producto). Corregir el maestro y reprocesar |
| Lecturas rechazadas por estándar | Crítica | Un producto sin estándar vigente: su producción no se cuenta | Cargar o corregir el estándar del producto y reprocesar las lecturas rechazadas |
| Órdenes abiertas sin estándar | Atención | Va a pasar lo anterior en cuanto se produzca | Cargar el estándar antes de que arranque la orden |
| Duplicados anormales | Atención | Más de 50 lecturas repetidas en 15 min | Un sensor está reenviando. No suma cajas (son idempotentes), pero indica un problema de red o de configuración del equipo |
| Errores en llamadas a SAP | Atención | Rechazos, timeouts o consultas fallidas en la última hora | `integration_attempts.error_code`. Si es credencial o SAP caído, avisar a SAP. Los documentos quedan pendientes y se concilian solos |
| Pendientes de conciliación | Atención | Documentos que llevan más de 30 min sin saberse si existen en SAP | Ver sección 3 |
| Documentos que no coinciden | Crítica | En SAP hay un documento con nuestra correlación pero distinto | Ver sección 3.3. Nunca se confirma solo |
| Discrepancias abiertas | Atención | Estibas retenidas por conteo distinto | `/discrepancies`. Ver sección 4 |

## 3. Conciliación de recibos y traslados

Un documento queda en **PENDIENTE_CONCILIACION** cuando no se sabe si SAP lo creó: timeout, respuesta ambigua o worker caído a mitad de envío. Mientras tanto la estiba queda retenida y no se puede pedir otra vez.

### 3.1 Lo que hace el sistema solo

`sap:sweep` lo consulta en SAP por su correlación cada `SAP_CHECK_EVERY_MINUTES` (5):

| SAP dice | Resultado |
|---|---|
| Existe y coincide | Se confirma con DocEntry y DocNum |
| No existe y ya pasó el tiempo de gracia (10 min) | Se reenvía con la misma correlación, hasta 3 envíos |
| No existe, todavía en tiempo de gracia | Espera (`no_existe_en_espera`) |
| No se pudo consultar | No cambia nada (`consulta_fallida`) |
| Existe pero no coincide | Se marca `mismatch` y se detiene |

### 3.2 Cuando llega al límite de envíos

`reconciliation.last_result = no_existe_limite_de_envios`. SAP rechazó o perdió tres envíos. Revisar `integration_attempts` para entender por qué. Cuando esté claro que el documento no existe, usar **Marcar fallido** con el motivo: para recibos en `/receipts`, para transferencias en el detalle de la estiba (`/pallets/{id}`). Solo se acepta si SAP confirma en ese momento que no existe. La estiba queda libre para pedirse otra vez.

### 3.3 Documento que no coincide

Alguien editó el documento en SAP, o otra solicitud usó la misma correlación. La pantalla lo marca en rojo; las diferencias exactas quedan en la columna `reconciliation` (`differences`). El sistema no lo confirma ni lo reenvía: crear otro duplicaría inventario. Lo resuelve una persona con SAP abierto:

1. Ver el documento en SAP (DocEntry en `reconciliation.doc_entry`).
2. Si está mal, corregirlo o cancelarlo **en SAP**, con el procedimiento de la empresa.
3. Anotar lo hecho. Cerrar el caso en la aplicación todavía no tiene una acción (depende de DP-08, reversión de documentos).

### 3.4 Lo que nunca se hace

- No cambiar estados con `UPDATE` en la base. Los triggers lo rechazan, y aunque se pudiera, se perdería la evidencia.
- No reintentar a mano un job de la cola `sap` desde `failed_jobs` sin revisar primero el estado del documento.
- No crear en SAP a mano un documento que la aplicación tiene pendiente: la conciliación no lo reconocería sin la correlación.

### 3.5 Transferencias 04 → 05

No hay pantalla de traslados: la transferencia se pide sola cuando el conteo ciego sale bien, en la misma transacción. Cuando una necesita a una persona, se maneja desde el detalle de la estiba, con el permiso `transfers.reconcile`:

| Situación | En el detalle de la estiba |
|---|---|
| SAP la rechazó (FALLIDO) | **Solicitar transferencia** la pide otra vez. Revisar antes el motivo en `integration_attempts` |
| Pendiente de conciliación | **Conciliar** la consulta ya; **Marcar fallida** solo si SAP confirma que no existe |
| Auditada sin transferencia (estibas contadas antes de este cambio) | **Solicitar transferencia** |

Para encontrarlas: alerta "Pendientes de conciliación" en `/operations`, o el tablero filtrado por estado de traslado.

## 4. Discrepancias

Una discrepancia se abre cuando el operario falla las 3 oportunidades del conteo. La estiba queda retenida y el caso se ve en `/discrepancies` con cada conteo, los números de cada oportunidad y la cantidad del sistema. El supervisor de producción da "Habilitar reconteo" y otro operario la vuelve a contar (ver `docs/fase-5-auditoria.md`). Cerrar un caso con acción sigue apagado (`TRACE_DISCREPANCY_CLOSE_ACTIONS`). Nunca se ajusta inventario: el ajuste, si hace falta, se hace en SAP.

## 5. Después de restaurar un respaldo

El respaldo es de una hora anterior; SAP no se restaura. Antes de volver a operar:

1. Anotar la fecha y hora del respaldo restaurado.
2. En SAP, listar recibos de producción y traslados creados desde esa hora con el campo de correlación (DP-09).
3. Para cada uno, buscar su `request_uuid` en `production_receipts` o `inventory_transfers`. Los que no están se crearon después del respaldo: sus estibas pueden aparecer en la aplicación como no recibidas o no trasladadas cuando en SAP sí lo están.
4. No pedir esas estibas otra vez hasta aclarar cada caso. Registrar la lista y lo decidido.
5. Las lecturas de sensores posteriores al respaldo se perdieron. Si los sensores guardan historial, se pueden reenviar: son idempotentes.

## 6. Dispositivos

```bash
php artisan devices:register SEN-L1 "Sensor línea 1" --kind=SENSOR   # muestra el token una sola vez
php artisan devices:rotate-token SEN-L1                              # el token viejo deja de servir
php artisan devices:deactivate SEN-L1 --reason="equipo retirado"
```

El token se guarda solo como hash. Si se pierde, se rota.

## 7. Verificación de configuración

`php artisan app:preflight` revisa debug, HTTPS, cookies seguras, cola persistente, caché compartida, conector SAP real y base de datos, y lista qué decisiones pendientes siguen apagadas. Sale con error si algo no es seguro. Corre solo en cada despliegue; también se puede correr a mano.

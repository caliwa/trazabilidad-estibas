# Trazabilidad de estibas, producción y CEDI · Fase 0: diagnóstico

Fecha: 2026-10-19. Rama: `claude/trusting-goldberg-pfofev`.

## Decisiones tomadas (2026-10-19)

- **DP-00:** el sistema de estibas será una **aplicación aparte, en un repositorio nuevo**, con el mismo stack. Este documento se escribió en GestProy y se copió a este repositorio como antecedente. Las rutas y hallazgos de las secciones 1 y 2 se refieren a GestProy.
- **DP-10:** **PHP 8.3**. GestProy fija `config.platform.php = 8.3.0` y el lock quedó con Symfony 7.4 LTS.
- **H-01 y H-02 corregidos** en esta rama: se quitó el submódulo `GestProy` sin `.gitmodules` y se regeneró el lock cambiando solo los paquetes de Symfony (8.1 → 7.4) más `symfony/polyfill-php83`.

Este documento es el entregable de la Fase 0. No hay código nuevo del sistema de estibas todavía. Todo lo que se dice sobre el repositorio está verificado leyendo archivos o ejecutando comandos; lo que no se pudo verificar se marca como tal.

## 1. Qué hay en el repositorio

El repositorio es **GestProy**, la aplicación de gestión de proyectos del área de Tecnología y Estrategias Digitales (Tecnoalsa). No contiene nada del dominio de producción, estibas, CEDI ni SAP.

### 1.1 Inventario técnico

| Componente | Declarado en `composer.json` / `package.json` | Versión en el lock |
|---|---|---|
| PHP | `^8.3` | ver hallazgo H-02 |
| laravel/framework | `^13.17` | 13.33.0 |
| livewire/livewire | `^4.1` | 4.4.6 |
| livewire/flux (UI) | `^2.13.1` | 2.20.0 |
| laravel/fortify (auth, 2FA, passkeys) | `^1.37.2` | 1.40.0 |
| spatie/laravel-permission | `^8.3` | 8.3.0 |
| bacon/bacon-qr-code (dependencia de Fortify) | transitiva | 3.1.1 |
| phpunit/phpunit | `^12.5.23` | 12.5.35 |
| larastan (PHPStan nivel 7), pint | dev | |
| Tailwind CSS | `^4.0.7` con `@tailwindcss/vite` | |
| Vite | `^8.0.0` (vía `vite-plus`) | |
| Node | 22 en CI | |
| PostgreSQL | 16 en CI, "14 o superior" en `docs/despliegue.md` | |

Entorno de esta sesión: PHP 8.3.6 CLI con `pdo_pgsql`, `pgsql`, `intl` y `mbstring` (sin `bcmath`, ver H-04), PostgreSQL 16.15, Node 22.22, Composer 2.8.12.

### 1.2 Arquitectura actual

- **Organización:** Laravel estándar, no por dominios. `app/Actions/{Projects,Tasks,...}` para casos de uso, `app/Queries/Projects` para lecturas paginadas, `app/Services/Projects` para cálculo puro, `app/Policies` para autorización por registro, enums en `app/Enums`.
- **UI:** componentes Livewire 4 de un solo archivo (`resources/views/pages/**/⚡*.blade.php`) con Flux UI. Rutas con `Route::livewire(...)`. Layout con barra lateral en `resources/views/layouts/app/sidebar.blade.php`.
- **Autenticación:** Fortify con 2FA y passkeys, registro público deshabilitado, middleware `EnsureUserIsActive` y `SecurityHeaders`.
- **Permisos:** spatie/laravel-permission sin teams. Los enums `Role` y `Permission` son la fuente de verdad y `RolesAndPermissionsSeeder` los sincroniza.
- **Auditoría:** `audit_logs` (append-only por convención, `action` de 40 caracteres, polimórfica, `old_values`/`new_values` en jsonb, IP) escrita por `App\Services\AuditLogger`. Los cambios de proyectos van aparte en `project_activity_logs`.
- **Concurrencia:** ya hay un precedente bueno: `ProjectCodeGenerator` usa `pg_advisory_xact_lock` dentro de la transacción para el consecutivo.
- **Colas:** driver `database`, worker con Supervisor y cron de `schedule:run` documentados en `docs/despliegue.md`.
- **API:** no existe `routes/api.php`, ni Sanctum, ni autenticación de dispositivos.
- **Pruebas:** PHPUnit contra PostgreSQL real (`gestproy_testing`), no SQLite. Bien para lo que pide este sistema.
- **Despliegue:** guía para Ubuntu 24.04 / Debian 12 con Nginx, PHP-FPM, Supervisor, cron y respaldos con `pg_dump`.

### 1.3 Componentes reutilizables

| Existe | Se puede usar para |
|---|---|
| Fortify, usuarios, desactivación, 2FA | Login de operarios y administradores |
| spatie/permission + enums | Roles y permisos granulares nuevos |
| Patrón Actions + Policies + Queries | Casos de uso de estibas, recibos, auditorías y traslados |
| `pg_advisory_xact_lock` | Serializar por clave de negocio (orden + producto + lote) |
| `AuditLogger` / `audit_logs` | Base de la bitácora, pero hay que ampliarla (H-06) |
| bacon/bacon-qr-code | Generar el QR sin agregar dependencias |
| Notificaciones (database, mail, Teams) | Alertas de discrepancias y conciliaciones |
| CI con PostgreSQL 16 | Pruebas de restricciones y concurrencia, una vez se arregle (H-01) |
| `docs/despliegue.md` | Base del despliegue, hay que extenderla con workers SAP y conciliación |

## 2. Hallazgos que bloquean antes de escribir código

**H-01. El CI de `main` está roto desde el commit `bd53682` ("Icono").** Ese commit agregó una entrada `GestProy` de tipo submódulo (modo 160000) sin `.gitmodules`. El checkout de GitHub Actions falla con `fatal: No url found for submodule path 'GestProy' in .gitmodules` (corrida 36174503498 sobre `5fd53a1`). Desde entonces ninguna prueba corre en CI. La corrección es `git rm --cached GestProy` (es un puntero vacío, la carpeta local está vacía). No lo hice en esta fase porque toca el historial de otra funcionalidad; es un cambio de una línea y lo recomiendo como primer commit de la Fase 1.

**H-02. El `composer.lock` no instala en PHP 8.3.** El lock tiene Symfony 8.1, que exige PHP 8.4.1. `composer.json`, el CI (`php-version: '8.3'`) y `docs/despliegue.md` dicen PHP 8.3. Resultado: `composer install` falla en este entorno y fallaría en CI apenas se arregle H-01. Verifiqué en una copia aparte que agregando `config.platform.php = 8.3.6` el lock se resuelve con Symfony 7.4 (LTS) y Laravel 13.37. Hay que decidir (DP-10): fijar plataforma 8.3 o subir el servidor a PHP 8.4.

**H-03. No hay acceso a SAP Business One.** No hay credenciales, URL de Service Layer, ni documentación del entorno en el repo. Por regla del proyecto no se programan mapeadores reales. Se trabajará con un contrato (`SapGateway`) y un adaptador simulado.

**H-04. Extensión `bcmath` no está en este entorno.** Las conversiones caja → unidad SAP deben hacerse con decimales exactos. Se puede usar `numeric` de PostgreSQL y aritmética de enteros escalados, o `bcmath`, que la guía de despliegue ya pide en producción. Queda pendiente confirmarla en el servidor real.

**H-05. Ubicación del sistema (DP-00).** GestProy es una herramienta administrativa de un área. El sistema de estibas es operación de planta con sensores, SAP y CEDI. Meterlo en la misma aplicación ahorra autenticación y despliegue, pero acopla disponibilidad, credenciales SAP y ciclos de despliegue a una app que no es crítica. Ver DP-00.

**H-06. `audit_logs` no alcanza para la auditoría exigida.** Le falta identidad de dispositivo (solo `user_id`), identificador de correlación, origen, resultado, referencias SAP, y no hay nada en la base que impida un `UPDATE` o `DELETE`. Propuesta: una tabla propia `trace_events` con esos campos y un trigger de PostgreSQL que rechace `UPDATE` y `DELETE`.

## 3. Arquitectura propuesta

### 3.1 Principio general

Se respeta lo que ya usa el repo: Actions para casos de uso, Queries para lecturas, Policies para autorización, enums con CHECK en PostgreSQL, Livewire de un solo archivo para pantallas. Se agrega organización por dominio solo donde hay lógica de dominio real:

```
app/
  Domain/Traceability/
    Pallets/        Actions (ProcessScanEvent, CloseManually, ConfirmPalletized, ConfirmLabeled, ReprintLabel),
                    Enums (PhysicalStatus, CloseType, ...), Exceptions, PalletCodeGenerator
    Receipts/       Actions (RequestProductionReceipt, ReconcileProductionReceipt), Jobs
    Audits/         Actions (StartBlindCount, SubmitBlindCount, ResolveDiscrepancy)
    Transfers/      Actions (RequestTransfer, ReconcileTransfer), Jobs
    Standards/      sincronización y lectura del estándar vigente
  Integrations/Sap/
    Contracts/SapGateway.php      interfaz única hacia SAP
    Fake/FakeSapGateway.php       adaptador simulado con escenarios (ok, error, timeout, documento ya existe)
    ServiceLayer/                 cliente HTTP, sesión, mappers (solo cuando haya entorno verificado)
    Dto/                          entradas y salidas sin tipos de Eloquent
  Http/Controllers/Api/ScanEventController.php    único endpoint HTTP clásico (sensores)
  Models/Traceability/...
resources/views/pages/traceability/...            pantallas Livewire
```

Nada de repositorios genéricos: Eloquent + Actions alcanza.

### 3.2 Estados como dimensiones separadas

En `pallets` cuatro columnas, cada una con su CHECK:

| Columna | Valores |
|---|---|
| `physical_status` | EN_ESTIBADO, ESTIBADO_FINALIZADO, PALETIZADO, ETIQUETADO |
| `receipt_status` | SIN_RECIBO, SOLICITADO, PENDIENTE_CONCILIACION, CONFIRMADO_04, FALLIDO |
| `audit_status` | PENDIENTE, EN_CONTEO, APROBADA, DISCREPANCIA |
| `transfer_status` | NO_ELEGIBLE, LISTO, SOLICITADO, PENDIENTE_CONCILIACION, CONFIRMADO_05, FALLIDO |

Estas columnas son el estado actual para consultar rápido. La historia vive en `pallet_scan_events`, `production_receipt_pallets`, `pallet_audits`, `inventory_transfer_pallets` y `trace_events`. Cada enum tiene `canTransitionTo()` y las Actions la usan dentro de la transacción después de `SELECT ... FOR UPDATE`.

### 3.3 Motor de estibado y concurrencia

1. El controlador autentica el dispositivo, valida estructura y responde rápido. Todo lo demás va en `ProcessScanEvent`.
2. Dentro de una transacción:
   - `INSERT` del evento en `pallet_scan_events` con `ON CONFLICT (device_id, external_event_id) DO NOTHING`. Si no insertó, es duplicado: se responde igual que la primera vez y no se toca nada. El alcance de la unicidad depende de DP-01.
   - `pg_advisory_xact_lock(hash(orden, producto, lote))` para serializar esa combinación.
   - Buscar la estiba activa con `FOR UPDATE`. Un índice único parcial `UNIQUE (production_order_ref, item_code, lot_code) WHERE physical_status = 'EN_ESTIBADO'` garantiza en la base que no haya dos activas aunque falle el bloqueo.
   - Si no hay, se crea con consecutivo nuevo y el estándar congelado (`standard_boxes_snapshot`, `standard_version`).
   - `UPDATE pallets SET boxes_count = boxes_count + 1 ... WHERE id = ? AND boxes_count < standard_boxes_snapshot RETURNING boxes_count`. CHECK `boxes_count BETWEEN 0 AND standard_boxes_snapshot`.
   - Si `boxes_count = standard`, pasa a ESTIBADO_FINALIZADO con cierre AUTOMATICO en la misma sentencia.
3. Lo que pasa después (notificaciones, refresco de pantallas) se encola dentro de la misma transacción. Con el driver de cola `database` en la misma conexión PostgreSQL, el `INSERT` en `jobs` es parte de la transacción y funciona como outbox: si hay rollback no queda trabajo huérfano, si hay commit no se pierde. Esto deja de ser cierto si se cambia a Redis; se documentará como invariante y tendrá una prueba. Si infraestructura exige Redis, se agrega una tabla `outbox_messages`.

**Cierre manual vs. escaneo simultáneo:** los dos toman el mismo advisory lock y el `FOR UPDATE` de la estiba. El que llegue segundo ve el estado ya cambiado. Si el cierre gana, el escaneo abre una estiba nueva. Si el escaneo completa la estiba, el cierre manual se rechaza porque ya no está EN_ESTIBADO.

**Consecutivo:** tabla `pallet_code_counters` con una fila por prefijo y `FOR UPDATE`, dentro de la misma transacción. Así no hay huecos ni reutilización. Una `SEQUENCE` de PostgreSQL tampoco reutiliza, pero deja huecos en rollback; si al negocio no le importan los huecos se puede usar. El formato del código depende de DP-07.

**Sin borrado lógico en estibas.** Una estiba anulada cambia de estado según DP-08, nunca se borra, así el código queda único para siempre.

### 3.4 SAP: idempotencia y conciliación

- Cada recibo o traslado se crea local con `request_uuid` y `payload_hash` **antes** de llamar a SAP, en estado SOLICITADO, y sus estibas quedan reservadas por filas en `production_receipt_pallets` / `inventory_transfer_pallets` con estado. Un índice único parcial `UNIQUE (pallet_id) WHERE status IN ('RESERVADO','SOLICITADO','PENDIENTE_CONCILIACION','CONFIRMADO')` impide que una estiba esté en dos operaciones activas o confirmadas.
- Un job hace la llamada. Respuesta clara de éxito con DocEntry: se consulta el documento para verificar que corresponde (orden, líneas, cantidades, lotes) y solo ahí pasa a CONFIRMADO. Error de negocio claro: FALLIDO y se liberan las reservas. Timeout, conexión cortada o respuesta ambigua: PENDIENTE_CONCILIACION, sin reintento de escritura.
- Un job de conciliación busca el documento en SAP por la correlación externa. Si existe y corresponde, confirma. Si se demuestra que no existe, vuelve a permitir el envío con el mismo `request_uuid`. Si no se puede demostrar ninguna de las dos cosas, queda pendiente y alerta.
- **No se asume** que SAP acepte una cabecera de idempotencia. La correlación necesita un campo en el documento SAP donde guardar `request_uuid` (DP-09).
- Por la documentación pública de Service Layer, los candidatos son `InventoryGenEntries` (recibo de producción contra `ProductionOrders`) y `StockTransfers` (traslado). **No están verificados contra el entorno de Tecnoalsa** y no se programarán mapeadores con eso hasta tener acceso.

### 3.5 Conteo ciego

- El componente Livewire del operario solo tiene como propiedades públicas el token del QR leído, el número contado y el resultado. Nunca carga la estiba como modelo público ni `boxes_count`, `standard_boxes_snapshot` o porcentaje.
- La consulta para el operario usa un DTO propio (`PalletForBlindCount`) sin cantidades.
- `SubmitBlindCount` compara en el servidor y devuelve solo `APROBADA` o `DISCREPANCIA`. Los mensajes de validación no mencionan la cantidad esperada.
- Pruebas que revisan el HTML renderizado, el snapshot de Livewire y las respuestas JSON buscando la cantidad esperada.

### 3.6 QR

El QR lleva un token opaco aleatorio (`pallets.qr_token`, 128 bits, único), no el id ni el consecutivo ni cantidades. Al escanearlo el servidor busca la estiba y valida estado y permisos. El QR no autoriza nada por sí mismo.

### 3.7 Sensores

- Tabla `devices` con token por dispositivo guardado como hash, estado activo/inactivo y última conexión.
- Endpoint `POST /api/traceability/scan-events`, `throttle` por dispositivo, solo HTTPS.
- Si el dispositivo puede firmar, HMAC del cuerpo más marca de tiempo para evitar repeticiones. Si la red de planta lo permite, mTLS en Nginx. Depende de DP-01.

### 3.8 Roles nuevos

| Rol | Alcance |
|---|---|
| traceability_admin | Tablero, cierres, resolución de discrepancias, recibos, traslados, conciliación |
| production_operator | Pantalla de producción, cierre manual, paletizado, etiquetado, reimpresión |
| logistics_operator | Lectura de QR y conteo ciego. No resuelve discrepancias |
| traceability_viewer | Consulta del tablero, sin cantidades del conteo en curso |

El `admin` actual de GestProy no debería tener estos permisos por defecto si el sistema queda en la misma app (DP-00).

## 4. Requisitos y matriz de trazabilidad

El prompt maestro cita CA-01 a CA-18 pero no trae su texto. Los identificadores de abajo los propongo yo a partir de las secciones del prompt. **Si existe un documento oficial de requisitos con otra numeración, hay que pasármelo y esta tabla se ajusta.**

### 4.1 Requisitos funcionales

| ID | Requisito | Componente | Fase |
|---|---|---|---|
| RF-01 | Recibir eventos de escaneo de sensores | `ScanEventController`, `devices` | 2 |
| RF-02 | Validar producto, lote y orden | `ProcessScanEvent`, maestros | 2 |
| RF-03 | Crear estibas automáticamente | `ProcessScanEvent`, `PalletCodeGenerator` | 2 |
| RF-04 | Acumular cajas con eventos únicos | `pallet_scan_events` | 2 |
| RF-05 | Finalizar al alcanzar el estándar | `ProcessScanEvent` | 2 |
| RF-06 | Cierre manual de estibas incompletas | `CloseManually` | 2 |
| RF-07 | Confirmar paletizado | `ConfirmPalletized` | 3 |
| RF-08 | Etiqueta con QR, impresión y reimpresión | `LabelRenderer`, `ReprintLabel` | 3 |
| RF-09 | Confirmar etiqueta aplicada | `ConfirmLabeled` (bloqueado por DP-03) | 3 |
| RF-10 | Tablero administrativo | `PalletIndexQuery`, página tablero | 3 |
| RF-11 | Recibos de producción en SAP | `RequestProductionReceipt`, job | 4 |
| RF-12 | Conciliar recibos inciertos | `ReconcileProductionReceipt` | 4 |
| RF-13 | Escanear QR en CEDI | página de recepción | 5 |
| RF-14 | Validar identidad y estado en servidor | `StartBlindCount` | 5 |
| RF-15 | Conteo físico ciego | `SubmitBlindCount`, DTO sin cantidades | 5 |
| RF-16 | Bloquear traslado si hay discrepancia | `transfer_status`, `RequestTransfer` | 5 |
| RF-17 | Novedades para revisión administrativa | `discrepancy_cases`, `ResolveDiscrepancy` | 5 |
| RF-18 | Traslado 04 → 05 en SAP | `RequestTransfer`, job | 6 |
| RF-19 | Traslado individual y agrupado | `RequestTransfer` (agrupado bloqueado por DP-05) | 6 |
| RF-20 | Conciliar traslados inciertos | `ReconcileTransfer` | 6 |
| RF-21 | Trazabilidad estiba → auditoría → documento → línea | relaciones `*_pallets`, vista de detalle | 6 |

### 4.2 Reglas de negocio

| ID | Regla (sección 3 del prompt) | Cómo se garantiza | Prueba prevista |
|---|---|---|---|
| RN-01 | Una estiba = una orden, un producto, un lote | Columnas no nulas, sin `UPDATE` de identidad (trigger) | `PalletIdentityIsImmutableTest` |
| RN-02 | Un evento cuenta como máximo una vez | Único `(device_id, external_event_id)` + `ON CONFLICT` | `DuplicateScanEventTest` |
| RN-03 | Sin id confiable no hay producción | Bandera de configuración que bloquea el endpoint hasta cerrar DP-01 | `ScanEndpointDisabledTest` |
| RN-04 | Estándar validado, > 0 y vigente | CHECK y validación en `ProcessScanEvent` | `MissingStandardRejectsEventTest` |
| RN-05 | Estándar congelado al crear | `standard_boxes_snapshot` | `StandardChangeDoesNotAlterPalletsTest` |
| RN-06 | Consecutivo único y nunca reutilizado | Contador con `FOR UPDATE`, único, sin borrado | `PalletCodeConcurrencyTest` |
| RN-07 | Nada es "confirmado en SAP" por solo enviar | Confirmación solo tras verificar documento | `ReceiptConfirmedOnlyAfterVerificationTest` |
| RN-08 | No recibir ni trasladar dos veces | Único parcial en `*_pallets` | `PalletCannotBeReceivedTwiceTest` |
| RN-09 | Una estiba en un solo recibo y un solo traslado confirmados | Único parcial | ídem |
| RN-10 | Estados independientes | Cuatro columnas con CHECK | `PalletStateDimensionsTest` |
| RN-11 | SAP es la fuente oficial del inventario | No hay ajustes de inventario locales | revisión + `NoInventoryAdjustmentTest` |
| RN-12 | Concurrencia protegida | Transacciones, locks, restricciones | `tests/Concurrency/*` con procesos reales |
| RN-13 | Toda operación crítica auditada | `trace_events` desde cada Action | una aserción por Action |
| RN-14 | Discrepancias sin ajustes automáticos | `ResolveDiscrepancy` no toca cantidades SAP | `DiscrepancyDoesNotAdjustInventoryTest` |
| RN-15 | No retroceder estados confirmados | Transiciones validadas + trigger | `ConfirmedStatesCannotGoBackTest` |
| RN-16 | Sin secretos en logs | Procesador de logs que enmascara, DTO sin credenciales | `SapCredentialsNotLoggedTest` |
| RN-17 | Nada pendiente se implementa supuesto | Banderas apagadas por DP | `PendingDecisionFlagsTest` |
| RN-18 | Toda regla con prueba | Esta matriz | revisión en cada fase |

### 4.3 Criterios de aceptación propuestos

| ID | Criterio verificable | Pruebas |
|---|---|---|
| CA-01 | El primer evento válido de una combinación crea una estiba con el estándar congelado | `FirstScanCreatesPalletTest` |
| CA-02 | Cada evento válido suma exactamente una caja; un duplicado no suma | `ScanIncrementsOneBoxTest`, `DuplicateScanEventTest` |
| CA-03 | Al llegar al estándar la estiba se finaliza una sola vez y el siguiente evento abre otra | `AutoCloseExactlyOnceTest` |
| CA-04 | N eventos concurrentes de la misma combinación dejan una sola estiba activa y la suma exacta | `ConcurrentScansTest` (PostgreSQL, procesos paralelos) |
| CA-05 | No se mezclan órdenes, productos ni lotes | `NoMixingTest` |
| CA-06 | Estándar ausente, inválido o vencido rechaza el evento y deja la causa | `MissingStandardRejectsEventTest` |
| CA-07 | El cierre manual exige entre 1 y estándar − 1 cajas y conserva la cantidad real | `ManualCloseTest` |
| CA-08 | No se paletiza sin finalizar ni se etiqueta sin paletizar | `PhysicalTransitionsTest` |
| CA-09 | La reimpresión no cambia identidad ni cantidad y queda auditada | `ReprintTest` |
| CA-10 | El QR solo identifica; manipularlo no evade validaciones | `QrTamperingTest` |
| CA-11 | Recibo agrupado y recibos individuales de tres estibas con conversión correcta | `ProductionReceiptGroupingTest` |
| CA-12 | Un timeout deja PENDIENTE_CONCILIACION y no hay segunda creación ciega | `ReceiptTimeoutTest` |
| CA-13 | La conciliación encuentra el documento existente y confirma sin duplicar | `ReceiptReconciliationTest` |
| CA-14 | Solo se audita una estiba con recibo confirmado y no trasladada | `BlindCountEligibilityTest` |
| CA-15 | El operario no puede obtener la cantidad esperada antes de enviar el conteo por ninguna vía | `BlindCountDoesNotLeakTest` |
| CA-16 | Conteo distinto: discrepancia, traslado bloqueado, novedad y aviso al administrador | `DiscrepancyFlowTest` |
| CA-17 | Traslado 04 → 05 solo de estibas aprobadas, sin duplicados, con relación estiba-documento-línea | `TransferTest`, `GroupedTransferTest` |
| CA-18 | Solo una confirmación verificada de SAP marca CONFIRMADO_05; errores y timeouts conservan el estado real | `TransferReconciliationTest` |

## 5. Decisiones pendientes

Las DP-01 a DP-08 vienen del prompt. Agregué DP-00, DP-09 y DP-10 porque salieron de la inspección.

### DP-00. Dónde vive el sistema

- **Problema:** el repo es GestProy (gestión de proyectos TED). El sistema de estibas es operación de planta.
- **Riesgo de no decidir:** si se mete aquí, una caída o un despliegue de GestProy para la planta, y las credenciales SAP quedan en la misma app que usa toda el área administrativa.
- **Alternativas:** (a) módulo dentro de GestProy; (b) aplicación nueva en un repo nuevo, mismo stack, copiando los patrones; (c) mismo repo pero despliegue separado (difícil de mantener).
- **Recomendación:** (b). Disponibilidad, permisos y ciclo de cambios distintos. Se reusa el conocimiento, no el proceso.
- **Pregunta:** ¿el sistema de estibas va en una aplicación y repositorio nuevos, o se integra como módulo de GestProy?
- **Afecta:** todo. **Pruebas:** ninguna específica; cambia dónde corre todo.

### DP-01. Identificador del sensor, orden de eventos y eventos tardíos

- **Problema:** la deduplicación depende de que el sensor mande un id único y estable. No sé si lo manda, si es único por equipo o global, ni si se reinicia.
- **Riesgo:** sin esto, un reenvío del sensor suma cajas de más o un id repetido tras reinicio descarta cajas reales.
- **Alternativas:** (a) id del sensor único por dispositivo → único `(device_id, external_event_id)`; (b) id generado por un middleware de planta (UUID) persistido antes de enviar; (c) sin id: hash de dispositivo + timestamp + lectura, que **no** es confiable con lectores de alta velocidad.
- **Recomendación:** (a) o (b). Los eventos se asignan a la estiba activa al momento de **procesar**, no según `event_occurred_at`; un evento con hora anterior al cierre de la estiba previa se marca como tardío en el registro pero se cuenta en la activa. Un evento rechazado no se reprocesa solo.
- **Pregunta:** ¿qué marca y modelo de sensor o lector se usa, cómo envía los eventos (HTTP, OPC-UA, archivo, PLC), trae un id único y cuál es su alcance?
- **Afecta:** `pallet_scan_events`, `ScanEventController`, `devices`. **Pruebas:** `DuplicateScanEventTest`, `LateEventTest`, `DeviceIdScopeTest`.

### DP-02. Fuente de órdenes y lotes, factor caja/unidad y unidades de medida

- **Problema:** hay que saber de dónde sale cada dato: órdenes de producción (¿SAP `ProductionOrders`?), lotes (¿los crea SAP o planta?), estándar de cajas por estiba (¿qué campo o UDF del artículo?) y factor caja → unidad de inventario.
- **Riesgo:** recibir cantidades mal convertidas en SAP es un error de inventario real.
- **Alternativas:** caché local sincronizada desde SAP cada X minutos con versión, o consulta en línea con caché corta.
- **Recomendación:** tabla local `pallet_standards_sync` como caché versionada de solo lectura, con `standard_version` congelado en la estiba. El escaneo no debe depender de que SAP responda en ese momento.
- **Pregunta:** ¿en qué campo de SAP está el estándar de cajas por estiba y el factor caja/unidad? ¿La unidad de inventario del artículo es la caja, la unidad o el kilo? ¿El lote existe en SAP antes de producir?
- **Afecta:** maestros, `ProcessScanEvent`, recibos. **Pruebas:** `UnitConversionTest`, `StandardVersioningTest`.

### DP-03. Confirmación explícita de etiqueta aplicada

- **Problema:** ¿basta con imprimir para considerar la estiba etiquetada, o alguien debe confirmar que la pegó?
- **Riesgo:** dar por etiquetada una estiba sin etiqueta física rompe la lectura en CEDI.
- **Alternativas:** (a) etiquetado al imprimir; (b) confirmación por botón; (c) confirmación escaneando el QR recién pegado.
- **Recomendación:** (c). Prueba que la etiqueta está físicamente y es legible.
- **Pregunta:** ¿el operario debe confirmar la etiqueta pegada, y de qué forma?
- **Afecta:** `ConfirmLabeled`, estados físicos, recibos (solo ETIQUETADO se recibe). **Pruebas:** `LabelConfirmationTest`.

### DP-04. Resolución de discrepancias

- **Problema:** qué puede hacer el administrador ante una discrepancia.
- **Riesgo:** sin procedimiento se terminan "arreglando" cantidades sin control.
- **Alternativas:** reauditoría por otro operario; corrección documentada con ajuste hecho en SAP por el área responsable; rechazo físico y devolución a planta.
- **Recomendación:** reauditoría obligatoria por un operario distinto antes de cualquier otra acción. Si se confirma la diferencia, el sistema no ajusta inventario: registra la resolución y la referencia del documento SAP que haga el área de inventarios.
- **Pregunta:** ¿qué acciones están autorizadas, quién las aprueba y se exige reauditoría por otra persona?
- **Afecta:** `ResolveDiscrepancy`, `discrepancy_cases`, permisos. **Pruebas:** `DiscrepancyResolutionTest`, `OperatorCannotResolveOwnDiscrepancyTest`.

### DP-05. Activación del traslado agrupado

- **Problema:** cuándo se agrupan estibas en un solo traslado.
- **Riesgo:** un documento grande que falla deja varias estibas pendientes; uno mal agrupado mezcla lotes.
- **Alternativas:** por turno, por cantidad, manual con confirmación.
- **Recomendación:** individual por defecto. Agrupado solo manual por administrador, misma combinación producto + lote + almacenes, con confirmación explícita.
- **Pregunta:** ¿quién decide agrupar, con qué límite y en qué momento?
- **Afecta:** `RequestTransfer`. **Pruebas:** `GroupedTransferTest`.

### DP-06. Cierre de órdenes en SAP con recepciones parciales

- **Problema:** si SAP cierra la orden al primer recibo o al llegar a la cantidad planeada, los recibos siguientes fallan.
- **Riesgo:** estibas producidas sin poder recibirse.
- **Pregunta:** ¿quién cierra la orden en SAP y cuándo? ¿Se permite recibir más de lo planeado?
- **Recomendación:** la app nunca cierra órdenes; consulta el estado real de la orden antes de cada recibo.
- **Afecta:** `RequestProductionReceipt`. **Pruebas:** `PartialReceiptTest`, `ClosedOrderRejectsReceiptTest`.

### DP-07. Etiqueta física, impresora y protocolo

- **Problema:** no sé la impresora, el tamaño ni el protocolo.
- **Riesgo:** imprimir desde navegador en una impresora térmica suele salir mal escalado.
- **Alternativas:** (a) PDF/HTML desde el navegador; (b) ZPL directo a una Zebra por red (puerto 9100); (c) servicio de impresión local.
- **Recomendación:** (b) si son Zebra; con vista previa HTML para pruebas.
- **Pregunta:** ¿marca y modelo de impresora, tamaño de etiqueta, cómo se conecta, y qué formato tiene que tener el consecutivo?
- **Afecta:** `LabelRenderer`, `PalletCodeGenerator`. **Pruebas:** `LabelContentTest`.

### DP-08. Reversión de documentos y estibas anuladas o devueltas

- **Problema:** qué pasa si SAP cancela un recibo o un traslado, o una estiba se devuelve.
- **Riesgo:** estados locales que dicen "recibido" cuando SAP ya no lo tiene.
- **Recomendación:** no habrá reversión hasta definir el procedimiento. Cuando exista, será una operación nueva que referencia el documento de cancelación de SAP, nunca un cambio de estado hacia atrás.
- **Pregunta:** ¿cómo se cancelan hoy esos documentos en SAP y quién lo autoriza?
- **Afecta:** todos los estados confirmados. **Pruebas:** `ConfirmedStatesCannotGoBackTest`, más las del procedimiento cuando exista.

### DP-09. Acceso a SAP y campo de correlación

- **Problema:** sin entorno de SAP no se pueden verificar objetos, campos, lotes, almacenes 04/05, ni cómo buscar un documento por nuestro `request_uuid`.
- **Riesgo:** sin correlación, la conciliación después de un timeout no es inequívoca y se corre el riesgo de duplicar documentos.
- **Recomendación:** que el administrador de SAP cree un campo de usuario (UDF) en la cabecera de los dos documentos para guardar `request_uuid`, filtrable por Service Layer.
- **Pregunta:** ¿hay un ambiente de pruebas de SAP B1 con Service Layer? ¿Qué versión? ¿Se puede crear el UDF? ¿Los códigos de almacén son literalmente "04" y "05"?
- **Afecta:** `Integrations/Sap/ServiceLayer`. **Pruebas:** las de integración contra el ambiente de pruebas.

### DP-10. PHP 8.3 o 8.4

- **Problema:** H-02. El lock pide 8.4, todo lo demás dice 8.3.
- **Alternativas:** fijar `config.platform.php = 8.3` (Symfony 7.4 LTS, verificado que resuelve) o subir servidor y CI a PHP 8.4.
- **Recomendación:** lo que tenga el servidor de producción aprobado por infraestructura. Si es Ubuntu 24.04 con paquetes del sistema, eso es 8.3 → fijar plataforma.
- **Pregunta:** ¿qué versión de PHP va a tener el servidor de producción?

## 6. Riesgos principales

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Sensor sin id confiable | Sobreconteo o pérdida de cajas | Endpoint deshabilitado hasta cerrar DP-01 |
| SAP sin correlación externa | Documentos duplicados tras un timeout | UDF (DP-09); sin eso no se habilitan escrituras |
| Fuga de la cantidad esperada en el conteo ciego | Auditoría inútil | DTO sin cantidades + pruebas sobre HTML, snapshot y JSON |
| Concurrencia en estibas activas | Estibas duplicadas | Advisory lock + único parcial + pruebas con procesos paralelos |
| Conversión de unidades errada | Inventario SAP mal | Factor versionado, `numeric`, pruebas con valores reales de DP-02 |
| CI roto (H-01, H-02) | Cambios sin verificar | Arreglar como primera tarea de la Fase 1 |
| Acoplamiento con GestProy | Caídas cruzadas | DP-00 |

## 7. Plan de implementación

| Fase | Contenido | Depende de |
|---|---|---|
| 1 | Arreglar CI (H-01, H-02). Migraciones, enums con transiciones, restricciones, `trace_events` inmutable, roles, factories, contrato `SapGateway` y `FakeSapGateway`. Pruebas de invariantes | DP-00, DP-10 |
| 2 | Endpoint de sensores, idempotencia, motor de estibado, cierre manual, pruebas de concurrencia reales | DP-01 para habilitarlo en producción |
| 3 | Pantalla de producción, paletizado, etiqueta con QR, reimpresión, tablero | DP-03, DP-07 |
| 4 | Recibos con adaptador simulado, reservas, jobs, conciliación | DP-02, DP-06, DP-09 para SAP real |
| 5 | Lectura de QR, conteo ciego, discrepancias | DP-04 |
| 6 | Traslados individuales y agrupados, conciliación | DP-05, DP-09 |
| 7 | Concurrencia, seguridad, despliegue, informe de cumplimiento | todo lo anterior |

Lo que se puede hacer ya sin esperar respuestas: Fase 1 completa (salvo dónde vive, DP-00) y el motor de la Fase 2 detrás de una bandera apagada.

## 8. Estado de verificación

| Elemento | Estado |
|---|---|
| Inspección del repositorio | Hecha |
| Falla del CI en `main` | Verificada en los logs de la corrida 36174503498 |
| Incompatibilidad del lock con PHP 8.3 | Verificada con `composer install` |
| Resolución con plataforma 8.3 | Verificada en una copia, no aplicada al repo |
| Suite actual de pruebas | 221 pruebas, 221 pasan, 1123 aserciones, contra PostgreSQL 16.15 (ver nota) |
| SAP | Sin acceso. Nada verificado |

**Cómo se obtuvo la línea base.** En una copia fuera del repo, con estos cambios que **no** están aplicados aquí:

1. Lock regenerado con `config.platform.php = 8.3.6` (H-02).
2. Sin `larastan/larastan`: `phpstan/phpstan` solo se publica como zip en `api.github.com`, y ese host devuelve 403 desde esta sesión. Por eso PHPStan no se corrió.
3. Un `public/build/manifest.json` mínimo: `npm run build` falla porque `fonts.bunny.net` está bloqueado por el proxy de la sesión. Las pruebas no necesitan los assets reales.

Pint tampoco se corrió sobre el código existente. Ninguna de estas limitaciones aplica al CI de GitHub, que sí tiene acceso a esos hosts.

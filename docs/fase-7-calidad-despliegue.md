# Fase 7: calidad, seguridad, observabilidad y despliegue

## 1. Objetivo

Cerrar lo que el prompt pide para operar: alertas, health checks, revisión de seguridad, configuración y guía de despliegue en Linux con Nginx, PHP-FPM y PostgreSQL, y un informe que conecte cada requisito con sus pruebas.

## 2. Qué se hizo

| Tema | Componentes |
|---|---|
| Alertas de la sección 13 | `OperationalChecks` (12 revisiones), `ops:check` cada 5 min, notificación `OperationalAlert` con enfriamiento, pantalla `/operations`, permiso `ops.alerts`, `config/operations.php` |
| Latidos | El scheduler guarda el suyo y encola `RecordWorkerHeartbeat` en cada cola cada minuto |
| Duplicados anormales | `EventCounter`: contador por minuto en caché, porque un duplicado no deja fila nueva |
| Health check | `/api/health`: base de datos, scheduler y workers, solo `ok` o `fail`, 503 si algo falla |
| Verificación previa | `app:preflight`: debug, HTTPS, cookies, cola, caché, SAP real, base; lista las DP apagadas; falla si algo no es seguro |
| Logs estructurados | `StructuredLogging`: JSON por línea, `correlation_id`, secretos enmascarados, sin trazas de pila |
| Seguridad | Pruebas de errores internos ocultos, asignación masiva, cabeceras, nada privado en `public/`. `composer audit` y `npm audit` |
| Despliegue | `deploy/`: Nginx, pool de PHP-FPM, `php.ini`, Supervisor (dos workers), cron, `deploy.sh`, `rollback.sh`, `backup.sh` |
| Documentación | `docs/despliegue.md` reescrita, `docs/operacion.md` (manual de soporte y conciliación), `docs/informe-cumplimiento.md` |
| Informe verificable | `ComplianceReportTest` falla si el informe cita una prueba que no existe (probado renombrando dos) |

## 3. Decisiones y supuestos

- **Alertas dentro de la aplicación y por correo.** La campana existente más el correo para quien lo tenga activo. Teams o SMS no están: no hay decisión ni credenciales. El canal de Laravel se puede agregar después sin tocar las revisiones.
- **La notificación de alerta no va por cola.** Si el problema es que no hay workers, una notificación encolada nunca saldría.
- **Si el scheduler muere, no hay alertas.** Por eso `/api/health` revisa el scheduler, y la guía exige monitorearlo desde afuera.
- **Umbrales iniciales** (10 rechazos o 50 duplicados en 15 min, 30 min pendiente, 100 jobs o 10 min de espera, 3 min sin latido) son un punto de partida. Hay que ajustarlos con el volumen real de la planta.
- **Dos workers separados** (`sap` y `default`), así un SAP lento no frena correos. `--timeout=75` < `retry_after=90`.
- **Despliegue por carpetas de versión y symlink.** Cambio atómico, rollback inmediato, los jobs siguen en la base. Las migraciones deben ser compatibles hacia atrás; el rollback no las revierte.
- **`tinypool` sigue con aviso en `npm audit`.** Viene dentro de `vite-plus` 0.3.0, que el proyecto fija. Solo corre al compilar assets en la máquina de build; no llega al servidor ni al navegador. Corregí `shell-quote` y `source-map-js` con `npm audit fix` (sin `--force`).
- **CSRF** lo da Laravel en todas las rutas web y Livewire lo manda en cada petición. No tiene prueba propia porque Laravel lo desactiva en las pruebas.

## 4. Pruebas nuevas

| Archivo | Pruebas |
|---|---|
| `tests/Feature/Operations/OperationalChecksTest.php` | 11 |
| `tests/Feature/Operations/HealthAndPreflightTest.php` | 4 |
| `tests/Feature/Security/HardeningTest.php` | 5 |
| `tests/Feature/Docs/ComplianceReportTest.php` | 1 |

## 5. Resultados reales

PHP 8.3.6 y PostgreSQL 16.15, en esta sesión:

```
php artisan test             → 289 pruebas, 289 pasan (dos corridas seguidas)
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
composer audit               → sin avisos
npm audit --omit=dev         → queda 1 (tinypool, solo de compilación)
bash -n deploy/scripts/*.sh  → sin errores
```

No ejecutado:

- **Assets.** La compilación sigue bloqueada aquí porque `fonts.bunny.net` no resuelve, antes y después de actualizar `package-lock.json`. El CI de GitHub sí compila, así que ahí se verifica el cambio del lock.
- **Navegador.** Las pantallas no se revisaron en uno.
- **Servidor.** Los archivos de `deploy/` no se probaron en un servidor real. `shellcheck` no está instalado.

## 6. Pendientes para producción

Ver `docs/informe-cumplimiento.md`, secciones 9 y 10. Lo principal:

- **DP-09.** Hace falta el conector real de Service Layer. Sin él, `app:preflight` falla.
- **DP-01, DP-02 y DP-03.** Sin estas decisiones no entra producción ni hay recibos.
- **DP-08.** La reversión de documentos y las devoluciones no están implementadas.
- **Prueba en planta.** Falta probar con sensores, lectores e impresora reales, y hacer un despliegue de ensayo siguiendo `docs/despliegue.md`.

# Trazabilidad de estibas

Trazabilidad individual de estibas desde el primer escaneo de cajas en planta hasta la recepción en el almacén 04 y el traslado al 05, confirmados por SAP Business One.

**Stack:** Laravel 13 · Livewire 4 · PHP 8.3 · PostgreSQL 16 · Tailwind CSS 4 · Flux UI · spatie/laravel-permission.

## Estado

| Fase | Contenido | Estado |
|---|---|---|
| 0 | Diagnóstico, arquitectura, decisiones pendientes | Hecha ([docs/fase-0-diagnostico.md](docs/fase-0-diagnostico.md)) |
| 1 | Persistencia, estados, restricciones, bitácora, roles, contrato SAP y simulador | Hecha ([docs/fase-1-persistencia.md](docs/fase-1-persistencia.md)) |
| 2 | Motor de estibado (sensores, idempotencia, concurrencia) | Hecha, apagada hasta DP-01 ([docs/fase-2-motor-estibado.md](docs/fase-2-motor-estibado.md)) |
| 3 | Pantalla de producción, paletizado, etiquetas, tablero | Hecha; etiqueta aplicada espera DP-03 ([docs/fase-3-produccion.md](docs/fase-3-produccion.md)) |
| 4 | Recibos de producción en SAP | Hecha contra el simulador; SAP real espera DP-09 ([docs/fase-4-recibos.md](docs/fase-4-recibos.md)) |
| 5 | Conteo ciego y discrepancias | Hecha; resolución espera DP-04 ([docs/fase-5-auditoria.md](docs/fase-5-auditoria.md)) |
| 6 | Transferencia 04 → 05 | Hecha contra el simulador. Desde el cambio de proceso la hace el conteo ciego aprobado ([docs/fase-6-traslados.md](docs/fase-6-traslados.md)) |
| 7 | Calidad, alertas, seguridad y despliegue | Hecha; despliegue sin probar en un servidor ([docs/fase-7-calidad-despliegue.md](docs/fase-7-calidad-despliegue.md)) |
| + | Etiquetas de caja: consulta de OF en SAP, QR y ZPL 10 × 8 cm | Hecha contra el simulador; sin probar en una Zebra ([docs/etiquetas-de-caja.md](docs/etiquetas-de-caja.md)) |

Nada está validado contra SAP real: solo existe el simulador (`SAP_DRIVER=fake`), que se rechaza en producción.

## Puesta en marcha local

```bash
composer install
cp .env.example .env            # ajustar DB_PASSWORD
php artisan key:generate
php artisan migrate --seed      # roles y admin@example.com / password (solo fuera de producción)
npm install && npm run build
composer dev
```

Para ver el flujo completo con datos: `php artisan demo:flow` (ver [docs/demo.md](docs/demo.md)).

El scheduler (`php artisan schedule:run` cada minuto) corre `sap:sweep`, la red de seguridad de los documentos SAP, `audits:expire-counts`, que libera los conteos ciegos abandonados, y `ops:check`, que revisa colas, workers, SAP y discrepancias y avisa a quienes tienen `ops.alerts`. Los envíos a SAP van en la cola `sap`: el worker debe escucharla (`php artisan queue:work --queue=sap,default`).

En producción el primer administrador se crea con `php artisan app:create-admin`. Los sensores se registran con `php artisan devices:register <código> "<nombre>"`, que muestra el token una sola vez.

## Pruebas

```bash
composer test    # Pint + PHPStan (nivel 7) + PHPUnit contra PostgreSQL (base trazabilidad_testing)
```

Las pruebas corren contra PostgreSQL real, no SQLite: las restricciones, índices parciales y triggers son parte de las reglas del negocio. Las de concurrencia (`--testsuite=Concurrency`) lanzan procesos PHP en paralelo contra una base aparte, `trazabilidad_testing_concurrency`, que se crea sola: el usuario de la base necesita permiso para crear bases.

## Documentación

- [Fase 0: diagnóstico y decisiones pendientes](docs/fase-0-diagnostico.md)
- [Fase 1: persistencia e invariantes](docs/fase-1-persistencia.md)
- [Fase 2: motor de estibado](docs/fase-2-motor-estibado.md)
- [Fase 3: producción, etiquetas y tablero](docs/fase-3-produccion.md)
- [Fase 4: recibos de producción](docs/fase-4-recibos.md)
- [Fase 5: conteo ciego y discrepancias](docs/fase-5-auditoria.md)
- [Fase 6: traslados 04 → 05](docs/fase-6-traslados.md)
- [Fase 7: calidad y despliegue](docs/fase-7-calidad-despliegue.md)
- [Informe de cumplimiento](docs/informe-cumplimiento.md)
- [Despliegue](docs/despliegue.md)
- [Operación y soporte](docs/operacion.md)
- [Datos de demostración](docs/demo.md)
- [Etiquetas de caja](docs/etiquetas-de-caja.md)

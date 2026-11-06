# Motivos de cierre de estibas incompletas

Al cerrar una estiba incompleta desde la Estación de producción, el operario escoge el motivo de una lista. El motivo es obligatorio. Además puede escribir una observación opcional, de hasta 300 caracteres.

## Quién mantiene la lista

La pantalla está en **Administración → Motivos de cierre** y la usan los roles con el permiso `close_reasons.manage`:

- Administrador del sistema
- Supervisor de producción

Desde ahí se crea un motivo, se cambia su nombre o su orden, y se desactiva o reactiva. Los motivos no se borran. Una estiba ya cerrada conserva el suyo aunque después lo desactiven. Cada cambio queda en Auditoría, tipo "Motivos de cierre".

El orden define cómo aparecen en la estación: los números menores salen primero. Los nombres no se repiten, sin importar mayúsculas.

## Qué se guarda

- `pallets.close_reason_id` y `pallets.close_note` guardan el motivo y la observación de la estiba.
- El evento de trazabilidad `pallet.closed_manual` lleva en `reason` el texto "Motivo: observación" y en `context` el id y el nombre del motivo de ese momento. Si después le cambian el nombre al motivo, el historial conserva el original.
- La base de datos no deja hacer un cierre manual sin motivo, y tampoco deja cambiar el motivo de una estiba ya cerrada (trigger `pallets_close_reason_guard`). Las estibas que se cerraron antes de este cambio quedan sin motivo.

## Despliegue

1. `php artisan migrate`
2. `php artisan db:seed --class=RolesAndPermissionsSeeder`, que crea el permiso nuevo.
3. Antes de abrir la línea, un supervisor carga los motivos. Mientras la lista esté vacía, la estación avisa y no permite cerrar estibas incompletas.

La demo (`demo:flow`) crea tres motivos de ejemplo: "Fin de turno", "Fin de lote" y "Cambio de referencia". En producción no se crea ninguno automáticamente.

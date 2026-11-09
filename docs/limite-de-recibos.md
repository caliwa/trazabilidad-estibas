# Límite de recibos por OF

Una OF acepta recibos de producción hasta que lo recibido llega a un porcentaje de la cantidad planificada. Por defecto ese porcentaje es 90 %. Al llegar, hay que cerrar la OF en SAP.

## Cómo se mide

- Cuenta lo recibido en 04 y lo que está en trámite: las líneas de recibos confirmados en SAP o todavía por confirmar (`hold_status` ACTIVA o CONFIRMADA). No cuentan los recibos fallidos.
- Se compara contra la cantidad planificada de la OF que viene de SAP. Si la OF no tiene cantidad planificada, no hay límite.
- Mientras la OF esté por debajo del límite, acepta recibos. El recibo que cruza el límite sí pasa: por ejemplo, si va en 85 % y el recibo la lleva a 96 %, se envía. Desde ahí la OF no acepta más.
- Dos solicitudes al mismo tiempo para la misma OF se revisan una después de la otra, porque la fila de la OF se bloquea.

## Qué ve cada quien

- **Recibos de producción:** al escoger la OF se ve cuánto lleva y el límite.
  - Si el recibo que se acaba de solicitar la lleva al límite, sale el aviso "La OF llegó al 90 %: hay que realizar el cierre de la OF en SAP".
  - Con la OF bloqueada, el botón queda deshabilitado y el servidor rechaza la solicitud con el mismo mensaje.
- **Tablero de OF:** la OF bloqueada muestra "Recibos bloqueados: cerrar en SAP".
- La alerta "Lista para cerrar en SAP" sigue saliendo al 100 %, como antes.

## Habilitar de nuevo

Si producción debe seguir con esa OF, el Supervisor de producción, que tiene el permiso `orders.unlock_receipts`, pulsa **Habilitar recibos** en el Tablero de OF y escribe el motivo. El Administrador también puede.

Desde ese momento la OF acepta recibos sin límite, hasta que se cierre en SAP. Quedan registrados quién la habilitó, cuándo y el motivo, en la OF y en su historial (`production_order.receipts_unlocked`).

## Trazabilidad

- `production_order.receipt_limit_reached`: el recibo que llevó la OF al límite.
- `production_order.receipts_unlocked`: la habilitación, con el motivo y en cuánto iba la OF.

## Parámetro

**Administración → Parámetros**, "Límite de recibos por OF". Va de 1 a 100 y lo cambia el Administrador (permiso `settings.manage`). El valor se guarda en `app_settings` y cada cambio queda en Auditoría, tipo "Parámetros", con el valor anterior y el nuevo.

## Despliegue

1. `php artisan migrate`
2. `php artisan db:seed --class=RolesAndPermissionsSeeder`, que crea los permisos `orders.unlock_receipts` y `settings.manage`.
3. `npm run build`

Las OF que ya estén por encima del límite quedan bloqueadas desde el despliegue, hasta que se cierren en SAP o el supervisor las habilite.

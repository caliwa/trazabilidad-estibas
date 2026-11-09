# Anulación de cajas leídas

Si una caja ya contada se avería o se leyó por error, se anula desde la Estación de producción con el botón **Anular caja leída**.

## Quién

Necesita el permiso `boxes.void`. Lo tienen el Operario de producción, el Administrador de trazabilidad y el Administrador del sistema.

## Cómo

1. Se lee el QR de la caja con la PDA, el lector o la cámara. El campo es el mismo que el de la lectura de cajas: se envía solo cuando el lector termina, con Enter o sin él, y acepta lo que agregan algunas PDA (identificador de simbología como `]Q1`, teclas invisibles antes o después). Si la etiqueta está tan dañada que el QR no se puede leer, se escribe a mano el token de la caja (los 32 caracteres, sin `TRZB:`) y se presiona Enter. Esto solo vale para anular: el campo de lectura de cajas sigue exigiendo el QR completo.
2. La pantalla muestra la caja, su estiba y qué le va a pasar a la estiba.
3. Se escoge el motivo de la lista, que es obligatorio, y se puede escribir una observación.
4. **Anular caja.**

La lista de motivos se mantiene en **Administración → Motivos**, pestaña "Anulación de caja", igual que la de cierre (ver `motivos.md`). Si no hay motivos activos, la estación no deja anular.

## Reglas

- Solo se anulan cajas de estibas que no se han paletizado: en estibado o cerradas sin paletizar.
- Una caja anulada no se vuelve a contar. Si se lee otra vez, se rechaza como "caja anulada", tanto en la lectura manual como en el lector fijo de la línea, y tampoco sirve para confirmar la etiqueta de una estiba.
- Qué le pasa a la estiba:

| La estiba estaba | Resultado |
|---|---|
| En estibado | Queda con una caja menos. |
| Cerrada automáticamente (llena) y no hay otra abierta de la misma OF y lote | Se reabre con una caja menos. La siguiente caja de esa OF y lote vuelve a ella. |
| Cerrada automáticamente, pero la línea ya abrió la siguiente | Queda cerrada como incompleta con una caja menos, cerrada por quien anuló. |
| Cerrada a mano | Sigue cerrada con una caja menos. Si queda sin cajas, se reabre. Si además hay otra abierta, no se puede anular. |

## Trazabilidad

- La lectura original no se borra. La anulación queda en `box_voids`, que no se puede modificar ni borrar, con la caja, el motivo, la observación, quién, cuándo y cómo quedó la estiba antes y después.
- Eventos `box.voided` y, si aplica, `pallet.reopened` en el historial de la estiba.
- La base de datos solo deja bajar la cantidad de cajas, reabrir una estiba o cambiar su cierre dentro de la misma transacción que registró la anulación de esa estiba.
- El detalle de la estiba muestra cuántas lecturas se anularon. El tablero de Inicio no cuenta las cajas anuladas.

## Despliegue

`php artisan migrate` y `php artisan db:seed --class=RolesAndPermissionsSeeder`, que agrega el permiso. Antes de usarlo, un supervisor carga los motivos de anulación.

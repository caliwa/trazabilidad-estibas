# Patinaje, Inicio del operario y NO_READ

## Rol Patinador de producción

Rol nuevo `production_skater` ("Patinador de producción"). Es quien recibe de la línea las estibas ya cerradas.

| Puede | Permiso |
|---|---|
| Pantalla **Producción → Patinaje** | `pallets.skate` (nuevo) |
| Confirmar paletizado | `pallets.palletize` |
| Imprimir la etiqueta de la estiba y confirmar que quedó pegada | `pallets.label` |
| Reimprimir la etiqueta, con motivo | `pallets.reprint` |
| Generar recibos de producción y conciliarlos con SAP | `receipts.request`, `receipts.reconcile` |
| Ver el tablero de Estibas, el detalle y la trazabilidad | `pallets.view`, `trace.view` |

No lee cajas, no cierra estibas y no entra a la Estación de producción. La Estación y los demás roles quedan como estaban.

**Pantalla Patinaje** (se refresca cada 5 s):

- Cuatro contadores: en estibado, por paletizar, por etiquetar y para recibo.
- **Por paletizar y etiquetar:** las estibas cerradas, con los mismos botones y reglas de la Estación (confirmar paletizado, imprimir, reimprimir con motivo, confirmar etiqueta aplicada). La confirmación por lectura usa el mismo campo de lectura de cajas, así que sirve con PDA, lector o cámara.
- **Listas para recibo de producción:** las etiquetadas sin recibo, agrupadas por OF, con el botón **Generar recibo**. Abre Recibos de producción con esa OF ya escogida. Allí siguen aplicando todas las reglas, incluido el límite de recibos por OF.
- **En estibado (solo consulta):** las estibas que se están llenando, con su avance.

Usuario de prueba: `patinador@example.com` (`RoleUsersSeeder`).

## Inicio del operario de producción

Quien tiene `pallets.operate` y no tiene los indicadores de gestión ve en Inicio un tablero de la línea, que se refresca cada 5 s:

- **Cajas contadas hoy**, en verde, con las de la última hora.
- **No contadas hoy**, en rojo cuando hay alguna: lecturas del lector de la línea que no sumaron, y cuántas de ellas fueron NO_READ.
- **Estibas en estibado** y **cerradas por despachar** (por paletizar y por etiquetar).
- **Últimas lecturas**, una por una: verde "Contada", con la caja y la estiba a la que fue, o rojo "No contada" o "El lector no logró leer", con el motivo.
- **Avance de las estibas:** barra de progreso, cajas / estándar y "Faltan N cajas".

Las lecturas manuales rechazadas no se guardan (se muestran en la Estación al momento), así que "No contadas" cuenta las del lector fijo.

## NO_READ

Cuando el lector se activa pero no logra leer, manda un texto en lugar del QR, por ejemplo `NO_READ`, `NoRead`, `NOREAD`, `NO READ`, `ERROR` o `NG`. Ahora la aplicación lo reconoce y en vez de "Caja no contada: lo leído no es el QR…" muestra:

> **El lector no logró leer** · El lector se activó pero no logró leer la etiqueta (NO_READ). Vuelve a leer esa caja.

Aplica en el panel del lector fijo de la Estación, en el campo de lectura manual (PDA) y en las lecturas de la API. Queda en la bitácora del lector con `no_read: true` y no suma caja, como antes.

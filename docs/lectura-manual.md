# Lectura manual de cajas y escáner

## 1. Qué hay

| Dónde | Para qué | Permiso |
|---|---|---|
| Estación de producción → **Lectura manual de cajas** | Leer la etiqueta de cada caja y llenar la estiba | `pallets.operate` |
| Estación de producción → **Confirmar etiqueta pegada** | Leer el QR de la etiqueta ya pegada en la estiba paletizada | `pallets.label` |
| Conteo ciego | Buscar la estiba leyendo su QR | `audits.count` |

Las tres usan el mismo campo de escáner:
- **Lector de mano** USB o Bluetooth, de los que escriben como teclado. Se deja el cursor en el campo y se lee; la lectura se envía sola cuando el lector deja de escribir.
- **Cámara:** en celulares con Chrome (Android) aparece el botón "Cámara". En otros navegadores no aparece.

## 2. Lectura de una caja

El QR de la caja trae la OF, el PT, la descripción, el lote, el vencimiento, el contenido y el código de la caja. Al leerlo:

1. **OF:** se busca la orden por su número. Si todavía no está en la aplicación, se trae de SAP en ese momento. Tiene que estar **liberada**.
2. **PT:** el producto de la caja tiene que ser el de la OF.
3. **Lote y caja:** el lote tiene que tener etiquetas de caja generadas en la aplicación para esa OF, y el código de caja tiene que ser uno de esas etiquetas. No se cuentan cajas con etiquetas que no salieron de aquí.
4. **Estiba:** la caja suma a la estiba abierta de esa OF, ese PT y ese lote. Si no hay una, se abre. Al llegar al estándar se cierra sola y la siguiente caja abre otra. Son las mismas reglas que con el sensor.

La pantalla muestra lo leído (OF, producto, lote, vence) y cómo va la estiba (cajas / estándar). Si la caja no se cuenta, dice por qué.

**Cada caja se cuenta una sola vez.** Cada etiqueta tiene su propio código de caja (lote + consecutivo, por ejemplo `33128293-0007`), en la última línea del QR (`Caja: 33128293-0007`) e impreso debajo del QR.
- El código tiene que ser uno que la aplicación generó para esa OF y ese lote.
- El código de caja es el identificador de la lectura y la base de datos lo acepta una sola vez. Leer la misma caja otra vez, un reintento de la red o un lector que dispara dos veces, aunque sea desde dos estaciones al mismo tiempo, no cuenta de nuevo. La pantalla dice "La caja … ya fue leída y está en la estiba …".
- Las etiquetas impresas antes de los códigos de caja no se aceptan: hay que reimprimir ese lote.

**Lectores con otra distribución de teclado:** si el lector escribe ":" o "/" como otros símbolos (Ñ, -, ;), o manda los saltos de línea como Enter, la lectura igual se entiende. Lo mismo para el QR de la estiba con mayúsculas o con otro separador después de `TRZ1`.

## 3. Estándar de estiba de prueba

Mientras el estándar real (cajas por estiba) se carga en SAP, un producto sin estándar recibe uno de prueba: **40 cajas** (`TRACE_TEST_PALLET_BOXES`). Queda marcado `PRUEBA`, se ve como "Estándar de prueba" en la estación y queda en la bitácora. Con `TRACE_TEST_PALLET_BOXES=0` se apaga.

Si el producto ya tiene un estándar, se usa ese.

## 4. Configuración

- `TRACE_LABEL_CONFIRMATION=scan`: la etiqueta se confirma leyendo su QR (DP-03). Es el valor por defecto. Revisa que en el `.env` no esté vacío.
- `TRACE_TEST_PALLET_BOXES=40`: estándar de prueba.
- La lectura manual no depende de `TRACE_SCAN_INGESTION_ENABLED` (DP-01), que es solo para el sensor automático de la línea.

## 5. Pruebas

`ManualBoxScanTest` (12) y `ManualBoxScanConcurrencyTest` (10 procesos leyendo la misma caja a la vez: cuenta una). Al cambiar el identificador de la lectura por uno aleatorio, las dos pruebas de doble lectura fallan.

`ManualBoxScanTest`:
- cajas que llenan y cierran la estiba con el estándar de prueba;
- estándar real cuando existe;
- lecturas con otra distribución de teclado;
- una caja se cuenta una sola vez por su código; etiquetas sin código o con un código desconocido;
- validación de OF, PT y lote sin contar nada cuando falla;
- OF traída de SAP por su número;
- permisos;
- confirmación de etiqueta por lectura;
- pantallas de la estación y del conteo ciego.

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

El QR de la caja funciona como el de la estiba: **lleva solo un código único opaco** (`TRZB:` y 32 caracteres), distinto en cada etiqueta. No lleva la OF, el lote ni la descripción: esos datos van impresos en la etiqueta para las personas, y la aplicación los toma de su base de datos al leer el QR.

Al leerlo:

1. **Caja:** se busca la etiqueta con ese código. Tiene que ser una que generó la aplicación; si no, se rechaza. Si se lee el QR de una estiba en este campo, se avisa.
2. **OF:** de la caja sale su OF, que tiene que seguir **liberada**. Si el producto de la OF en SAP ya no es el que se imprimió en la caja, se rechaza.
3. **Estiba:** la caja suma a la estiba abierta de esa OF, ese PT y ese lote. Si no hay una, se abre. Al llegar al estándar se cierra sola y la siguiente caja abre otra. Son las mismas reglas que con el sensor.

La pantalla muestra lo que se sabe de esa caja (OF, producto, lote, código de caja, vence) y cómo va la estiba (cajas / estándar). Si la caja no se cuenta, dice por qué.

**Cada caja se cuenta una sola vez.** El código de la caja (lote + consecutivo, por ejemplo `33128293-0007`, impreso debajo del QR) es el identificador de la lectura, y la base de datos lo acepta una sola vez. Leer la misma caja otra vez, un reintento de la red o un lector que dispara dos veces, aunque sea desde dos estaciones al mismo tiempo, no cuenta de nuevo. La pantalla dice "La caja … ya fue leída y está en la estiba …".

**Etiquetas anteriores:** las que llevaban el texto dentro del QR ya no se aceptan. Hay que reimprimir esos lotes.

**Lectores con otra distribución de teclado:** si el lector escribe el ":" como otro símbolo (Ñ, ;, -), agrega un Enter al final o manda mayúsculas, el QR igual se entiende. Lo mismo para el QR de la estiba.

## 3. Estándar de estiba de prueba

Mientras el estándar real (cajas por estiba) se carga en SAP, un producto sin estándar recibe uno de prueba: **40 cajas** (`TRACE_TEST_PALLET_BOXES`). Queda marcado `PRUEBA`, se ve como "Estándar de prueba" en la estación y queda en la bitácora. Con `TRACE_TEST_PALLET_BOXES=0` se apaga.

Cuando la sincronización de OF trae `Items.U_TEC_CajMstXPall` con valor, ese pasa a ser el estándar vigente y el de prueba queda reemplazado (ver `docs/conector-sap.md`, sección 5). Las estibas abiertas con el de prueba lo conservan hasta cerrarse.

Si el producto ya tiene un estándar, se usa ese.

## 4. Configuración

- `TRACE_LABEL_CONFIRMATION=scan`: la etiqueta se confirma leyendo su QR (DP-03). Es el valor por defecto. Revisa que en el `.env` no esté vacío.
- `TRACE_TEST_PALLET_BOXES=40`: estándar de prueba.
- La lectura manual no depende de `TRACE_SCAN_INGESTION_ENABLED` (DP-01), que es solo para el sensor automático de la línea.

## 5. Pruebas

`ManualBoxScanTest` (11) y `ManualBoxScanConcurrencyTest` (10 procesos leyendo la misma caja a la vez: cuenta una). Al cambiar el identificador de la lectura por uno aleatorio, las dos pruebas de doble lectura fallan.

`ManualBoxScanTest`:
- cajas que llenan y cierran la estiba con el estándar de prueba;
- estándar real cuando existe;
- el QR lleva solo el código opaco;
- solo QR de cajas generadas aquí y con la OF liberada; QR de estiba o etiquetas viejas rechazados;
- lecturas con otra distribución de teclado;
- una caja se cuenta una sola vez por su código;
- permisos;
- confirmación de etiqueta por lectura;
- pantallas de la estación y del conteo ciego.

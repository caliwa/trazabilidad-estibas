# Lectura manual de cajas y escáner

## 1. Qué hay

| Dónde | Para qué | Permiso |
|---|---|---|
| Estación de producción → **Lectura manual de cajas** | Leer la etiqueta de cada caja y llenar la estiba | `pallets.operate` |
| Estación de producción → **Por paletizar y etiquetar** → **Confirmar etiqueta aplicada** | Leer el QR de una caja de la estiba donde se pegó la etiqueta | `pallets.label` |
| Conteo ciego | Buscar la estiba leyendo su QR | `audits.count` |

Las tres usan el mismo campo de escáner:
- **Lector de mano** USB o Bluetooth, de los que escriben como teclado. Se deja el cursor en el campo y se lee; la lectura se envía sola cuando el lector deja de escribir.
  En la estación de producción el campo **Etiqueta de la caja** recupera el cursor solo: al cargar la página, después de leer en otro campo, al cerrar una ventana o al hacer clic en otra parte. No hay que hacer clic en él para leer. Si el operario pone el cursor en otro campo para escribir, se respeta hasta que sale de ahí. En celulares no lo hace, para no abrir el teclado sobre la pantalla (ahí se usa la cámara).
- **Cámara del celular:** al leer las cajas y el QR de la estiba (el diálogo "Confirmar etiqueta aplicada", conteo ciego y calidad) aparece el botón "Cámara" o "Leer con la cámara" en cualquier celular: Chrome en Android, Safari en iPhone y otros. Abre la cámara trasera, lee el QR y lo envía solo. Donde el navegador no trae lector de QR propio, lo decodifica la aplicación (jsQR).
- La cámara solo funciona si la aplicación se abre por **HTTPS** (o en `localhost`). Por `http://` el navegador no la ofrece y el botón no aparece. La aplicación permite la cámara solo para sus propias páginas (`Permissions-Policy: camera=(self)`).

## 2. Lectura de una caja

El QR de la caja funciona como el de la estiba: **lleva solo un código único opaco** (`TRZB:` y 32 caracteres), distinto en cada etiqueta. No lleva la OF, el lote ni la descripción: esos datos van impresos en la etiqueta para las personas, y la aplicación los toma de su base de datos al leer el QR.

Al leerlo:

1. **Caja:** se busca la etiqueta con ese código. Tiene que ser una que generó la aplicación; si no, se rechaza. Si se lee el QR de una estiba en este campo, se avisa.
2. **OF:** de la caja sale su OF, que tiene que seguir **liberada**. Si el producto de la OF en SAP ya no es el que se imprimió en la caja, se rechaza.
3. **Estiba:** la caja suma a la estiba abierta de esa OF, ese PT y ese lote. Si no hay una, se abre. Al llegar al estándar se cierra sola y la siguiente caja abre otra. Son las mismas reglas que con el sensor.

La pantalla muestra lo que se sabe de esa caja (OF, producto, lote, código de caja, vence) y cómo va la estiba (cajas / estándar). Si la caja no se cuenta, dice por qué.

**Cada caja se cuenta una sola vez, por cualquier tipo de lectura.** El token del QR (o el código de caja, lote + consecutivo, por ejemplo `33128293-0007`, impreso debajo del QR) identifica la caja. Cada lectura guardada queda ligada a su etiqueta (`pallet_scan_events.box_label_id`, lo llena la base de datos misma al guardar la lectura) y un índice único (`pallet_scan_events_one_per_box`) acepta **una sola lectura por caja**, venga de la lectura manual, la PDA, la cámara, el lector fijo de la línea o un dispositivo por la API, y aunque llegue desde dos equipos al mismo tiempo. Si ya está leída, la pantalla dice "La caja … ya fue leída y está en la estiba …" y no suma. Una caja anulada tampoco se vuelve a leer.

**Etiquetas anteriores:** las que llevaban el texto dentro del QR ya no se aceptan. Hay que reimprimir esos lotes.

**Lectores con otra distribución de teclado:** si el lector escribe el ":" como otro símbolo (Ñ, ;, -), agrega un Enter al final o manda mayúsculas, el QR igual se entiende. Lo mismo para el QR de la estiba.

## 3. Estándar de estiba de prueba

Mientras el estándar real (cajas por estiba) se carga en SAP, un producto sin estándar recibe uno de prueba: **40 cajas** (`TRACE_TEST_PALLET_BOXES`). Queda marcado `PRUEBA`, se ve como "Estándar de prueba" en la estación y queda en la bitácora. Con `TRACE_TEST_PALLET_BOXES=0` se apaga.

Cuando la sincronización de OF trae `Items.U_TEC_CajMstXPall` con valor, ese pasa a ser el estándar vigente y el de prueba queda reemplazado (ver `docs/conector-sap.md`, sección 5). Las estibas abiertas con el de prueba lo conservan hasta cerrarse.

Si el producto ya tiene un estándar, se usa ese.

## 4. Configuración

- `TRACE_LABEL_CONFIRMATION=scan`: la etiqueta se confirma leyendo una caja de la estiba (DP-03). Es el valor por defecto. Revisa que en el `.env` no esté vacío.

## Confirmar la etiqueta pegada

Leer el QR de la etiqueta solo probaba que se imprimió, no en qué estiba quedó. Ahora se confirma desde el botón **Confirmar etiqueta aplicada** de la estiba en "Por paletizar y etiquetar" (la estiba cuya etiqueta se acaba de imprimir): la ventana abre con el cursor listo y pide leer el QR de **una caja de la estiba donde se pegó la etiqueta** (mejor del centro, no del borde). Al leerla se confirma sola, sin clic.

La aplicación comprueba que esa caja se contó en la estiba del botón. Si es así, la estiba queda etiquetada y la bitácora guarda la caja usada. Si la caja es de otra estiba, la ventana muestra en rojo "La caja leída es de EST-B, no de EST-A", no se confirma y queda registrado (`pallet.label_mismatch`); se puede leer otra caja las veces que haga falta. También se puede leer con la cámara del celular.

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

## 6. Regla de un token, una lectura (base de datos)

- `pallet_scan_events.box_label_id` liga cada lectura a la etiqueta de su caja. Lo llena el trigger `pallet_scan_events_box_link` al insertar, a partir del identificador de la lectura: el código de caja o el token (solo o dentro del texto del QR). No depende de que la aplicación lo mande.
- El índice único `pallet_scan_events_one_per_box` deja una sola lectura por etiqueta, cualquiera sea el dispositivo.
- La caja de una lectura no se puede cambiar después (`pallet_scan_events_guard`).
- Al migrar se ligaron las lecturas que ya existían. Si alguna caja tenía más de una lectura contada de antes, solo la primera quedó ligada y las otras quedan como estaban. `php artisan boxes:duplicates` las lista para revisarlas y, si corresponde, anular la sobrante.
- Pruebas: `BoxReadOnceTest` (la base rechaza la segunda lectura por QR, token o código desde otro dispositivo; sensor y mano en los dos órdenes; API y estación) y `ScanEventApiTest`.

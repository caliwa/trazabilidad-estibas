# Etiquetas de caja

## 1. Objetivo

Generar el QR que va pegado en cada caja de producto, en etiquetas de 10 × 8 cm para impresora Zebra. El operario escribe el número de la orden de fabricación (OF), el sistema la consulta en SAP B1 y, si está **Liberada**, muestra su encabezado, lo guarda y permite generar las etiquetas.

Es un módulo nuevo y aparte: no cambia la estación de producción, las estibas, los recibos, el conteo ni las transferencias.

## 2. Pantalla

| Ruta | Para | Permiso |
|---|---|---|
| `/box-labels` (menú Producción → Etiquetas de caja) | Consultar la OF, ver el encabezado, generar etiquetas, ver la vista previa, descargar el ZPL | `box_labels.print`: operario de producción y administrador de trazabilidad |

## 3. Cómo funciona

1. **Consulta en SAP** (`ConsultProductionOrder`).
   - Si la OF no existe, se dice.
   - Si existe pero está Planificada, Cerrada o Cancelada, se dice el estado y no se guarda nada.
   - Si SAP no responde, se dice que no se pudo consultar. Nunca se confunde con "no existe".
2. **Encabezado guardado.** Con la OF liberada se guardan en `production_orders` los mismos campos del formulario de SAP: tipo, estado, producto, descripción, cantidad planificada y unidad, almacén, prioridad, fechas de la orden, de inicio y de finalización, usuario, origen y "vinculado a". También queda la respuesta completa de SAP (`sap_header`), quién consultó y cuándo.
   - El producto queda en `items` con su código EAN.
   - Consultar otra vez actualiza el encabezado.
   - La vida útil del producto es un dato local y SAP no la pisa.
   - Si en SAP la OF cambió de producto y aquí ya tiene estibas, se rechaza para revisar.
3. **Generar etiquetas** (`GenerateBoxLabels`). El operario indica la cantidad de etiquetas, la fecha de producción (no puede ser futura) y la de vencimiento. La de vencimiento se propone sola con la vida útil del producto, si está cargada.
4. **Lote:** número de OF + día juliano de la fecha de producción, con 3 dígitos. Por ejemplo, la OF 33128 producida el 20/10/2026 (día 293) queda con lote `33128293`.
5. **Límite por OF.** Entre todas las tandas, una OF no recibe más etiquetas que las cajas que llena su cantidad planificada, contando la última caja aunque quede incompleta. Ejemplo: 800 unidades a 24 por caja dan 34 etiquetas. La fila de la OF se bloquea mientras se genera una tanda, así dos operarios a la vez no se pasan del límite.
6. **Qué se guarda.** Cada tanda queda en `box_label_batches` con el texto exacto del QR y el ZPL exacto. La tabla no admite cambios ni borrados (trigger). Generar y descargar quedan en la bitácora.

## 4. Contenido del QR

```
CJ1|<OF>|<producto>|<unidades por caja> <unidad>|<lote>|<EAN>|<vencimiento AAAA-MM-DD>
CJ1|33128|PT1350023|24 UNIDAD|33128293|2000013500236|2027-10-20
```

`CJ1` identifica el formato y su versión, por si mañana cambia. `BoxLabelPayload::decode()` lo lee de vuelta.

**Todas las cajas de una misma OF, lote y fecha llevan el mismo QR**, como se definió. El QR dice qué hay en la caja, no cuál caja es. Por eso, cuando un sensor lea las cajas en la línea, el identificador único de cada lectura lo sigue poniendo el sensor (DP-01). El QR solo no sirve para distinguir una caja de otra.

## 5. Etiqueta e impresión

ZPL II para una Zebra de 203 dpi: 800 × 640 puntos, es decir 100 × 80 mm.

| Zona | Contenido |
|---|---|
| Izquierda | OF, código y descripción del producto, lote, fecha de producción, vencimiento, unidades por caja |
| Derecha | QR (modelo 2, corrección M) |
| Abajo | Código de barras EAN-13. La Zebra calcula el dígito de control |

- **Una sola etiqueta con copias:** como todas las copias son iguales, se manda una etiqueta con `^PQ` y la impresora la repite.
- **Caracteres:** con `^CI28` salen bien la Ñ y las tildes.
- **Texto desde SAP:** cualquier `^` o `~` se quita, para que una descripción no pueda meter comandos en la impresora.

El archivo `.zpl` se descarga desde la pantalla y se manda a la Zebra con la utilidad de Zebra (Zebra Setup Utilities, "enviar archivo") o con el driver en modo de texto sin procesar.

La pantalla muestra una vista previa con el mismo QR. Lo que se imprime es el ZPL.

## 6. Pruebas

| Prueba | Qué demuestra |
|---|---|
| `BoxLabelsTest` (16) | Consulta y guardado del encabezado; rechazo de OF inexistentes, planificadas, cerradas o canceladas, sin guardar nada; SAP sin respuesta; reconsulta que conserva la vida útil; cambio de producto con estibas; regla del lote; contenido del QR; formato ZPL; texto de SAP que intenta meter comandos; vencimiento; límite acumulado por OF; datos que faltan; tanda inmutable; permisos; pantalla completa con la descarga |
| `BoxLabelConcurrencyTest` | 12 tandas en paralelo sobre la misma OF nunca pasan de 34. Sin el bloqueo de la fila, la prueba falló 4 de 5 veces |

Además decodifiqué con OpenCV el QR de la vista previa en el navegador, y sale el texto esperado.

## 7. Lo que falta o está supuesto

- **Conector SAP real (DP-09).** La consulta corre contra el simulador. El contrato (`SapGateway::findProductionOrder`) ya define qué datos hacen falta. Qué propiedades de Service Layer los traen se decide al escribir el conector, con acceso a SAP.
- **Simulador.** Responde con los 40 PT reales y seis órdenes de ejemplo (33128 a 33133) basadas en la 33128 que mandaste. Los EAN del simulador **no son reales**: usan el prefijo 200, que es de uso interno.
- **Unidades por caja.** Salen del factor de conversión local (DP-02). Sin factor vigente no se generan etiquetas.
- **Vida útil.** No viene de SAP. Es un campo local por producto (`items.shelf_life_days`). Si está vacío, el operario escribe el vencimiento. Falta definir quién la carga y de dónde sale.
- **Cantidad planificada.** Supuse que está en la misma unidad de inventario que usa el factor de conversión, como en los recibos. Si SAP la diera en cajas, el límite cambia.
- **Impresora sin probar.** Revisé el ZPL a mano y con pruebas automáticas, pero no lo imprimí en una Zebra real. Antes de usarlo en planta hay que imprimir una etiqueta y leerla con el lector de la línea.

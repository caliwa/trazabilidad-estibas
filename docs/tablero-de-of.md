# Tablero de OF

## 1. Objetivo

Ver en un solo lugar cómo va cada orden de fabricación liberada en SAP: cuánto se planificó, cuántas etiquetas de caja se generaron, cuántas cajas se leyeron, dónde están las estibas, cuánto se recibió en 04 y cuánto llegó a 05. Lo más importante es avisar cuando los recibos ya cubren lo planificado, para que cierren la OF en SAP.

La aplicación **no cierra** la OF: solo avisa. El cierre lo hacen en SAP, como hoy.

## 2. Pantallas

| Ruta | Para | Permiso |
|---|---|---|
| `/orders` (menú Producción → Tablero de OF) | Lista de OF liberadas con su avance y alertas | `orders.view`: administrador de trazabilidad y administrador |
| `/orders/{id}` | Detalle de una OF | `orders.view` |

**Tablero:**
- Arriba: OF liberadas, listas para cerrar, con sobreproducción, con alertas, cajas leídas hoy, estibas recibidas hoy y estibas en 05 hoy.
- Una fila por OF: producto, avance (etiqueta de estado y barra), planificado, etiquetas, cajas leídas, estibas (armando, terminadas, etiquetadas), recibido en 04, en 05 y alertas.
- Orden: primero sobreproducción, luego listas para cerrar, luego en proceso. Dentro de cada grupo, por fecha de fin.
- Filtros: búsqueda por OF, código o descripción; avance; solo con alertas.

**Detalle:**
- Encabezado de SAP: planificado, completado en SAP, almacén, fechas, unidades por caja y la última consulta.
- Recorrido: planificado → etiquetas → cajas leídas → recibido en 04 → contado y aprobado → en 05.
- Tiempos promedio: estiba cerrada → recibo confirmado, recibo → conteo aprobado, conteo → en 05.
- Lotes con su vencimiento, documentos SAP (recibos y transferencias con DocNum y estado) y estibas.

## 3. Solo OF liberadas

En el tablero solo aparecen las OF que están **Liberadas** en SAP. Las planificadas, cerradas o canceladas no salen. Tampoco salen las que llegaron por lecturas del sensor y nadie consultó en SAP. Esas aparecen apenas una actualización desde SAP diga que están liberadas.

## 4. Datos de SAP

SAP se consulta **solo con el botón** "Actualizar desde SAP". No hay consulta automática.

- En el tablero, el botón consulta todas las OF que no están cerradas ni canceladas, incluidas las que nadie había consultado. En el detalle, consulta solo esa OF.
- Cada OF se guarda en su propia transacción: si una falla, las demás se actualizan igual.
- Si SAP no responde o no tiene la OF, la orden se queda con lo que tenía y la pantalla dice por qué. "No respondió" y "no existe" nunca se confunden.
- Se guarda el estado y la cantidad **completada** que reporta SAP. Si SAP dice que la OF se cerró, aquí también queda cerrada: sale del tablero y ya no recibe lecturas, etiquetas ni recibos.
- Si en SAP la OF cambió de producto y aquí ya tiene estibas, se rechaza para revisar, igual que en la consulta de etiquetas de caja.

## 5. Cantidades

Se muestran en la unidad de SAP y en cajas.

- **Recibido en 04 y En 05** en unidades: la cantidad exacta de cada línea de recibo confirmada en SAP.
- **Etiquetas, cajas leídas y contado**: en cajas, y en unidades con el factor vigente.
- **Cajas planificadas**: lo planificado entre las unidades por caja, contando la última caja aunque quede incompleta.
- Las cuentas se hacen en millonésimas enteras (`Quantities`), nunca con decimales de punto flotante.

## 6. Estados y alertas

| Estado | Cuándo |
|---|---|
| En proceso | Lo recibido y confirmado todavía no llega a lo planificado |
| **Lista para cerrar en SAP** | Lo recibido y confirmado llega a lo planificado, contando la última caja completa |
| **Sobreproducción** | Lo recibido y confirmado pasa de lo que llenan las cajas planificadas |

**La última caja.** Los recibos van en cajas completas. Si lo planificado no da cajas exactas (800 unidades a 24 por caja son 33 cajas y una de 8), recibir esa caja 34 deja 816 unidades: eso es "Lista para cerrar", con una nota de las 16 unidades de más, no sobreproducción. Si el producto no tiene factor de conversión, el límite es lo planificado.

Solo cuentan los recibos **confirmados** en SAP. Uno pendiente de conciliación no suma, para no dar por cerrada una OF con un recibo que tal vez no existe.

Alertas de cada OF:
- Sobreproducción, con cuánto se pasó.
- Lista para cerrar.
- SAP reporta una cantidad completada distinta a la que confirmó la aplicación. Puede ser un recibo hecho directo en SAP o uno pendiente de conciliar.
- Recibos pendientes de conciliar.
- Transferencias a 05 pendientes de conciliar.
- Discrepancias de conteo abiertas.
- Más cajas leídas que etiquetas de caja generadas.

## 7. Aviso "Lista para cerrar"

- Cuando un recibo se confirma en SAP y con él lo recibido llega a lo planificado (o lo pasa), se avisa a todos los usuarios activos con `orders.view`. El aviso llega a la campana y por correo a quien tenga activado el correo.
- Se manda **una sola vez por OF**. La fila de la OF se bloquea mientras se decide, y queda la marca `ready_to_close_notified_at` y un evento en la bitácora.
- Solo si la OF está Liberada en SAP.
- El aviso corre después de confirmar el recibo y nunca puede deshacer la confirmación. Si falla, se reporta y el recibo sigue confirmado.
- También se revisa después de cada actualización desde SAP.

## 8. Pruebas

| Prueba | Qué demuestra |
|---|---|
| `OrderBoardTest` (12) | Estado según los recibos confirmados; la última caja incompleta cuenta como lo planificado; un recibo sin confirmar no cuenta; aviso una sola vez y solo a quien tiene el permiso; OF no liberada sin aviso; actualización con estado y cantidad completada, y cierre en SAP reflejado; alerta de diferencia con SAP; alerta de más cajas que etiquetas; SAP sin respuesta o sin la OF; permisos; tablero solo con liberadas y en orden de urgencia; detalle completo |
| `DemoFlowTest` | La demo deja la OF 33134 lista para cerrar, con el aviso enviado y la cantidad completada leída de SAP |

Al quitar el control de "una sola vez", la prueba del aviso falla.

## 9. Lo que falta o está supuesto

- **Conector SAP real (DP-09).** La cantidad completada se pide en el contrato (`SapProductionOrder::completedQuantity`). Qué propiedad de Service Layer la trae se decide al escribir el conector. El simulador la calcula sumando los recibos que tiene en memoria.
- **Sin tolerancia por debajo.** "Lista para cerrar" pide llegar a lo planificado. Si en planta se acepta cerrar con menos (por ejemplo 98 %), hay que definir el porcentaje.
- **La última caja incompleta.** Hoy el recibo manda la caja completa (cajas × factor), aunque la última lleve menos unidades. Eso es de DP-02: si SAP debe recibir las unidades reales, el recibo tiene que poder llevar una caja parcial.
- **Recibos hechos directo en SAP.** No suman en "Recibido en 04", porque la aplicación no los conoce. Se ven como diferencia contra lo completado en SAP.
- **Escala.** El estado y las alertas se calculan por OF después de la consulta. Con cientos de OF liberadas a la vez funciona bien; con miles habría que guardar el avance.

# Tablero de OF

## 1. Objetivo

Un tablero de KPIs con todas las órdenes de fabricación **liberadas en SAP B1**. Muestra cuánto avanzó cada una según los recibos de producción y cuánto ya se transfirió al CEDI. Cuando los recibos cubren lo planificado, avisa para que cierren la OF en SAP.

La aplicación **no cierra** la OF: solo avisa. El cierre lo hacen en SAP, como hoy.

## 2. Pantalla

| Ruta | Permiso |
|---|---|
| `/orders` (menú Producción → Tablero de OF) | `orders.view`: administrador de trazabilidad y administrador |

**KPIs arriba:**
- **OF liberadas** y cuántas no tienen avance.
- **Recibido en 04:** % de lo planificado, con las unidades.
- **Transferido a CEDI:** % de lo planificado, con las unidades.
- **Listas para cerrar en SAP:** cuántas ya están completas en CEDI y cuántas tienen sobreproducción.
- Un aviso si alguna OF tiene recibos o transferencias pendientes de conciliar con SAP.

**Una fila por OF:** OF y fecha de fin, producto, planificado, barra de recibido en 04, barra de transferido a CEDI, y el estado. Las listas para cerrar salen primero. Se puede buscar por OF, código o descripción, y filtrar por estado.

Las unidades se suman por unidad de medida de SAP. Si hay OF en unidades distintas, salen tarjetas separadas.

## 3. Datos de SAP

SAP se consulta **solo con el botón** "Actualizar desde SAP".

- Se pide a SAP la lista de **todas las OF liberadas** (`SapGateway::releasedProductionOrders`). Cada una se guarda o se actualiza aquí, aunque nunca se haya consultado antes.
- Las que aquí estaban liberadas y ya no vienen en la lista se consultan una por una. Si quedaron cerradas o canceladas, se marcan igual aquí: salen del tablero y ya no reciben lecturas, etiquetas ni recibos.
- Planificadas, cerradas y canceladas nunca entran al tablero.
- Si SAP no responde, no cambia nada y la pantalla lo dice. Si SAP ya no tiene una OF que aquí está liberada, se deja como está y se reporta.
- Cada OF se guarda en su propia transacción: si una falla, las demás se actualizan igual.

## 4. Avance

- **Recibido en 04:** la cantidad exacta de las líneas de recibo **confirmadas** en SAP. Un recibo pendiente de conciliar no suma.
- **Transferido a CEDI:** lo recibido de las estibas cuya transferencia de 04 a 05 está confirmada en SAP.
- **"Completa en CEDI":** todo lo recibido ya está transferido.

| Estado | Cuándo |
|---|---|
| Sin avance | Todavía no hay recibos confirmados |
| En proceso | Lo recibido no llega a lo planificado |
| **Lista para cerrar en SAP** | Lo recibido llega a lo planificado, contando la última caja completa |
| **Sobreproducción** | Lo recibido pasa de lo que llenan las cajas planificadas |

**La última caja.** Los recibos van en cajas completas. Si lo planificado no da cajas exactas (800 unidades a 24 por caja son 33 cajas y una de 8), recibir esa caja 34 deja 816 unidades: eso cuenta como "Lista para cerrar", no como sobreproducción. Si el producto no tiene factor de conversión, el límite es lo planificado.

## 5. Aviso "Lista para cerrar"

- Cuando un recibo se confirma y con él lo recibido llega a lo planificado (o lo pasa), se avisa a los usuarios activos con `orders.view`. Llega a la campana, y por correo a quien lo tenga activado.
- Se manda **una sola vez por OF** y solo si la OF está liberada en SAP. La fila de la OF se bloquea mientras se decide. Quedan la marca `ready_to_close_notified_at` y un evento en la bitácora.
- Corre después de confirmar el recibo y nunca puede deshacer la confirmación. También se revisa en cada actualización desde SAP.

## 6. Pruebas

| Prueba | Qué demuestra |
|---|---|
| `OrderBoardTest` (12) | Se traen todas las liberadas y ninguna otra; una OF cerrada en SAP sale del tablero; SAP sin respuesta no cambia nada; OF que SAP ya no tiene; avance por recibos y por transferencia a CEDI; un recibo sin confirmar no cuenta; la última caja incompleta; aviso una sola vez y solo a quien tiene el permiso; OF no liberada sin aviso; KPIs; permisos; pantalla |
| `DemoFlowTest` | La demo trae solo las liberadas y deja la OF 33134 lista para cerrar y completa en CEDI, con el aviso enviado |

Al quitar el control de "una sola vez", la prueba del aviso falla.

## 7. Lo que falta o está supuesto

- **Conector SAP real (DP-09).** El contrato pide la lista de OF liberadas con el mismo encabezado que la consulta de una OF. Qué consulta de Service Layer la trae se decide al escribir el conector. Hoy responde el simulador.
- **Sin tolerancia por debajo.** "Lista para cerrar" pide llegar a lo planificado. Si en planta cierran con menos (por ejemplo 98 %), hay que definir el porcentaje.
- **La última caja incompleta.** Hoy el recibo manda la caja completa (cajas × factor), aunque la última lleve menos unidades. Eso es de DP-02: si SAP debe recibir las unidades reales, el recibo tiene que poder llevar una caja parcial.
- **Recibos hechos directo en SAP.** No suman en el tablero, porque la aplicación no los conoce.

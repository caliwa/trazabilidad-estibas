# Conector SAP Business One (Service Layer)

## 1. Qué está conectado

| Operación | Service Layer | Estado |
|---|---|---|
| Iniciar sesión | `POST /Login` | Conectado |
| Crear recibo de producción | `POST /InventoryGenEntries` | Conectado |
| Buscar un recibo después de un timeout | `GET /InventoryGenEntries?$filter=<campo de correlación> eq '<uuid>'` y luego `GET /InventoryGenEntries(<DocEntry>)` | Conectado |
| Consultar una OF | `GET /ProductionOrders?$filter=DocumentNumber eq <n> and startswith(ItemNo,'PT')` y el producto en `GET /Items('<código>')` | Conectado |
| Traer todas las OF liberadas (tablero de OF, `sap:sync-orders`) | `GET /ProductionOrders?$filter=ProductionOrderStatus eq 'boposReleased' and startswith(ItemNo,'PT')`, siguiendo `odata.nextLink` | Conectado |
| Transferencia 04 → 05 | | **No conectada.** Se rechaza sin enviar nada |

Nada de esto se ha corrido contra el SAP DEV todavía: esta sesión no tiene acceso a esa red. Todo está probado con respuestas simuladas de Service Layer (`Http::fake`).

## 2. Configuración

En el `.env` del servidor:

```
SAP_DRIVER=service_layer
SAP_ENVIRONMENT=DEV
SAP_API_URL_DEV=https://servidor:50000/b1s/v1
SAP_COMPANY_DEV=
SAP_USER_DEV=
SAP_PASSWORD_DEV=
SAP_SL_VERIFY_TLS=true
SAP_SL_TIMEOUT=30
SAP_RECEIPT_CORRELATION_FIELD=U_TRZ_UUID
SAP_RECEIPT_COSTING_CODE=PCC
SAP_RECEIPT_COSTING_CODE2=PRODUCCI
SAP_RECEIPT_COSTING_CODE3=ANTIOQUI
```

- `SAP_ENVIRONMENT` elige el juego de credenciales: con `DEV` se leen las variables que terminan en `_DEV`. Para producción se agregan las `_PRD` (o el sufijo que usen) y se cambia `SAP_ENVIRONMENT`.
- `SAP_API_URL_<ENV>` es la raíz de Service Layer, terminada en `/b1s/v1`.
- Las credenciales solo se leen del entorno. No se escriben en logs, en la bitácora ni en la salida de los comandos.
- `php artisan app:preflight` revisa que estén completas, que la URL sea https y que el certificado se verifique.

**Probar la conexión** (solo lectura):

```bash
php artisan sap:check          # inicia sesión y lista las OF liberadas
php artisan sap:check 33128    # consulta una OF
php artisan sap:sync-orders    # trae y guarda todas las OF liberadas
```

## 3. Recibo de producción

Por cada recibo se envía:

```json
{
  "DocDate": "2026-10-21",
  "U_TRZ_UUID": "<request_uuid del recibo>",
  "DocumentLines": [
    {
      "BaseType": 202,
      "BaseEntry": 41659,
      "Quantity": 480,
      "WarehouseCode": "04",
      "CostingCode": "PCC",
      "CostingCode2": "PRODUCCI",
      "CostingCode3": "ANTIOQUI",
      "BatchNumbers": [
        {
          "BaseLineNumber": 0,
          "BatchNumber": "33128294",
          "Quantity": 480,
          "ManufacturingDate": "2026-10-21",
          "ExpiryDate": "2028-10-20",
          "AddmisionDate": "2026-10-21"
        }
      ]
    }
  ]
}
```

- **Una línea por lote**, como se definió. Cada línea va contra la OF (`BaseType` 202, `BaseEntry` = DocEntry de la OF) con un solo lote. `BaseLineNumber` es el número de esa línea (0, 1, 2…).
- **DocEntry de la OF**: sale de la consulta a SAP (etiquetas de caja o tablero de OF). Si la OF nunca se consultó, el recibo se rechaza sin llamar a SAP y dice que hay que consultarla.
- **Fechas**: `DocDate`, `ManufacturingDate` y `AddmisionDate` son la fecha de admisión, fijada al pedir el recibo. `ExpiryDate` es el vencimiento del lote, tomado de las etiquetas de caja.
- **Cantidades**: cajas × unidades por caja, en la unidad de inventario.
- **Normas de reparto**: fijas, desde la configuración. Si una se deja vacía, no se manda.

### Cómo se interpreta la respuesta

| Respuesta de SAP | Resultado |
|---|---|
| 201 con DocEntry y DocNum | Creado. Igual se lee de vuelta y se compara antes de confirmar |
| 4xx con error de SAP (por ejemplo, lote o cantidad no válidos) | Rechazado: el recibo queda fallido y las estibas se pueden pedir otra vez |
| Timeout, error de conexión, 401 después de volver a iniciar sesión, 408, 429 o 5xx | Incierto: pendiente de conciliación. Nunca se reenvía a ciegas |
| No se pudo iniciar sesión | Incierto: no se envió nada; la conciliación lo reintenta cuando SAP vuelva |

**Sesión**: se inicia al primer uso y se guarda en el proceso. Si SAP responde 401 (sesión vencida), se inicia sesión otra vez y se repite la llamada una sola vez. Un 401 significa que SAP no procesó la llamada, así que repetirla no duplica nada.

### Verificación antes de confirmar

Después de crear (o en la conciliación), el recibo se busca por el campo de correlación y se lee completo. Se compara lote por lote: producto, lote, almacén, cantidad, vencimiento y fabricación. También se comparan la fecha del documento y la OF base. Solo si todo coincide se confirma con DocEntry y DocNum. Si algo no coincide, queda pendiente para revisión y nunca se reenvía.

## 4. Campo de correlación (importante)

Sin un campo que lleve el `request_uuid`, después de un timeout no hay forma segura de saber si el recibo se creó, y se arriesga a duplicarlo.

- Por defecto se usa **`U_TRZ_UUID`**: un campo de usuario en la cabecera del recibo de producción (tabla OIGN), alfanumérico de 36 caracteres. **Hay que crearlo en SAP.**
- Mientras no exista, SAP rechaza el recibo por propiedad desconocida. No se crea nada y el recibo queda fallido con el mensaje de SAP.
- Se puede usar otro campo con `SAP_RECEIPT_CORRELATION_FIELD` (por ejemplo `Comments`). Solo se aceptan nombres de campo simples, porque el valor va dentro de un filtro OData.

## 5. Orden de fabricación

Solo se traen OF de producto terminado: las que tienen un `ItemNo` que empieza por `PT` (configurable con `SAP_ORDER_ITEM_PREFIX`). Una OF de otro producto se trata como si SAP no la tuviera.

De `ProductionOrders` se usan: `AbsoluteEntry` (DocEntry), `DocumentNumber`, `ProductionOrderStatus`, `ProductionOrderType`, `ItemNo`, `PlannedQuantity`, `Warehouse`, `Priority`, `PostingDate`, `StartDate`, `DueDate` y `ProductionOrderOrigin`. Del producto (`Items`): `ItemName`, `BarCode`, `InventoryUOM`, `SalesQtyPerPackUnit` y `SalesPackagingUnit`. Los dos últimos se guardan en el maestro de productos (`items.sales_qty_per_pack_unit` e `items.sales_packaging_unit`) cada vez que se sincroniza una OF.

**Unidades por caja (DP-02).** `SalesQtyPerPackUnit` son las unidades que lleva una caja del PT. Al sincronizar, ese valor queda como el factor de conversión vigente del producto (`box_conversion_factors`). De ahí salen el límite de etiquetas de caja y la cantidad de los recibos (cajas × unidades por caja).
- Si cambia en SAP, se crea una versión nueva y la anterior queda como reemplazada. Las etiquetas y los recibos que ya la usaron conservan la suya, y el cambio queda en la bitácora.
- Si en SAP está vacío o en cero, el factor vigente no se toca y no se inventa uno.

| Estado en SAP | En la aplicación |
|---|---|
| `boposPlanned` | Planificado |
| `boposReleased` | Liberado |
| `boposClosed` | Cerrado |
| `boposCancelled` | Cancelado |
| Otro | No se interpreta: la consulta falla y lo dice |

- El usuario de la OF y el campo "vinculado a" quedan vacíos: `ProductionOrders` da el usuario como número interno y no hay un campo directo para lo segundo.
- `Items.BarCode` trae el EAN-13 de la unidad. La etiqueta de caja imprime el EAN-14 armado con el indicador 1 (`SAP_BOX_GTIN_INDICATOR`), los 12 primeros dígitos del EAN-13 y un dígito de control nuevo. Ver `docs/etiquetas-de-caja.md`.

## 6. Lo que falta

- **Correr `sap:check` y un recibo de prueba contra el SAP DEV.** Los nombres de objetos y campos son los estándar de Service Layer y los del JSON que compartieron, pero no se han validado contra su versión.
- **Crear el campo `U_TRZ_UUID`** en la cabecera del recibo de producción, o definir otro.
- **Transferencia 04 → 05:** sin conectar. Con el conector real, la transferencia que pide el conteo aprobado se rechaza con el mensaje "todavía no está conectada" y la estiba queda lista para reintentar cuando se conecte.
- **Decimales**: las cantidades viajan como número JSON. Con cantidades de hasta 15 dígitos significativos no hay pérdida.

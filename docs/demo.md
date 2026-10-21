# Datos de demostración

`php artisan demo:flow` llena una base local con un turno completo, de las lecturas del sensor al traslado de 04 a 05. Usa los mismos casos de uso que la aplicación y el simulador de SAP, así que lo que se ve en pantalla es lo que el sistema hace de verdad.

```bash
php artisan migrate:fresh --seed
php artisan demo:flow
```

Se niega a correr en producción, con un conector SAP real o si los datos ya existen.

## Qué deja

El turno es del último día terminado (`D`): lecturas desde las 06:00, paletizado a las 07:10, recibos a las 09:30 y conteos con su transferencia a las 13:00.

**Productos y órdenes.** El demo carga los 40 PT reales del catálogo de SAP (`PT1350002` a `PT1350041`) y consulta en el simulador tres órdenes liberadas, igual que la pantalla de etiquetas de caja:

| OF | Producto | Para qué |
|---|---|---|
| 33128 | PT1350023 · GELATINA OLIMPICA SIN SABOR 30 GR | Estándar de 10 cajas por estiba, 24 unidades por caja |
| 33131 | PT1350019 · GELATINA ALKOSTO SIN SABOR 30 GR | Estándar de 8 cajas, 24 unidades por caja |
| 33132 | PT1350002 · GELATINA AZUCARADA BOOM LIMON 1000 GR | Sin estándar de estiba, a propósito: sus lecturas se rechazan |

Los lotes siguen la regla de las etiquetas de caja: OF + día juliano. `33128DDD` es el lote del día `D`, y los lotes de 33128 de los dos días anteriores van como A2 y A3. Los códigos EAN-14 son del simulador (indicador 1 y prefijo 200, de uso interno) y la vida útil de 730 días es un valor de demo.

| Estiba | Lote | Dónde queda |
|---|---|---|
| EST-00000001 | 33128 del día D | Contada bien y transferida a 05 |
| EST-00000002 | 33128 del día D | Contada bien y transferida a 05 |
| EST-00000003 | 33128 del día D | Discrepancia: se contaron 9 de 10. Retenida |
| EST-00000004 | 33128 del día D | Cierre manual con 5 cajas, contada bien y transferida a 05 |
| EST-00000005 | 33128 del día D−1 | Recibida en 04 y **sin contar**: para probar el conteo ciego a mano |
| EST-00000006 | 33128 del día D−2 | Recibo pendiente de conciliación (SAP creó el documento pero la respuesta se perdió) |
| EST-00000007 | 33131 del día D | Contada bien; la transferencia quedó pendiente de conciliación (timeout sin crear). Se maneja desde su detalle |
| EST-00000008 | 33131 del día D | Paletizada, sin etiqueta |
| EST-00000009 | 33131 del día D | En estibado, 3 de 8 cajas |

Además:

- **Lecturas.** 3 lecturas rechazadas: 2 por producto sin estándar (OF 33132) y 1 por orden desconocida (99999). También 3 reenvíos duplicados, que no suman.
- **Alertas.** Las alertas de `/operations` muestran los pendientes, la discrepancia y la orden sin estándar.

## Etiquetas de caja

Entrar como `produccion@example.com` en `/box-labels` y consultar la OF 33128. Se ve el encabezado guardado y se pueden generar etiquetas (máximo 34: 800 unidades a 24 por caja). Para ver los rechazos: 33129 (planificada), 33130 (cerrada), 33133 (cancelada) o un número que no exista. Ver `docs/etiquetas-de-caja.md`.

## Usuarios

Contraseña `password` para todos:

| Usuario | Rol | Para ver |
|---|---|---|
| `supervisor@example.com` | Administrador de trazabilidad | Tablero, recibos, discrepancias, estado operativo; reintentar o conciliar transferencias desde el detalle |
| `produccion@example.com` | Operario de producción | Estación de producción |
| `cedi@example.com` | Operario logístico | Conteo ciego |
| `cedi2@example.com` | Operario logístico | Hizo el conteo que dio discrepancia |
| `admin@example.com` | Administrador | Todo, usuarios y auditoría |

## Simular la lectura del QR en el CEDI

Al final, el comando imprime el texto que leería el escáner para EST-00000005 (`TRZ1:` seguido de 32 caracteres). Para seguir el flujo desde ahí:

1. Entrar como `cedi@example.com` en `/cedi/count`, pegar ese texto y dar Enter. La pantalla muestra la estiba sin la cantidad esperada.
2. Escribir 10 cajas y registrar. Sale "Conteo aprobado" y, en la misma operación, se pide en SAP la transferencia de 04 a 05.
3. Con un worker corriendo (`composer dev` lo levanta), la transferencia pasa a Confirmado con su DocNum.
4. En `/pallets/5` queda la historia completa: lecturas, cierre, paletizado, etiqueta, recibo, conteo y transferencia.

Con otro número se abre una discrepancia, igual que con EST-00000003.

## Limitaciones

- Las decisiones pendientes (DP-01, DP-03) se encienden solo dentro del proceso del comando. En la aplicación siguen apagadas, por eso la estación de producción dice "Recepción de lecturas apagada (DP-01)".
- El simulador de SAP vive en memoria. Al conciliar un pendiente desde la pantalla, el simulador del worker no lo conoce: dice que no existe y, pasado el tiempo de gracia, lo reenvía. En SAP real la conciliación lo encontraría.
- El scheduler no corre solo en local, así que `/operations` muestra "Sin señal" para el scheduler y los workers hasta que se levanten.

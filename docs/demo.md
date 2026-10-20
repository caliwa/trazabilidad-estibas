# Datos de demostración

`php artisan demo:flow` llena una base local con un turno completo, de las lecturas del sensor al traslado de 04 a 05. Usa los mismos casos de uso que la aplicación y el simulador de SAP, así que lo que se ve en pantalla es lo que el sistema hace de verdad.

```bash
php artisan migrate:fresh --seed
php artisan demo:flow
```

Se niega a correr en producción, con un conector SAP real o si los datos ya existen.

## Qué deja

El turno es del último día terminado: lecturas desde las 06:00, paletizado a las 07:10, recibos a las 09:30, conteos a las 13:00 y traslados a las 15:00.

| Estiba | Lote | Dónde queda |
|---|---|---|
| EST-00000001 | L-A1 | Trasladada a 05 (individual) |
| EST-00000002 | L-A1 | Trasladada a 05 (agrupada con la 4) |
| EST-00000003 | L-A1 | Discrepancia: se contaron 9 de 10. Retenida |
| EST-00000004 | L-A1 | Cierre manual con 5 cajas, trasladada a 05 (agrupada) |
| EST-00000005 | L-A2 | Recibida en 04 y **sin contar**: para probar el conteo ciego a mano |
| EST-00000006 | L-A3 | Recibo pendiente de conciliación (SAP creó el documento pero la respuesta se perdió) |
| EST-00000007 | L-B1 | Auditada; traslado pendiente de conciliación (timeout sin crear) |
| EST-00000008 | L-B1 | Paletizada, sin etiqueta |
| EST-00000009 | L-B1 | En estibado, 3 de 8 cajas |

Además:

- **Lecturas.** 3 lecturas rechazadas: 2 por producto sin estándar y 1 por orden desconocida. También 3 reenvíos duplicados, que no suman.
- **Alertas.** Las alertas de `/operations` muestran los pendientes, la discrepancia y la orden sin estándar.

## Usuarios

Contraseña `password` para todos:

| Usuario | Rol | Para ver |
|---|---|---|
| `supervisor@example.com` | Administrador de trazabilidad | Tablero, recibos, discrepancias, traslados, estado operativo |
| `produccion@example.com` | Operario de producción | Estación de producción |
| `cedi@example.com` | Operario logístico | Conteo ciego |
| `cedi2@example.com` | Operario logístico | Hizo el conteo que dio discrepancia |
| `admin@example.com` | Administrador | Todo, usuarios y auditoría |

## Simular la lectura del QR en el CEDI

Al final, el comando imprime el texto que leería el escáner para EST-00000005 (`TRZ1:` seguido de 32 caracteres). Para seguir el flujo desde ahí:

1. Entrar como `cedi@example.com` en `/cedi/count`, pegar ese texto y dar Enter. La pantalla muestra la estiba sin la cantidad esperada.
2. Escribir 10 cajas y registrar. Sale "Conteo aprobado".
3. Entrar como `supervisor@example.com` en `/transfers`, marcar la estiba y solicitar el traslado.
4. Con un worker corriendo (`composer dev` lo levanta), el traslado pasa a Confirmado con su DocNum.
5. En `/pallets/5` queda la historia completa: lecturas, cierre, paletizado, etiqueta, recibo, conteo y traslado.

Con otro número se abre una discrepancia, igual que con EST-00000003.

## Limitaciones

- Las decisiones pendientes (DP-01, DP-03, DP-05) se encienden solo dentro del proceso del comando. En la aplicación siguen apagadas, por eso la estación de producción dice "Recepción de lecturas apagada (DP-01)".
- El simulador de SAP vive en memoria. Al conciliar un pendiente desde la pantalla, el simulador del worker no lo conoce: dice que no existe y, pasado el tiempo de gracia, lo reenvía. En SAP real la conciliación lo encontraría.
- El scheduler no corre solo en local, así que `/operations` muestra "Sin señal" para el scheduler y los workers hasta que se levanten.

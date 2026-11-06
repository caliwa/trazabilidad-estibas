# Tablero de gestión (Inicio)

Al entrar, los usuarios con el permiso `dashboard.kpis` (rol **Supervisor de producción**; el Administrador del sistema lo tiene por tener todos) ven en el Inicio los indicadores de gestión. Los demás roles ven el Inicio como antes, con su rol: el operario logístico, en particular, nunca ve cantidades de cajas (conteo ciego). Se refresca solo cada minuto.

| Bloque | Qué muestra | Para qué |
|---|---|---|
| Cajas leídas hoy | Cajas contadas hoy y la variación contra **ayer a la misma hora** | Saber si el día va mejor o peor que ayer, a cualquier hora |
| Ritmo de la última hora | Cajas de los últimos 60 min contra el promedio del día (desde la primera caja) | Detectar caídas de ritmo en la línea |
| Estibas completadas hoy | Estibas cerradas hoy contra ayer a la misma hora | Productividad en estibas |
| Estibas en estibado ahora | Abiertas y su avance promedio | Qué tan cerca están de cerrarse |
| Cajas por hora | Columnas por hora, hoy (azul) contra ayer (gris), con detalle al pasar el mouse | Ver horas flojas, paradas y picos |
| Cajas por día | Últimos 14 días y el mejor día | Tendencia |
| Flujo de estibas | Estibas en cada etapa, en el orden del proceso (estibado → paletizar → etiquetar → recibo SAP → calidad → conteo CEDI → traslado a 05), con cuánto lleva la más antigua; marca el **cuello de botella** (la etapa, después del estibado, con más estibas) | Saber dónde se está represando el proceso |
| Órdenes de fabricación en curso | Las 6 de fecha de fin más próxima: cajas leídas contra planificadas, recibidas en SAP y trasladadas a 05; vencidas en rojo y aviso cuando se pasa de lo planificado | Priorizar órdenes |
| CEDI y calidad | Exactitud del conteo ciego a la primera (últimos 7 días), conteos de hoy, estibas esperando conteo (con la más antigua), discrepancias abiertas, retenidas por calidad | Calidad del proceso y pendientes del CEDI |
| Documentos SAP | Recibos y traslados confirmados hoy, esperando a SAP y fallidos hoy | Salud de la integración |
| Alertas operativas | Las mismas de Estado operativo | Problemas que requieren acción |

Los estados usan siempre ícono + texto (verde al día, ámbar atención, rojo crítico), nunca solo color. Consultas en `App\Queries\DashboardQuery`; pruebas en `DashboardTest`.

Después de desplegar: `php artisan db:seed --class=RolesAndPermissionsSeeder` para que el rol Supervisor de producción reciba el permiso.

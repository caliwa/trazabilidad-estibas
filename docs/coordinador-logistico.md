# Coordinador logístico e Inicio de logística

## Rol Coordinador logístico

Rol nuevo `logistics_coordinator` ("Coordinador logístico").

| Puede | Permiso |
|---|---|
| Conteo ciego | `audits.count` |
| Tablero de OF | `orders.view` |
| Estibas: tablero, detalle y lecturas rechazadas | `pallets.view` |

- El Tablero de OF y Estibas muestran cantidades de cajas, así que el conteo de este rol no es del todo ciego. Se decidió así porque es un rol de confianza, y el conteo deja registrado quién lo hizo.
- `orders.view` también deja usar "Actualizar desde SAP" en el Tablero de OF.
- El **Operario logístico no cambia**: cuenta a ciegas y sigue sin ver el Tablero de OF ni Estibas.
- Usuario de prueba: `coordinador.logistica@example.com` (`RoleUsersSeeder`).

## Inicio de logística

El operario logístico y el coordinador logístico (quien tiene `audits.count` y no tiene los indicadores de gestión) ven en Inicio un tablero que se refresca cada 30 s. Todo se muestra **en estibas y documentos, nunca en cajas ni unidades**, para no romper el conteo ciego.

- **Traslados a CEDI (04 → 05):**
  - trasladadas hoy (estibas y documentos confirmados en SAP);
  - por trasladar (listas más las que se están enviando a SAP);
  - por conciliar con SAP;
  - traslados fallidos por reintentar;
  - debajo, los traslados a no conforme (04 → 11C) de hoy y los pendientes.
- **Conteo ciego:**
  - estibas esperando conteo, con la antigüedad de la más vieja;
  - en conteo;
  - aprobadas hoy;
  - discrepancias abiertas.
- Estibas trasladadas a CEDI por día en los últimos 7 días.
- Últimos traslados: destino, número de estibas, documento SAP, hora y estado.

Las consultas están en `App\Queries\LogisticsBoardQuery`.

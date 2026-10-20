# Despliegue en Linux con Nginx, PHP-FPM y PostgreSQL

Guía para Ubuntu 24.04 o Debian 12. Los archivos de configuración están en `deploy/` y se copian tal cual, cambiando el dominio y las rutas del certificado.

> **Estado.** Todo esto está escrito y revisado, pero no se probó en un servidor real: en esta sesión no hay uno. Los scripts pasan `bash -n`. El primer despliegue conviene hacerlo en un servidor de pruebas siguiendo esta guía paso a paso.

## 1. Requisitos

| Componente | Versión |
|---|---|
| PHP-FPM y CLI | 8.3, con `pdo_pgsql`, `intl`, `mbstring`, `xml`, `curl`, `zip`, `opcache` |
| Composer | 2 |
| Node.js | 22, solo para compilar assets (puede ser en el CI y copiar `public/build`) |
| PostgreSQL | 16 (probado con 16.15). Mínimo 14 |
| Nginx | estable, con HTTP/2 |
| Supervisor | 4 |
| Certificado TLS | del dominio interno o público |

Recursos de partida para una planta: 2 vCPU, 4 GB de RAM, 40 GB de disco. PostgreSQL puede ir en el mismo servidor o en otro.

```bash
sudo apt install nginx supervisor postgresql-16 \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-intl php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-opcache
```

## 2. Usuario de sistema y carpetas

La aplicación corre con su propio usuario, sin shell de login para otras personas y sin sudo general.

```bash
sudo adduser --system --group --home /var/www/trazabilidad --shell /bin/bash trazabilidad
sudo mkdir -p /var/www/trazabilidad/{releases,shared/storage}
sudo chown -R trazabilidad:trazabilidad /var/www/trazabilidad
sudo -u trazabilidad mkdir -p /var/www/trazabilidad/shared/storage/{app/public,framework/{cache,sessions,views},logs}
sudo chmod -R u=rwX,g=rX,o= /var/www/trazabilidad/shared
sudo mkdir -p /var/log/php && sudo chown trazabilidad /var/log/php
```

Para que `deploy.sh` recargue PHP-FPM sin sudo general, `/etc/sudoers.d/trazabilidad`:

```
trazabilidad ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm
```

Permisos resultantes: el código (`releases/*`) lo puede leer `trazabilidad` y nadie más lo escribe; solo `shared/storage` es escribible. Nginx (`www-data`) no lee el código: habla con PHP-FPM por el socket y sirve archivos solo de `public/`.

```bash
sudo usermod -aG trazabilidad www-data   # Nginx necesita leer public/build
```

## 3. Base de datos

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE trazabilidad LOGIN PASSWORD 'CAMBIAR-POR-UNA-CLAVE-LARGA';
CREATE DATABASE trazabilidad OWNER trazabilidad ENCODING 'UTF8';
SQL
```

El rol es dueño de su base y nada más: no es superusuario. Eso importa porque la bitácora (`trace_events`) es inmutable por triggers, y un superusuario los podría saltar.

## 4. Configuración (`shared/.env`)

```bash
sudo -u trazabilidad cp .env.example /var/www/trazabilidad/shared/.env
sudo -u trazabilidad chmod 600 /var/www/trazabilidad/shared/.env
```

Lo mínimo para producción (el resto de `.env.example` tiene comentarios):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                       # php artisan key:generate --show, y pegarlo aquí
APP_URL=https://estibas.example.com
APP_TIMEZONE=America/Bogota
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=trazabilidad
DB_USERNAME=trazabilidad
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=notificaciones@example.com

SAP_DRIVER=...                 # el conector real no existe todavía (DP-09)
```

Reglas:

- `.env` nunca va al repositorio ni a un respaldo sin cifrar. Se guarda aparte, en el gestor de secretos de la empresa.
- Las credenciales de SAP deben ser de un usuario con permisos solo para crear y leer recibos de producción y traslados, y leer órdenes y artículos.
- Las banderas `TRACE_*` de decisiones pendientes se dejan apagadas hasta que cada DP esté aprobada. `app:preflight` las lista.

## 5. PHP y PHP-FPM

```bash
sudo cp deploy/php/trazabilidad-fpm.conf /etc/php/8.3/fpm/pool.d/trazabilidad.conf
sudo cp deploy/php/99-trazabilidad.ini /etc/php/8.3/fpm/conf.d/
sudo cp deploy/php/99-trazabilidad.ini /etc/php/8.3/cli/conf.d/
sudo rm /etc/php/8.3/fpm/pool.d/www.conf     # si el servidor no sirve nada más
sudo systemctl restart php8.3-fpm
```

El pool corre como `trazabilidad`, tiene `display_errors` apagado sin que la aplicación pueda prenderlo, y un socket propio. `opcache.validate_timestamps=0`: PHP no revisa los archivos en cada petición, por eso `deploy.sh` recarga PHP-FPM al cambiar de versión.

## 6. Nginx y TLS

```bash
sudo cp deploy/nginx/trazabilidad.conf /etc/nginx/sites-available/trazabilidad
sudo ln -s /etc/nginx/sites-available/trazabilidad /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Qué hace la configuración:

- Redirige HTTP a HTTPS. TLS 1.2 y 1.3 solamente.
- La raíz es `current/public`. `.env`, `storage`, `vendor` y el código quedan fuera.
- Solo ejecuta `index.php`; cualquier otro `.php` da 404. Niega archivos ocultos.
- La aplicación agrega HSTS y el resto de cabeceras.

Certificado: si el dominio es público, `certbot --nginx`. Si es interno, el que emita la CA de la empresa. Los lectores y sensores deben confiar en ese certificado.

## 7. Primera instalación

```bash
sudo -u trazabilidad -i
cd /var/www/trazabilidad
git clone <repo> releases/inicial && cd releases/inicial
rm -rf storage && ln -s ../../shared/storage storage && ln -s ../../shared/.env .env
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -rf node_modules
php artisan app:preflight
php artisan migrate --force
php artisan db:seed --force          # roles y permisos; en producción no crea usuarios
php artisan app:create-admin
php artisan optimize
ln -sfn /var/www/trazabilidad/releases/inicial /var/www/trazabilidad/current
```

## 8. Colas y workers (Supervisor)

```bash
sudo cp deploy/supervisor/trazabilidad.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status
```

Hay dos workers:

| Programa | Cola | Para |
|---|---|---|
| `trazabilidad-sap` | `sap` | Envío y conciliación de recibos y traslados. Un intento por job (`--tries=1`): los reintentos los decide la conciliación, nunca la cola |
| `trazabilidad-default` | `default` | Correos, notificaciones, traslado automático |

Los tiempos están ajustados entre sí: `--timeout=75` alcanza para crear y verificar en SAP (30 s cada llamada), y es menor que `DB_QUEUE_RETRY_AFTER=90`, así otro worker nunca toma un job que sigue corriendo. Aun si pasara, el estado ENVIANDO lo frena.

Para más volumen se sube `numprocs`. Es seguro: los envíos se protegen con bloqueos en PostgreSQL, no con el número de workers.

## 9. Scheduler

```bash
sudo cp deploy/cron/trazabilidad /etc/cron.d/trazabilidad
```

| Tarea | Frecuencia | Para |
|---|---|---|
| `sap:sweep` | cada minuto | Reencola envíos perdidos, pasa a conciliación los ENVIANDO colgados, concilia pendientes |
| `ops:heartbeat` | cada minuto | Latido del scheduler y de cada cola |
| `audits:expire-counts` | cada 5 min | Cancela conteos ciegos abandonados |
| `ops:check` | cada 5 min | Alertas operativas a quienes tienen `ops.alerts` |

## 10. Despliegue de una versión nueva

```bash
sudo -u trazabilidad /var/www/trazabilidad/current/deploy/scripts/deploy.sh v1.2.0
```

Lo que hace `deploy.sh`, en orden:

1. Clona la versión en una carpeta nueva de `releases/`, enlaza `shared/.env` y `shared/storage`.
2. Instala dependencias y compila (o copia) los assets.
3. Corre `app:preflight`. Si falla, se detiene y la versión activa no cambia.
4. Migra y sincroniza permisos.
5. Cambia el symlink `current` de forma atómica (`mv -T`).
6. Recarga PHP-FPM (las peticiones en curso terminan con el código viejo).
7. `queue:restart`: cada worker termina su job y Supervisor lo levanta con el código nuevo.
8. Borra versiones viejas, deja 5.
9. Consulta `/api/health`.

**Por qué no se pierden trabajos.** Los jobs están en la tabla `jobs` de PostgreSQL, no en memoria. Cambiar de versión no los toca. `queue:restart` no mata el job en curso: el worker lo termina y sale. Si alguien hace `kill -9` a mitad de un envío a SAP, el documento queda ENVIANDO y `sap:sweep` lo pasa a conciliación: nunca se reenvía a ciegas.

**Regla para migraciones.** Cada migración tiene que funcionar con la versión anterior del código, porque la versión vieja sigue atendiendo mientras corre `migrate`. Para cambios que no lo permiten (renombrar columnas, cambiar un estado), se hace en dos versiones: primero agregar, después quitar. Si de verdad no se puede, `php artisan down` antes y `php artisan up` después.

**Clases de jobs.** No renombrar ni cambiar el constructor de un job en la misma versión en que todavía puede haber jobs viejos encolados. Si hace falta, dejar la clase vieja una versión más.

## 11. Rollback

```bash
sudo -u trazabilidad /var/www/trazabilidad/current/deploy/scripts/rollback.sh
```

Vuelve el symlink a la versión anterior, recarga PHP-FPM y reinicia workers. **No revierte migraciones.** Con la regla de la sección 10 no hace falta: la versión anterior funciona con el esquema nuevo. Si una migración rompió datos, se restaura desde respaldo (sección 13) y se aplica el procedimiento de conciliación después de restaurar.

## 12. Logs

| Qué | Dónde |
|---|---|
| Aplicación (JSON con `correlation_id`, secretos enmascarados) | `shared/storage/logs/laravel.log` |
| Workers | `shared/storage/logs/worker-*.log` |
| PHP-FPM | `/var/log/php/trazabilidad-fpm.log` |
| Nginx | `/var/log/nginx/` |
| Respaldos | `/var/log/trazabilidad-backup.log` |
| Bitácora operativa | tabla `trace_events` (inmutable) |
| Llamadas a SAP con latencia | tabla `integration_attempts` |

Rotación de `laravel.log` con logrotate (`/etc/logrotate.d/trazabilidad`):

```
/var/www/trazabilidad/shared/storage/logs/laravel.log {
    daily
    rotate 30
    compress
    missingok
    notifempty
    copytruncate
}
```

**Retención.** `trace_events` e `integration_attempts` no se borran nunca desde la aplicación. Si el negocio define un plazo, se archiva con un procedimiento aprobado, no con `DELETE`.

## 13. Respaldos y restauración

`deploy/cron/trazabilidad` corre `deploy/scripts/backup.sh` todos los días a las 02:15 como `postgres`:

- `pg_dump` en formato custom a `/var/backups/trazabilidad`, permisos 600;
- verifica que el archivo se pueda leer (`pg_restore --list`);
- borra los de más de 30 días.

Además: copiar esa carpeta fuera del servidor (otro equipo o almacenamiento de la empresa), y guardar `.env` aparte, cifrado.

**Restaurar:**

```bash
sudo supervisorctl stop trazabilidad:*
sudo -u trazabilidad php /var/www/trazabilidad/current/artisan down
sudo -u postgres pg_restore --clean --if-exists --no-owner --role=trazabilidad -d trazabilidad /var/backups/trazabilidad/trazabilidad-AAAAMMDD-HHMMSS.dump
sudo -u trazabilidad php /var/www/trazabilidad/current/artisan up
sudo supervisorctl start trazabilidad:*
```

**Después de restaurar, antes de operar:** el respaldo es de una hora anterior. Todo lo que pasó después se perdió en la base local, **pero no en SAP**. Hay que revisar en SAP los recibos y traslados creados desde la hora del respaldo (por el campo de correlación, DP-09) y reconstruir esas solicitudes o anotarlas. Ver `docs/operacion.md`, sección 5.

Probar una restauración en un servidor aparte al menos una vez por trimestre.

## 14. Health checks

| URL | Responde | Usar para |
|---|---|---|
| `/up` | 200 si PHP y Laravel arrancan | Balanceador |
| `/api/health` | 200 `{"status":"ok"}` si base de datos, scheduler y los dos workers están vivos; 503 si algo falla | Monitoreo externo (Uptime Kuma, Zabbix, Nagios) |

`/api/health` no muestra versiones, hosts, cantidades ni mensajes de error. Tiene límite de 60 consultas por minuto. Como `ops:check` corre en el scheduler, **si el scheduler muere no hay alertas**: por eso el monitoreo externo de `/api/health` es obligatorio.

## 15. Reinicio seguro de servicios

| Servicio | Comando | Efecto |
|---|---|---|
| PHP-FPM | `systemctl reload php8.3-fpm` | Termina las peticiones en curso, toma código nuevo |
| Workers | `php artisan queue:restart` | Terminan el job actual y se reinician |
| Workers (parar) | `supervisorctl stop trazabilidad:*` | Espera hasta `stopwaitsecs` a que termine el job |
| Nginx | `nginx -t && systemctl reload nginx` | Sin cortar conexiones |
| PostgreSQL | detener workers primero, después `systemctl restart postgresql` | Un envío a SAP cortado queda ENVIANDO y se concilia solo |

## 16. Verificación después de desplegar

- `php artisan app:preflight` en verde.
- `curl -fsS https://estibas.example.com/api/health` responde `ok`.
- `sudo supervisorctl status`: los dos workers `RUNNING`.
- Pantalla **Estado operativo** (`/operations`) sin alertas críticas.
- `php artisan about`: entorno production, debug apagado, caches activas.

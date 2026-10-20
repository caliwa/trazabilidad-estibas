#!/usr/bin/env bash
# Despliega una versión sin perder trabajos en cola.
#
# Uso (como el usuario trazabilidad):  deploy.sh <tag-o-commit>
#
# Estructura:
#   /var/www/trazabilidad/releases/<fecha>   una carpeta por versión
#   /var/www/trazabilidad/shared/.env        configuración (fuera del repo)
#   /var/www/trazabilidad/shared/storage     logs, sesiones de archivo, etc.
#   /var/www/trazabilidad/current            symlink a la versión activa
#
# Los trabajos viven en la tabla jobs de PostgreSQL, no en memoria: cambiar
# de versión no los borra. queue:restart deja que cada worker termine el
# job en curso y Supervisor lo vuelve a levantar con el código nuevo.
set -euo pipefail

REF="${1:?Uso: deploy.sh <tag-o-commit>}"
APP=/var/www/trazabilidad
REPO="${REPO:-git@github.com:wilsonosoriocaro-blip/trazabilidad-estibas.git}"
KEEP="${KEEP:-5}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
RELEASE="$APP/releases/$(date +%Y%m%d%H%M%S)"

echo "==> Versión $REF en $RELEASE"
git clone --quiet "$REPO" "$RELEASE"
git -C "$RELEASE" checkout --quiet "$REF"

cd "$RELEASE"
rm -rf storage
ln -s "$APP/shared/storage" storage
ln -s "$APP/shared/.env" .env

composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Assets: compilados aquí, o copiados desde el artefacto del CI si el servidor no tiene Node.
if [[ -d "${BUILD_DIR:-}" ]]; then
    cp -r "$BUILD_DIR" public/build
else
    npm ci --no-audit --no-fund && npm run build && rm -rf node_modules
fi

echo "==> Verificación previa"
php artisan app:preflight

echo "==> Migraciones"
# Las migraciones deben ser compatibles con la versión anterior (agregar antes
# de quitar), así la versión vieja sigue funcionando mientras esto corre.
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize

echo "==> Activando"
ln -sfn "$RELEASE" "$APP/current.new"
mv -Tf "$APP/current.new" "$APP/current"

sudo systemctl reload "$PHP_FPM_SERVICE"
php "$APP/current/artisan" queue:restart

echo "==> Limpiando versiones viejas (se conservan $KEEP)"
ls -1dt "$APP"/releases/* | tail -n +"$((KEEP + 1))" | xargs -r rm -rf

echo "==> Comprobando salud"
sleep 70
curl -fsS "${HEALTH_URL:-https://localhost/api/health}" -k || { echo "La salud no responde ok: revisar /operations y Supervisor"; exit 1; }
echo
echo "Listo: $(readlink "$APP/current")"

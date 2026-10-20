#!/usr/bin/env bash
# Vuelve a la versión anterior. No revierte migraciones: por eso deben ser
# compatibles hacia atrás. Si una migración no lo es, el rollback se planea
# aparte, con respaldo (ver docs/despliegue.md, sección Rollback).
set -euo pipefail

APP=/var/www/trazabilidad
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
CURRENT="$(readlink -f "$APP/current")"
PREVIOUS="$(ls -1dt "$APP"/releases/* | grep -vx "$CURRENT" | head -n 1 || true)"

if [[ -z "$PREVIOUS" ]]; then
    echo "No hay versión anterior." >&2
    exit 1
fi

echo "==> De $CURRENT a $PREVIOUS"
ln -sfn "$PREVIOUS" "$APP/current.new"
mv -Tf "$APP/current.new" "$APP/current"

cd "$APP/current"
php artisan optimize
sudo systemctl reload "$PHP_FPM_SERVICE"
php artisan queue:restart

echo "Listo. La versión descartada sigue en disco: $CURRENT"

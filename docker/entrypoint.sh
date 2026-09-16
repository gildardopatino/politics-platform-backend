#!/bin/sh
set -e

wait_for_db() {
    echo "Waiting for database ${DB_HOST}:${DB_PORT}..."
    until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}" >/dev/null 2>&1; do
        sleep 2
    done
    echo "Database is ready."
}

# Devuelve 0 si la BD ya fue inicializada (existe la tabla migrations)
db_initialized() {
    PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" \
        -U "${DB_USERNAME:-postgres}" -d "${DB_DATABASE}" -tAc \
        "SELECT to_regclass('public.migrations') IS NOT NULL" | grep -q t
}

run_setup() {
    php artisan migrate --force
    php artisan db:seed --class=Database\\Seeders\\SuperAdminSeeder --force
    php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
    php artisan db:seed --class=Database\\Seeders\\GeographySeeder --force
    php artisan db:seed --class=Database\\Seeders\\PrioritySeeder --force
}

# Solo el contenedor principal (php-fpm) toca el esquema; queue/scheduler solo esperan
if [ "$1" = "php-fpm" ]; then
    wait_for_db

    if ! db_initialized; then
        echo "BD vacía: ejecutando migraciones y seeders iniciales."
        run_setup
    elif [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        echo "RUN_MIGRATIONS=true: aplicando migraciones pendientes."
        php artisan migrate --force
    else
        echo "BD ya inicializada: se omiten migraciones y seeders."
    fi
else
    wait_for_db
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"

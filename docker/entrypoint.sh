#!/bin/sh
set -e

# Wait for the database to accept connections before doing anything DB-related.
wait_for_db() {
    echo "Waiting for database ${DB_HOST}:${DB_PORT}..."
    until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}" >/dev/null 2>&1; do
        sleep 2
    done
    echo "Database is ready."
}

# Migrations + idempotent essential seeders run ONLY in the main app container
# (the one started with php-fpm), never in queue/scheduler, to avoid races.
if [ "$1" = "php-fpm" ]; then
    wait_for_db

    php artisan migrate --force

    php artisan db:seed --class=Database\\Seeders\\SuperAdminSeeder --force
    php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
    php artisan db:seed --class=Database\\Seeders\\GeographySeeder --force
    php artisan db:seed --class=Database\\Seeders\\PrioritySeeder --force
else
    # queue / scheduler: just make sure the DB is up before starting.
    wait_for_db
fi

# Build framework caches (local to this container's bootstrap/cache).
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"

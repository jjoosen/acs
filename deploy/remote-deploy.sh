#!/usr/bin/env bash
# Draait OP de testserver (via SSH vanuit GitHub Actions).
# Verwacht env: DEPLOY_PATH (basis), RELEASE (volledig pad van de nieuwe release).
set -euo pipefail

: "${DEPLOY_PATH:?}"; : "${RELEASE:?}"
SHARED="$DEPLOY_PATH/shared"
mkdir -p "$SHARED" "$SHARED/var/log" "$SHARED/public/uploads" "$SHARED/upload"

cd "$RELEASE"

# Gedeelde (persistente) zaken koppelen.
ln -sfn "$SHARED/.env.local"      "$RELEASE/.env.local"
rm -rf  "$RELEASE/var";            ln -sfn "$SHARED/var" "$RELEASE/var"
ln -sfn "$SHARED/public/uploads"  "$RELEASE/public/uploads"
ln -sfn "$SHARED/upload"          "$RELEASE/upload"
mkdir -p "$SHARED/var/cache"

export APP_ENV=prod

# Doctrine (ORM) + PHPCR.
php bin/console doctrine:migrations:migrate -n --allow-no-migration
php bin/console doctrine:phpcr:init:dbal --if-not-exists || true
php bin/console sulu:document:initialize -n

# Eerste deploy: content uit de seed migreren + admin-user (eenmalig via marker).
if [ ! -f "$SHARED/.migrated" ]; then
  echo "Eerste deploy: content seeden vanuit deploy/seed/legacy.db ..."
  php bin/console app:migrate --source-dsn="pdo-sqlite:///$RELEASE/deploy/seed/legacy.db" || true
  php bin/console sulu:security:role:create Administrator Sulu || true
  php bin/console sulu:security:user:create admin Beheerder Beheerder admin@acs.be en Administrator "${ADMIN_PASSWORD:-AcsAdmin2026!}" || true
  touch "$SHARED/.migrated"
fi

# Cache.
php bin/console cache:clear
php bin/console cache:warmup

# Atomair activeren.
ln -sfn "$RELEASE" "$DEPLOY_PATH/current"

# Oude releases opruimen (laatste 5 bewaren).
cd "$DEPLOY_PATH/releases" && ls -1dt */ | tail -n +6 | xargs -r rm -rf

echo "Release actief: $RELEASE"
echo "Frontend: jouw testdomein/  |  Admin: jouw testdomein/admin"
echo "Content + admin worden bij de EERSTE deploy automatisch geseed (marker: $SHARED/.migrated)."
echo "Opnieuw seeden? Verwijder $SHARED/.migrated en deploy opnieuw."

#!/usr/bin/env bash
# Draait OP de Combell-server (via SSH vanuit GitHub Actions), IN-PLACE in de
# vaste docroot ($DEPLOY_PATH). De app staat in de docroot; een root-.htaccess
# routeert alles via public/. Database = SQLite (var/data.db) — geen MySQL nodig.
#
# Env: DEPLOY_PATH (vereist), PHP_BIN (optioneel, default "php"),
#      ADMIN_PASSWORD (optioneel).
set -euo pipefail
: "${DEPLOY_PATH:?}"
PHP="${PHP_BIN:-php}"
cd "$DEPLOY_PATH"

mkdir -p var/cache var/log public/uploads upload

# .env.local automatisch aanmaken (prod + SQLite) als die nog niet bestaat.
if [ ! -f .env.local ]; then
  SECRET="$($PHP -r 'echo bin2hex(random_bytes(16));')"
  cat > .env.local <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$SECRET
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
ENV
  echo ".env.local aangemaakt (prod, SQLite)."
fi

export APP_ENV=prod

# Root-.htaccess plaatsen (alles via public/).
cp -f deploy/htaccess-docroot .htaccess

# Schema is idempotent met schema:update; PHPCR + home altijd verzekeren.
$PHP bin/console doctrine:schema:update --force -n || true
$PHP bin/console doctrine:phpcr:init:dbal --if-not-exists || true
$PHP bin/console sulu:document:initialize -n || true

# Eenmalig seeden (marker var/.seeded). data.db is geen goede marker, want
# schema:update maakt die al aan vóór het seeden.
if [ ! -f var/.seeded ]; then
  echo "Eerste deploy: content seeden ..."
  # Seed naar schrijfbare var/ kopiëren + absoluut pad (vermijdt CANTOPEN).
  cp -f deploy/seed/legacy.db var/seed.db
  SEED="$(pwd)/var/seed.db"
  $PHP bin/console app:migrate --source-dsn="pdo-sqlite:///$SEED"
  $PHP bin/console sulu:security:role:create Administrator Sulu || true
  $PHP bin/console sulu:security:user:create admin Beheerder Beheerder admin@acs.be en Administrator "${ADMIN_PASSWORD:-AcsAdmin2026!}" || true
  touch var/.seeded
  echo "Seed voltooid."
else
  echo "Al geseed (var/.seeded aanwezig) — content ongemoeid gelaten."
fi

$PHP bin/console cache:clear
$PHP bin/console cache:warmup
chmod -R 775 var || true

echo "Klaar. Frontend: jouw domein/   |  Admin: jouw domein/admin  (admin / ${ADMIN_PASSWORD:-AcsAdmin2026!})"
echo "Opnieuw seeden? Verwijder $DEPLOY_PATH/var/.seeded (en evt. var/data.db) en deploy opnieuw."

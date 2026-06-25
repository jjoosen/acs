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

if [ ! -f var/data.db ]; then
  echo "Eerste deploy: schema opbouwen + content seeden ..."
  $PHP bin/console doctrine:schema:create -n
  $PHP bin/console doctrine:phpcr:init:dbal --if-not-exists || true
  $PHP bin/console sulu:document:initialize -n
  $PHP bin/console app:migrate --source-dsn="pdo-sqlite:///$DEPLOY_PATH/deploy/seed/legacy.db"
  $PHP bin/console sulu:security:role:create Administrator Sulu || true
  $PHP bin/console sulu:security:user:create admin Beheerder Beheerder admin@acs.be en Administrator "${ADMIN_PASSWORD:-AcsAdmin2026!}" || true
else
  echo "Bestaande database gevonden: alleen schema/cache bijwerken."
  $PHP bin/console doctrine:schema:update --force -n || true
  $PHP bin/console doctrine:phpcr:init:dbal --if-not-exists || true
  $PHP bin/console sulu:document:initialize -n || true
fi

$PHP bin/console cache:clear
$PHP bin/console cache:warmup
chmod -R 775 var || true

echo "Klaar. Frontend: jouw domein/   |  Admin: jouw domein/admin  (admin / ${ADMIN_PASSWORD:-AcsAdmin2026!})"
echo "Opnieuw seeden? Verwijder $DEPLOY_PATH/var/data.db en deploy opnieuw."

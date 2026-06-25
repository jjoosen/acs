#!/usr/bin/env bash
# Draait OP de Combell-server (via SSH vanuit GitHub Actions), IN-PLACE in de
# vaste docroot ($DEPLOY_PATH). De app staat in de docroot; een root-.htaccess
# routeert alles via public/.
#
# Verwacht: DEPLOY_PATH (= de docroot waar de app naartoe gersynct is).
set -euo pipefail
: "${DEPLOY_PATH:?}"
cd "$DEPLOY_PATH"

# Persistente map voor de eenmalige-seed-marker (en evt. logs).
mkdir -p var public/uploads upload

# .env.local moet bestaan (eenmalig handmatig aangemaakt, met DATABASE_URL).
if [ ! -f .env.local ]; then
  echo "FOUT: $DEPLOY_PATH/.env.local ontbreekt. Maak die eerst aan (zie .env.test.dist)."
  exit 1
fi

export APP_ENV=prod

# Root-.htaccess plaatsen (alles via public/).
cp -f deploy/htaccess-docroot .htaccess

# Doctrine (ORM) + PHPCR (doctrine-dbal, geen Java).
php bin/console doctrine:migrations:migrate -n --allow-no-migration
php bin/console doctrine:phpcr:init:dbal --if-not-exists || true
php bin/console sulu:document:initialize -n

# Eerste deploy: content uit de seed migreren + admin-user (eenmalig via marker).
if [ ! -f var/.migrated ]; then
  echo "Eerste deploy: content seeden vanuit deploy/seed/legacy.db ..."
  php bin/console app:migrate --source-dsn="pdo-sqlite:///$DEPLOY_PATH/deploy/seed/legacy.db" || true
  php bin/console sulu:security:role:create Administrator Sulu || true
  php bin/console sulu:security:user:create admin Beheerder Beheerder admin@acs.be en Administrator "${ADMIN_PASSWORD:-AcsAdmin2026!}" || true
  touch var/.migrated
fi

php bin/console cache:clear
php bin/console cache:warmup

echo "Klaar. Frontend: jouw domein/   |  Admin: jouw domein/admin  (admin / ${ADMIN_PASSWORD:-AcsAdmin2026!})"
echo "Opnieuw seeden? Verwijder $DEPLOY_PATH/var/.migrated en deploy opnieuw."

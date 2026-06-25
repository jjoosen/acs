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

# Reseed-beslissing: opnieuw seeden bij een nieuwe deploy/seed/VERSION
# (bv. na migratie-wijzigingen zoals menu's) of als er nog niets staat.
SEED_VER="$(cat deploy/seed/VERSION 2>/dev/null || echo 1)"
CUR_VER="$(cat var/.seed-version 2>/dev/null || echo 0)"
RESEED=0
if [ "$SEED_VER" != "$CUR_VER" ] || [ ! -f var/.seeded ]; then
  RESEED=1
  # Schone database voor een nette (her)seed (geen dubbele content).
  rm -f var/data.db
fi

# Schema + PHPCR. De live-workspace (default_live) is nodig voor meertaligheid
# en publiceren; maak ze aan en wis daarna de PHPCR-cache (anders blijft een
# "root niet gevonden" gecachet en faalt document:initialize).
$PHP bin/console doctrine:schema:update --force -n
$PHP bin/console doctrine:phpcr:init:dbal --if-not-exists || true
$PHP bin/console doctrine:phpcr:workspace:create default_live 2>/dev/null || true
rm -rf var/cache/* 2>/dev/null || true
$PHP bin/console sulu:document:initialize -n

# Admin VÓÓR de migratie aanmaken: zo kun je meteen inloggen, ook als de
# (lange) content-seed nog draait of opnieuw moet. Idempotent.
$PHP bin/console sulu:security:role:create Administrator Sulu || true
$PHP bin/console sulu:security:user:create admin Beheerder Beheerder admin@acs.be en Administrator "${ADMIN_PASSWORD:-AcsAdmin2026!}" || true

if [ "$RESEED" = "1" ]; then
  echo "Content (her)seeden: versie $CUR_VER -> $SEED_VER ..."
  cp -f deploy/seed/legacy.db var/seed.db
  SEED="$(pwd)/var/seed.db"
  $PHP bin/console app:migrate --source-dsn="pdo-sqlite:///$SEED"
  echo "$SEED_VER" > var/.seed-version
  touch var/.seeded
  echo "Seed voltooid (versie $SEED_VER)."
else
  echo "Geen reseed nodig (seed-versie $CUR_VER)."
fi

$PHP bin/console cache:clear
$PHP bin/console cache:warmup
chmod -R 775 var || true

# Combell-docroot is de symlink 'www' in de home-map. Die naar onze public/
# laten wijzen (absoluut pad), zodat het domein onze Sulu-app serveert.
# (DEPLOY_PATH kan relatief zijn; $(pwd) is hier het absolute projectpad.)
APP_DIR="$(pwd)"
WWW="${HOME:-$(dirname "$APP_DIR")}/www"
if [ -L "$WWW" ] || [ ! -e "$WWW" ]; then
  ln -sfn "$APP_DIR/public" "$WWW"
  echo "Docroot symlink: $WWW -> $APP_DIR/public"
elif [ -d "$WWW" ]; then
  echo "LET OP: $WWW is een echte map (geen symlink). Niet aangepast — handmatig nodig."
fi

echo "Klaar. Frontend: jouw domein/   |  Admin: jouw domein/admin  (admin / ${ADMIN_PASSWORD:-AcsAdmin2026!})"
echo "Opnieuw seeden? Verwijder $DEPLOY_PATH/var/.seeded (en evt. var/data.db) en deploy opnieuw."

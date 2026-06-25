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

# Nog niet (volledig) geseed? Begin met een schone database, zodat een eerdere
# half-afgebroken seed (bv. door SSH-timeout) geen dubbele content geeft.
if [ ! -f var/.seeded ]; then
  rm -f var/data.db
fi

# Schema + PHPCR + home (idempotent; maakt op een verse SQLite alles aan).
$PHP bin/console doctrine:schema:update --force -n
$PHP bin/console doctrine:phpcr:init:dbal --if-not-exists || true
$PHP bin/console sulu:document:initialize -n

# Admin VÓÓR de migratie aanmaken: zo kun je meteen inloggen, ook als de
# (lange) content-seed nog draait of opnieuw moet. Idempotent.
$PHP bin/console sulu:security:role:create Administrator Sulu || true
$PHP bin/console sulu:security:user:create admin Beheerder Beheerder admin@acs.be en Administrator "${ADMIN_PASSWORD:-AcsAdmin2026!}" || true

# Eenmalig content seeden (marker var/.seeded).
if [ ! -f var/.seeded ]; then
  echo "Content seeden ..."
  cp -f deploy/seed/legacy.db var/seed.db
  SEED="$(pwd)/var/seed.db"
  $PHP bin/console app:migrate --source-dsn="pdo-sqlite:///$SEED"
  touch var/.seeded
  echo "Seed voltooid."
else
  echo "Al geseed (var/.seeded aanwezig) — content ongemoeid gelaten."
fi

$PHP bin/console cache:clear
$PHP bin/console cache:warmup
chmod -R 775 var || true

# Combell-docroot is de symlink 'www' (wijst nu naar de oude site). Laten
# wijzen naar onze public/, zodat het domein onze Sulu-app serveert.
WWW="$(dirname "$DEPLOY_PATH")/www"
if [ -L "$WWW" ] || [ ! -e "$WWW" ]; then
  ln -sfn "$DEPLOY_PATH/public" "$WWW"
  echo "Docroot symlink: $WWW -> $DEPLOY_PATH/public"
elif [ -d "$WWW" ]; then
  echo "LET OP: $WWW is een echte map (geen symlink). Niet aangepast — handmatig nodig."
fi

echo "Klaar. Frontend: jouw domein/   |  Admin: jouw domein/admin  (admin / ${ADMIN_PASSWORD:-AcsAdmin2026!})"
echo "Opnieuw seeden? Verwijder $DEPLOY_PATH/var/.seeded (en evt. var/data.db) en deploy opnieuw."

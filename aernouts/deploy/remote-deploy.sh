#!/usr/bin/env bash
# Draait OP de Combell-server (via SSH vanuit GitHub Actions), IN-PLACE in de map
# van het aernouts-project ($DEPLOY_PATH, bv. ~/aernouts). Zelfstandige Sulu-app
# (los van ACS). Database = SQLite (var/data.db).
#
# Env: DEPLOY_PATH (vereist), PHP_BIN (optioneel), ADMIN_PASSWORD (optioneel).
set -euo pipefail
: "${DEPLOY_PATH:?}"
PHP="${PHP_BIN:-php}"
cd "$DEPLOY_PATH"

mkdir -p var/cache var/log public/uploads

# .env.local (prod + SQLite) automatisch aanmaken.
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

# Root-.htaccess plaatsen zodat de app ook werkt als de docroot de projectmap is
# (routeert alles via public/). Als de subdomein-docroot al direct naar public/
# wijst, is dit onschadelijk.
cp -f deploy/htaccess-docroot .htaccess

# Schema + PHPCR (idempotent). Live-workspace nodig voor publiceren.
$PHP bin/console doctrine:schema:update --force -n
$PHP bin/console doctrine:phpcr:init:dbal --if-not-exists || true
$PHP bin/console doctrine:phpcr:workspace:create default_live 2>/dev/null || true
rm -rf var/cache/* 2>/dev/null || true
$PHP bin/console sulu:document:initialize -n

# Admin-gebruiker (idempotent).
$PHP bin/console sulu:security:role:create Administrator Sulu || true
$PHP bin/console sulu:security:user:create admin Beheerder Beheerder admin@aernouts.be nl Administrator "${ADMIN_PASSWORD:-AernoutsAdmin2026!}" || true

$PHP bin/console cache:clear
$PHP bin/console cache:warmup
chmod -R 775 var || true

# Docroot: de subdomein-docroot van aernouts.goldeneye.digital moet naar
# $DEPLOY_PATH/public wijzen. We proberen enkele veelgebruikte Combell-locaties
# als symlink te leggen; raak NOOIT ~/www aan (dat is de ACS-site).
APP_DIR="$(pwd)"
HOME_DIR="${HOME:-$(dirname "$APP_DIR")}"
for CAND in "$HOME_DIR/aernouts.goldeneye.digital" "$HOME_DIR/www/aernouts" "$HOME_DIR/subsites/aernouts"; do
  PARENT="$(dirname "$CAND")"
  if [ -d "$PARENT" ]; then
    if [ -L "$CAND" ] || [ ! -e "$CAND" ]; then
      ln -sfn "$APP_DIR/public" "$CAND" && echo "Docroot-symlink: $CAND -> $APP_DIR/public"
    fi
  fi
done

echo "Klaar. Zet de docroot van aernouts.goldeneye.digital in Combell op: $APP_DIR/public"
echo "Admin: aernouts.goldeneye.digital/admin  (admin / ${ADMIN_PASSWORD:-AernoutsAdmin2026!})"

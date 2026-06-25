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

# Cache.
php bin/console cache:clear
php bin/console cache:warmup

# Atomair activeren.
ln -sfn "$RELEASE" "$DEPLOY_PATH/current"

# Oude releases opruimen (laatste 5 bewaren).
cd "$DEPLOY_PATH/releases" && ls -1dt */ | tail -n +6 | xargs -r rm -rf

echo "Release actief: $RELEASE"
echo "LET OP (eenmalig, handmatig): content migreren + admin-user:"
echo "  php $DEPLOY_PATH/current/bin/console app:migrate --source-dsn='mysql://user:pass@127.0.0.1:3306/acs_legacy'"
echo "  php $DEPLOY_PATH/current/bin/console sulu:security:role:create Administrator Sulu"
echo "  php $DEPLOY_PATH/current/bin/console sulu:security:user:create admin Beheerder Beheerder mail@acs.be en Administrator '<pwd>'"

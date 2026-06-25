# Deploy naar de testomgeving (Combell webhosting, vaste docroot)

Deze sandbox kan niet rechtstreeks naar een server (uitgaand SSH/HTTP geblokkeerd).
De deploy gebeurt daarom via **GitHub Actions**: een push naar de branch bouwt
het project en zet het via SSH/rsync op de testserver. Resultaat: *git push =
testomgeving bijgewerkt*.

**Model voor Combell-webhosting (vaste docroot):** de volledige app wordt
*in-place* in de docroot (`DEPLOY_PATH`) gersynct. Een root-`.htaccess`
(`deploy/htaccess-docroot`) routeert alle requests via `public/`, zodat
`config/`, `src/`, `vendor/` niet rechtstreeks bereikbaar zijn. Geen Java/
Jackrabbit nodig (PHPCR via doctrine-dbal in MySQL). De **eerste** deploy seedt
automatisch de content (uit `deploy/seed/legacy.db` → jouw MySQL) en maakt de
admin-user aan (marker `var/.migrated`).

```
  jij/Claude ──git push──► GitHub ──Actions(build+rsync over SSH)──► TESTSERVER
                                                                      (PHP+MySQL
                                                                       +PHPCR)
```

## Eenmalige opzet

### 1. Testserver (VPS / managed — geen shared hosting)
- PHP 8.2/8.3-FPM + extensies (zie `HOSTING.md`), Composer 2, Node 18+.
- MySQL 8 / MariaDB 10.6+ (DB + gebruiker aangemaakt).
- PHPCR: **Jackrabbit** (Java) of **doctrine-dbal** (in MySQL) — keuze maken.
- nginx/Apache vhost naar `current/public` (zie `deploy/nginx-sulu.conf.sample`).
- SSH-gebruiker met schrijfrechten op het deploy-pad.
- Shared (blijven bij elke release): `.env.local`, `var/log`, `public/uploads`,
  `upload` (mediamap).

### 2. GitHub-secrets (repo → Settings → Secrets → Actions)
| Secret | Inhoud |
|---|---|
| `SSH_HOST` | host/IP testserver |
| `SSH_USER` | deploy-gebruiker |
| `SSH_KEY` | private deploy-sleutel (publieke op server in `authorized_keys`) |
| `DEPLOY_PATH` | bv. `/data/sites/.../testing` |

### 3. Server-`.env.local` (eenmalig, buiten git — zie `.env.test.dist`)
```
APP_ENV=prod
APP_SECRET=<genereer>
DATABASE_URL="mysql://user:pass@127.0.0.1:3306/acs_test?serverVersion=8.0"
# PHPCR: dbal (standaard) of jackrabbit
SULU_PHPCR_TRANSPORT=doctrinedbal
MAILER_DSN=smtp://...
```

## Wat de deploy doet (`deploy/remote-deploy.sh`)
1. `composer install --no-dev -o`
2. `npm ci && npm run build` (of vooraf gebouwde assets meeleveren)
3. `doctrine:migrations:migrate -n`
4. `doctrine:phpcr:init:dbal` (of Jackrabbit) + `sulu:document:initialize -n`
5. **Eenmalig**: content migreren — `tools/legacy-to-sqlite.php` is voor de
   sandbox; op de server draait `app:migrate` rechtstreeks tegen de in MySQL
   geladen legacy-dump (`--source-dsn='mysql://...'`).
6. `cache:clear && cache:warmup`, daarna admin-user aanmaken.

## Eerste vulling van de content op de test
- Laad de legacy-dump in een tijdelijke MySQL-DB op de server.
- `php bin/console app:migrate --source-dsn='mysql://user:pass@127.0.0.1:3306/acs_legacy'`
- Importeer de `upload`-map als Sulu-media (script volgt).

Zie `.github/workflows/deploy-test.yml` voor de pipeline.

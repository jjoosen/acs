# Hosting voor Sulu (ACS)

Sulu 2.6 draait **niet** op klassieke shared hosting. Nodig is een **VPS of
managed server** (Combell biedt beide). Hieronder de vereisten en wat ik van
jou nodig heb om de hosting + deploy in orde te brengen.

## Serververeisten

### Software
- **PHP 8.2 of 8.3** (FPM) met extensies:
  `ctype, iconv, intl, mbstring, pdo_mysql, gd` (of `imagick`), `zip, xml,
  fileinfo, exif, simplexml, tokenizer, curl, openssl`.
- **MySQL 8.0** of **MariaDB 10.6+**.
- **PHPCR-backend** — kies één:
  - **Jackrabbit** (aanbevolen voor performance): vereist **Java 11+** en een
    draaiende Jackrabbit-service. Beste keuze voor een productiesite met veel
    pagina's.
  - **doctrine-dbal** (PHPCR in MySQL): eenvoudiger (geen Java), iets trager;
    prima voor kleinere sites.
- **Webserver**: nginx (aanbevolen) of Apache, met rewrite naar `public/index.php`.
- **Composer 2** en **Node 18+/npm** (voor de asset-build bij deploy).
- **SSL** (Let’s Encrypt of eigen certificaat).
- Optioneel: **Supervisor/cron** (search-index, message-queue), **Redis** (cache).

### Capaciteit (richtlijn)
- Met Jackrabbit: **2 vCPU / 4 GB RAM** minimum, 4 vCPU/8 GB comfortabel.
- Zonder Jackrabbit (dbal): 2 vCPU / 2–4 GB RAM.
- Schijf: code + `var/` + **media (`upload/`-map)** — afhankelijk van de
  beeldbibliotheek (richt op enkele GB’s, met groeimarge).

### Structuur
- Aparte (sub)domeinen mogelijk voor frontend en admin (bv. `acs.be` +
  `admin.acs.be`), of admin onder `/admin` op hetzelfde domein.
- Schrijfrechten op `var/` en `public/uploads`; `.env.local`/secrets buiten git.

## Wat ik van jou nodig heb

1. **Hostingtype + toegang**
   - VPS of managed? Provider (Combell?) en pakket.
   - **SSH-toegang** (host, user, sleutel) en het **deploy-pad**.
   - Recht om PHP-versie/extensies en (eventueel) Java/Jackrabbit te installeren.
2. **Database**
   - MySQL-host, **DB-naam(en)**, gebruiker + wachtwoord (of laat mij aanmaken).
   - Keuze: **Jackrabbit** of **doctrine-dbal** voor PHPCR.
3. **Domein & DNS**
   - Definitief(e) domein(en) + toegang om DNS/SSL te regelen.
4. **Media**
   - De **`upload/`-map** van de huidige Combell-server (voor de foto's).
5. **Secrets uit de oude `.env`**
   - SMTP (formulier-mails), **Google Maps API-key**, **reCAPTCHA**-keys,
     Sentry-DSN, eventuele social/tracking-IDs.
6. **Go-live-afspraken**
   - Gewenste knip-over (datum), 301-redirects behouden, korte freeze van
     content tijdens de finale migratie-run.

## Deploy-aanpak (voorstel)
- Git-based deploy (Deployer/Capistrano) met `composer install --no-dev -o`,
  `npm ci && npm run build`, `doctrine:migrations:migrate`,
  `doctrine:phpcr:init:dbal` (of Jackrabbit), `app:migrate` (eenmalig),
  cache-warmup. Shared: `.env.local`, `var/log`, `public/uploads`, `upload`.
- Ik lever de deploy-config + `.env.prod`-sjabloon zodra het hostingtype bekend is.

# ACS → Sulu — Migratieplan

Status: levend document. Bijgewerkt 2026-06-25.
Zie ook `MIGRATION-BLUEPRINT.md` (technische analyse) en `HOSTING.md` (server).

## Doel
De bestaande ACS-site (legacy Innomedio BaseBundle-CMS) **1:1** overzetten naar
**Sulu 2.6** — identiek qua styling, content, teksten en foto's — met beheer via
de Sulu-admin en een onderhoudbaar, modern fundament.

## Bron & doel
- **Bron**: MySQL-dump `ID137795_prodacsac` + codebase (`Archief.zip`) + media-map
  `upload/` (van Combell, nog te bezorgen).
- **Doel**: Sulu 2.6, webspace `acs`, locale `nl`, Twig-frontend met de
  bestaande Bootstrap-theme (gecompileerde assets hergebruikt).

## Fasering

### Fase 0 — Fundament  ✅ KLAAR
- Sulu 2.6 geïnstalleerd (`setup.sh`), webspace + content-template, theme overgezet.
- Compat-Twig-laag (`setting/img/navigation/margin_bottom/...`).
- Site boot + rendert (HTTP 200); Sulu-admin werkt (`/admin`).

### Fase 1 — Content-migratie (pagina's + teksten)  ✅ KLAAR (basis)
- `tools/legacy-to-sqlite.php`: dump → SQLite (zonder MySQL).
- `app:migrate`: pagina-boom + SEO + URLs. **382 pagina's, 0 fouten.**
- Linkcontrole: **366/366 pagina's HTTP 200**, 392/393 interne links OK.

### Fase 2 — Bloktypes porten  🟡 BEZIG (13 / ~48)
- Klaar: text, banner, text_image, usps, image, quote, header, header_small,
  text_card, video, google_maps, code, text_menu.
- Te doen: **geneste/children-blokken** (cards*, faq, team, toggle_list,
  location_list(_circles), downloads, quote_slider, summary, picture_grid,
  images_link, history, text_anchor_menu, sidebar_block_text, …) — vergt
  nested-block-support in `content.xml` + recursieve mapping in `app:migrate`.

### Fase 3 — Media / foto's  ⛔ WACHT OP `upload`-MAP
- Map **`upload/`** (Combell shared folder) importeren als Sulu-media.
- Koppeling via `file.tag='page-block'` + `file.tag_id = page_block.id`.
- `img()` schakelt automatisch over op Sulu media-formaten.

### Fase 4 — Navigatie & instellingen
- Hoofdmenu + footer-navigaties (legacy `navigation`-tabel) → Sulu
  navigation-contexts (`main`, `footer`).
- `setting()` → Sulu settings (snippet/DB): site_name, scripts, social, etc.

### Fase 5 — Speciale content-types
- Nieuws, vacatures (jobs) en vestigingen (locaties): overzicht + detail,
  smart-content. MVP nu: gewone pages (al gemigreerd).

### Fase 6 — Links & redirects
- **Absolute oude-domein-links** in content (`https://www.acs.be/…`,
  `https://www.acsac.eu/…`) automatisch herschrijven naar interne links.
- Legacy `redirect`-tabel → Sulu redirect-bundle (301’s behouden → SEO).
- Inactieve doelpagina's: 404 blijft 404 (zoals nu), tenzij anders gewenst.

### Fase 7 — Formulieren
- Contact-/nieuwsbrief-/job-formulieren → Sulu Form-bundle + mail-afhandeling.

### Fase 8 — Assets & build
- Webpack-encore build van de theme (`assets/theme`) → `public/build`.

### Fase 9 — Hosting & go-live  (zie HOSTING.md)
- Server (PHP+MySQL+Jackrabbit), deploy, DNS, SSL, definitieve migratie-run,
  eindverificatie tegen de live site, knip-over.

## Reeds gevonden aandachtspunten (uit linkcontrole)
- 1 interne link naar een **inactieve** pagina (Acs Connect) → 404 (correct).
- ±10+ absolute links naar oude domeinen in de content → herschrijven (fase 6).

## Afhankelijkheden van de klant
1. **`upload/`-mediamap** van Combell (voor de foto's).
2. **Hosting-toegang** (zie HOSTING.md).
3. Bevestiging mapping nieuws/jobs/locaties (pages vs custom content-type).
4. API-keys/secrets uit de oude `.env` (Google Maps, reCAPTCHA, SMTP, Sentry).

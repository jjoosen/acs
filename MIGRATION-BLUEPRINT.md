# ACS: Innomedio BaseBundle CMS → Sulu migratie — blueprint

## Bron
- Legacy CMS: Symfony app op `Innomedio\Symfony\Bundle\BaseBundle` (eigen CMS-framework van Innomedio).
- DB dump: `ID137795_prodacsac` (MySQL 5.7, latin1->utf8mb4). Content klein; 631MB is 99% logging.
- Codebase zip: `src/` (project-controllers/twig), `config/`, `templates/frontend/` (de theme, 48 content-blocks), `assets/` (scss/js/images/fonts).
- Taal: enkel **nl** (1 taal).

## Kern-datamodel (bron)
- `page` (415): nested tree (lft/rgt/lvl, tree_root, parent_id), template_name, content_type_tag (news/jobs/locations/...), fields (PHP serialize), active, in_sitemap, homepage, controller, published, date.
- `page_translation`: language_id(nl), name, url (slug-segment), full_slug, meta_title, meta_description, in_active.
- `page_block` (2517): page_id, parent_id (nesting), template_block_id, editable_area, tag (bloktype), custom_name, fields (PHP serialize), active, sort_order.
- `navigation` + `navigation_translation`: nav-boom per tag (bv. main/footer), custom_url.
- `redirect` + `redirect_hit`: oude->nieuwe url.
- `form` + `form_field` (+ translations) + `form_log`: formulieren.
- `file` + `image` (+ translations): MEDIA METADATA enkel (filename, tag, tag_id). Echte bestanden op Combell-schijf (nog aanleveren).
- `tag`, `template`, `setting`, `translation` (UI-vertalingen), `backend_user*` (CMS-logins, niet migreren -> Sulu eigen users).

## Doel (Sulu 2.6)
- Twig-templating (geen headless): front-end Bootstrap-theme hergebruiken, output identiek.
- Elke `page` -> Sulu PHPCR page document onder webspace `acs`.
- Elk bloktype -> Sulu block in template XML; Twig vrijwel 1:1 (block.field('x') -> block.x ; assets -> Sulu media).
- content_type_tag news/jobs/locations -> Sulu pages of (beter) eigen content + smart content. MVP: gewone pages.
- navigation -> Sulu navigation contexts (main/footer) op page documents.
- redirects -> Sulu redirect-route bundle (of nginx map).
- forms -> Sulu Form bundle.

## Block veld-inventaris (bron -> Sulu property)
(zie onder; types: text->text_line, title->text_line, text(rich)->text_editor, *_size->single_select, bool->checkbox, image->media_selection single, images->media_selection multi, download->media_selection, link->... )

banner: height,no_bottom_margin,text,text_size,title | img:image
cards-list-four: button_link,button_title,button_type,text,title | img:image (children: card)
cards-list: link,text,title | img:image (children)
cards-with-button: link,link_text,text,text_small,title | img:image, download
cards-with-icon: link,link_text,text,title | icon
code: code
colors: cmyk,hex,hsl,light,rgb,title
contact-form-location: email,phone,postal_city,street_number | image
contact-form: email,phone,postal_city,street_number,text,title,title_size
downloads: text,title | download,image (children)
faq: answer (children: q/a)
google-maps: iframe
header-search: text,title | image
header-small: title | image
header: link,text,title,youtube_id | image,video
history: text,title,title_size (children: timeline item)
image: compact | image
images-link: title (children: image+link)
job-detail: description,employment_type,experience,kind,postal_city,short,street_number,text,title,title_size | image
job-form: text,title,title_size
job-news-detail: author,text,title | image
job-news-list: external_link,short | image
jobs-highlight: text,title | image
jobs-list: contact_email,contact_name,contact_phone,description,text,title,title_2
location-header: postal_city,street_number,text,youtube_id | image,video
location-list-circles: email,phone,postal_city,street_number,text,title | image (children)
location-list: email,phone,postal_city,street_number,text,title | image (children)
news-category: category,external_link,short,title | image
news-detail: author,jobtitle,lastModified,text,title | image
news-list: external_link,short | image
news-recent-same-category: external_link,short,title | image
news-recent: external_link,short,text,title | image
newsletter-form: text,title,title_size
picture-grid: title,title_size (children: image)
quote-slider: (children: quote)
quote: center,text,text_small
sidebar-block-text: email,phone,subtitle,text,title | image
summary: margin_bottom,text,title
team: email,function,phone,text,title | image (children: member)
text-anchor-menu: text,title | image
text-images: img_left,img_small,scroll_reveal,text,title,title_size | image (multi)
text-menu: text,title
text: center,element,element_position,element_rotation,text,title,title_size
text_card: text,title,title_size | image
toggle-list: no_bottom_margin,text,title (children: toggle)
usps: bg_color,text,title | image (children: usp)
video: no_breadcrumbs,text,title,title_size,youtube_id | image,video

## Migratiestappen (command app:migrate)
1. languages/webspace setup (nl).
2. pages: walk tree by lft; create Sulu PagDocument; map template_name->Sulu template key; set title/url from page_translation; published/active->workflowStage; meta.
3. blocks: for each page, order by sort_order, group nested (parent_id); unserialize fields; map to Sulu block property values; resolve asset refs (image/file tag_id) -> Sulu media id (after media import).
4. navigation: set navigationContexts on documents; custom nav items.
5. redirects: write to redirect bundle.
6. forms: recreate via Form bundle.

## Openstaand / nodig van klant
- Echte media-bestanden (uploads-map van Combell) voor `file`/`image` import.
- Hosting target (Sulu vereist PHP+MySQL+Jackrabbit/doctrine-dbal PHPCR; shared hosting volstaat meestal niet -> VPS/managed).
- Bevestiging mapping news/jobs/locations (pages vs custom content type).

---

## Status na fundament-scaffold (sessie 2026-06-25)

Uitgevoerd in deze sessie (`bash setup.sh` + roadmap-aanzet):

- **Sulu 2.6 geïnstalleerd** (`composer create-project sulu/skeleton:^2.6`), config/templates/command-overlay erover, theme uit `Archief.zip` overgezet (48 legacy `content_blocks`, layout, macros, `assets/theme`).
- **Dev-omgeving**: `.env.local` met SQLite (`var/data.db`); DB-schema + PHPCR (jackalope-doctrine-dbal op SQLite) geïnitialiseerd; `sulu:document:initialize` → homepage-document `/cmf/acs/contents` (nl) aangemaakt.
- **DI-fix**: alias `Sulu\Component\DocumentManager\DocumentManagerInterface` → `@sulu_document_manager.document_manager` in `config/services.yaml` (anders compileert de container niet door `App\Command\MigrateCommand`).
- **Site rendert (HTTP 200)** via de overgezette theme: `frontend/base.html.twig` → header/footer/main; titel, CSS/JS-links aanwezig.
- **Compat-Twig-laag** (`src/Twig/LegacyCompatExtension.php`): `setting()`, `img()`, `navigation()`, `view_file_link()`, `margin_bottom()`, `count_jobs()`, `latest_jobs()`. De vendor-include `@InnomedioBase/frontend/head.html.twig` is vervangen door `templates/frontend/layout/_head.html.twig`. `transparentNav` krijgt een default in de base.
- **6 bloktypes geport** naar Sulu-dispatch (`templates/blocks/`): text, banner, image, quote, usps, text_image — passend bij de blokdefinities in `config/templates/pages/content.xml`.

### Belangrijke randvoorwaarden in deze sandbox
- **Geen MySQL/mysqld** beschikbaar → `app:migrate` (vereist de legacy-dump in MySQL via `--source-dsn`) kon hier niet uitgevoerd/getest worden. Het commando is een werkend skelet; pagina-boom + basis-mapping staan, per-blok mapping + media volgen.
- **Website-assets** (`public/build/css/app.css`, `app.js`) bestaan nog niet → vereisen een webpack-encore build van `assets/theme`. De pagina rendert zonder (404 op die assets is niet-fataal). `/public/build/` is gitignored (regenereerbaar).

### Resterende roadmap (volgorde)
1. **42 resterende bloktypes** porten (XML in `content.xml` + `templates/blocks/*.twig`) volgens het patroon van de 6 — incl. geneste/children-blokken (cards, faq, team, usps-items, …).
2. **Navigatie & settings** echt aansluiten: `navigation()` → Sulu navigation-contexts (main/footer); `setting()` → settings-snippet/DB.
3. **Media-pipeline**: `img(filename,…)` → Sulu media-formaten; media-import uit `file`/`image` (echte uploadbestanden van Combell nodig).
4. **Content-types** voor nieuws/jobs/locaties (overzicht + detail) — MVP: gewone pages.
5. **`app:migrate`** afmaken (per-blok veld-mapping + media-koppeling) en draaien tegen de in MySQL geladen dump.
6. **Redirects** → Sulu redirect-bundle; **formulieren** → Sulu Form-bundle.
7. **Webpack-build** van de theme-assets; eindverificatie tegen de live site; productie op **MySQL + Jackrabbit** (VPS/managed, geen shared hosting).

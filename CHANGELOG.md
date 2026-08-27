# Changelog — Neura WooCommerce Sync

## [1.16.1] — 2026-08-27

Consolidatierelease: dit distributierepo en de kopie in saas-frontend waren uiteengelopen. Deze release bevat alle wijzigingen t/m saas-frontend v1.16.0 én de WOWT-fix die alleen hier zat. (Nummering: dist-releases 1.14.1 en 1.14.2 kwamen inhoudelijk overeen met resp. 1.15.2 en de WOWT-fix hieronder.)

### Fixed
- **`/order-statuses` registreerde met methods `"WOWT"`** — `WP_REST_Server::READABLE | WP_REST_Server::CREATABLE` zijn strings ('GET', 'POST'); PHP's bitwise `|` maakt daar "WOWT" van, waardoor élke GET/POST op `/order-statuses` `rest_no_route` gaf (gemeten op nomadfire.shop). Neura kon daardoor de custom statussenlijst nooit pushen en elke per-order statuspush faalde met `unknown_status`. Nu een array `['GET','POST']`.

## [1.16.0] — 2026-07-29

### Added
- **`POST /orders/{id}/complete` bypass-endpoint** — voltooit een order rechtstreeks via de plugin (HPOS-compatibel), voor fulfillment-flows waar de reguliere statuspush niet volstaat.
- **`meta.features` capability-advertentie** — de plugin adverteert nu welke features hij ondersteunt, zodat Neura per shop weet welke endpoints beschikbaar zijn.
- **`connectionId` op de shipment-webhook** — zodat Neura bij meerdere WooCommerce-koppelingen in één werkruimte de shipment aan de juiste winkel koppelt.

## [1.15.8] — 2026-07-28

### Fixed
- **Omschrijvingen behouden HTML-structuur bij import** — `/terms` endpoint en de product-categorieën-handler gebruikten `wp_strip_all_tags()` om omschrijvingen te saniteren. Dit verwijdert tags zonder spaties in te voegen, waardoor tabellen en FAQ-teksten samenvloeien ("MaatPersonenBeste voorSmall", "kamado?Significant"). Vervangen door `wp_kses_post()` dat veilige opmaak (alinea's, koppen, lijsten, tabellen) behoudt en alleen gevaarlijke tags verwijdert. Na her-import zijn rijke omschrijvingen volledig correct overgenomen.

## [1.15.7] — 2026-07-07

### Fixed
- **Cursus-reviews werden nóg steeds niet gevonden (0)** — vervolg op 1.15.6. WordPress' `WP_Comment_Query` normaliseert élke ingebouwde status: `status='approve'` → `comment_approved='1'`, en `status='all'` → `comment_approved IN ('0','1')`. Tutor slaat de status op als de STRING `'approved'` → beide sluiten hem uit. Nu een **directe `$wpdb`-query** (zoals Tutor zelf doet) met `comment_approved IN ('approved','1')` — geen WP-status-normalisatie meer. Geverifieerd tegen WordPress core (`class-wp-comment-query.php`).

## [1.15.6] — 2026-07-07

### Fixed
- **Cursus-reviews werden niet geëxporteerd (0 gevonden)** — Tutor slaat de goedkeur-status van een course-rating op als de STRING `comment_approved = 'approved'`, niet als `'1'`. De export gebruikte `get_comments( status => 'approve' )`, wat WordPress vertaalt naar `comment_approved = '1'` → 0 resultaten, óók op cursussen met tientallen reviews. Nu wordt `status = all` opgehaald en filteren we zelf op `'approved'`/`'1'` (spam/trash/hold vallen af). Verifieerd tegen de Tutor-bron (`themeum/tutor` `classes/Reviews.php`: `comment_type = tutor_course_rating`, `comment_agent = TutorLMSPlugin`, meta `tutor_rating`).

## [1.15.5] — 2026-07-07

### Added
- **Cursus-reviews in de course-export** — `get_courses` geeft nu per cursus een `reviews`-array mee: Tutor-course-ratings (WP-comments `comment_type = tutor_course_rating` op de cursus, score uit comment-meta `tutor_rating`, alleen goedgekeurd). Voedt de nieuwe Neura `ReviewCourse`-import → reviews-module + de reviews-drawer/featured-carousel op de storefront-cursuspagina.

## [1.15.4] — 2026-07-06

### Added / Fixed
- **Quiz-export: échte "meerdere antwoorden mogelijk"-vlag** — `get_quiz_payload` las alleen `question_type`, maar Tutor slaat élke keuzevraag op als `multiple_choice`; of er meerdere antwoorden juist mogen zijn staat in `question_settings.has_multiple_correct_answer` (de "Meervoudig juist antwoord"-toggle). Deze wordt nu per vraag meegegeven als `multipleCorrect`, plus `answerRequired`, `randomize` en `explanation` (antwoord-uitleg). Voorheen leidde Neura enkel/meervoudig af uit het type → toonde "meerdere antwoorden mogelijk" bij ELKE vraag.
- **Quiz-brede instellingen meegenomen** — `settings{attemptsAllowed, timeLimitValue/Unit, feedbackMode, questionsOrder, maxQuestionsToAnswer}` uit `tutor_quiz_option` (nog niet afgedwongen in de speler; meegenomen zodat latere uitbreiding geen her-import vraagt).

## [1.15.3] — 2026-07-06

### Fixed
- **Kritieke bug: bijna alle lesinhoud ontbrak bij courses-export** — `get_courses` haalde lessen op met `post_parent = cursus-ID`, maar Tutor LMS hangt lessen aan hun **topic** (sectie), niet rechtstreeks aan de cursus. Resultaat: van elke cursus met secties (de norm) kwamen alléén de quizvragen mee; alle echte lessen (tekst/video) werden stil overgeslagen. Nu: topics eerst ophalen, lessen via `post_parent__in` op de topic-ID's (+ fallback op de cursus-ID zelf voor oude cursussen zonder secties).
- **Cursus-instellingen (duur/niveau/vereisten/uitkomsten) kwamen vaak leeg mee** — Tutor slaat deze soms op als losse meta-key, maar vaak alléén genest in `_tutor_course_settings` (en `benefits`/`requirements` soms als array, soms als newline-tekst). Beide vormen worden nu geprobeerd.
- **Prijs stond op 0 bij WooCommerce-gekoppelde cursussen** — als de losse Tutor-prijsmeta leeg is en de cursus niet expliciet op "gratis" staat, wordt de prijs nu opgehaald van het gekoppelde WooCommerce-product.
- **Niveau "Expert" kwam leeg over in Neura** — Tutor gebruikt `expert`, Neura's editor verwacht `advanced`; vertaling zit nu in de app-import (`wp-import.ts`).

### Added
- **Opdrachten (`tutor_assignments`) worden nu ook meegenomen** — als de Assignments-addon actief is, komen opdrachten mee als tekstles (was eerder volledig genegeerd).

## [1.15.2] — 2026-07-06

### Added
- **Tutor LMS course-export verrijkt** — `/wp-json/neuramerce/v1/courses`: per-les `videoUrl` (uit `_video`), gratis-preview (`_is_preview`), quizvragen+antwoorden (`tutor_quiz`-posts + `tutor_quiz_questions`/`_answers`-tabellen), inschrijvingen (`tutor_enrolled`), en course `requirements`/`outcomes`/`language`. Nieuwe **Cursussen-admintab** (telling + endpoint + API-key-status).

## [1.15.1] — 2026-07-03

### Fixed
- **Order-push koppelt nu aan de juiste winkel (multi-shop fix)** — de real-time order-push stuurde de workspace-brede sleutel (`nwws_push_key`) mee, waarmee Neuramerce bij meerdere WooCommerce-koppelingen in één werkruimte (bv. Nomad + Qandyshop) niet kon bepalen uit wélke winkel een bestelling kwam → de order werd een "wees" (of belandde op de verkeerde winkel-tab). De order-push gebruikt nu de koppeling-specifieke sleutel (`nwws_api_key` = `deriveConnectionSecret(connectionId)`), zodat Neuramerce de bestelling meteen aan de juiste winkel toewijst. Andere sync-endpoints (producten/conversies) blijven ongewijzigd op `nwws_push_key`; legacy-installaties vallen automatisch terug (geen regressie).

## [1.15.0] — 2026-06-30

### Added
- **Cross-device attributie voor ingelogde klanten** — als een bezoeker ingelogd is, koppelt de plugin z'n e-mail nu vroeg via `NauraTrack.identify()` (in de `<head>`, na track.js). Daardoor dragen óók pre-purchase touch-events (ad-landings) de e-mail, waarmee Neura touchpoints van hetzelfde persoon over verschillende apparaten kan samenvoegen. De server hasht de e-mail (SHA-256) en bewaart alleen de hash; consent-gating zit in track.js (identify zet alleen lokaal, events versturen pas na toestemming). Vult aan op de al bestaande e-mail-meegave bij het `purchase`-event op de bedankt-pagina.

## [1.14.0] — 2026-06-24

### Added
- **Auto-configuratie van de order/voorraad-sync** — na het koppelen haalt de plugin via `workspace-config` nu ook de push-URL, push-key en workspace-ID op en slaat die op (`nwws_push_url` / `nwws_push_key` / `nwws_workspace_id`). De 60s-sync-cron én real-time push werken daardoor direct na koppelen, zónder dat de Webhook-velden handmatig gekopieerd hoeven te worden.

### Fixed
- **Eerlijke sync-statusmelding (geen valse succesmelding)** — "Sync Alle Orders/Producten" meldde altijd succes, óók als er door ontbrekende push-configuratie niets verstuurd werd. Nu: een duidelijke foutmelding als de sync nog niet geconfigureerd is, en anders "X verstuurd naar Neuramerce — verwerking volgt" i.p.v. een misleidend "gesynchroniseerd".

## [1.11.1] — 2026-05-23

### Fixed
- **`/api/woocommerce/workspace-config` callt nu `/api/v1/woocommerce/workspace-config`** (ADR-002 Fase 1 alignment). 3 plekken in `neura-wp-woo-sync.php` (regels 623, 776, 1763) — config-fetch tijdens settings-save, `do_fetch_workspace_config()` AJAX-handler en auto-refetch via transient. Pre-fix werkte via 308-redirect-stub op Neura backend; post-fix is directe v1-call (geen extra round-trip). Veronderstelt `nwws_api_url` eindigt op `/api` (zonder `/v1`) — default sinds installatie. Sites die zelf `nwws_api_url` op `.../api/v1` hebben gezet krijgen `.../api/v1/v1/woocommerce/workspace-config` (double `/v1/`); workaround: reset URL naar `.../api`

## [1.11.0] — 2026-05-05

### Added
- **`wc-partially-shipped` order-status (F4)** — voor multi-warehouse split-orders waarbij sommige (niet alle) regels al verzonden zijn. Geregistreerd via `register_post_status` + verschijnt naast `wc-completed` in admin-dropdown. Telt als 'paid' status + meegenomen in WC reports. Neura's Postgres-trigger op `order_shipments` hercomputeert dit automatisch op basis van shipment-aggregaat (pending → partially_shipped → shipped → delivered)
- **`GET /wp-json/neuramerce/v1/order-statuses` live-fetch endpoint (S5)** — returnt actieve `wc-<key>` post_statuses zoals WC ze daadwerkelijk kent via `get_post_stati()` + `wc_get_order_statuses()`. Gebruikt door Neura's `safeguard_status_mapping_consistency` om drift te detecteren tussen Neura's database, WP-option en WC's runtime-registry. Response bevat `registered_post_statuses` + `wc_order_statuses` + `stored_in_option` + `plugin_version` + `fetched_at`
- **Dubbele-mail-fix (A1+A2)** — `maybe_suppress_default_email` filter onderdrukt WC's eigen klant-mail voor custom statussen waar Neura via z'n inbox-template-engine zelf mailt (`gesnoozed`, `partially-shipped`). Voorkomt dat klant 2× mail krijgt voor zelfde event. Site-eigenaars kunnen extra statussen toevoegen via `apply_filters('nwws_neura_handled_email_statuses', $statuses)`

### Compatibility
- Backwards-compat met v1.10.0: alle nieuwe features additief, geen bestaande hooks/filters gewijzigd. Auto-update via GitHub-updater (Neura_GitHub_Updater) verspreidt naar alle gekoppelde shops binnen 24-48u na release

## [1.10.0] — 2026-04-27

### Added
- **Dynamische verzenddag op productpagina** — `POST /wp-json/neuramerce/v1/shipping-schedules` slaat default weekschema + categorie-overrides + datum-excepties op als WP-options. Hook `woocommerce_single_product_summary` (priority 25) toont onder de prijs een tweede regel: "Vóór 16:00 besteld? Dinsdag verzonden.", berekend met de PHP-helper `nwws_calculate_next_ship_day` (max 14 dagen forward-loop, exact dezelfde logica als de TS-versie in saas-frontend voor presence-sites)
- Categorie-overrides matchen via `product_cat` term-slug (Neura kent geen WC term-IDs)
- Datum-excepties: `no_shipping` (feestdag) en `shipping_day` (extra-verzenddag) doorbreken het weekschema

## [1.9.0] — 2026-04-27

### Added
- **Custom orderstatussen pushed vanuit Neura**: `POST /wp-json/neuramerce/v1/order-statuses` slaat de hele set custom statussen op als `nwws_custom_order_statuses` option en registreert ze dynamisch via `register_post_status` + `wc_order_statuses` filter
- **Voorraadstatus-tekst (Custom Stock Status Pro vervanger)**: `POST /wp-json/neuramerce/v1/stock-texts` zet per-product (`_nwws_stock_status_text` post-meta) of per-categorie (`_nwws_stock_status_text` term-meta) tekst. `woocommerce_get_availability` filter rendert die tekst op productpagina's met fallback override → product → categorie → standaard
- **Order-level status push**: `POST /wp-json/neuramerce/v1/orders/{id}/status` zet de custom status + leverdatum (`_nwws_planned_delivery_at` meta) op een specifieke order via HPOS-compatible `wc_get_order()` + `$order->set_status()`

## [1.7.5] — 2026-04-27

### Added
- **Custom WooCommerce order status `wc-naar-warehouse` (display: "Naar warehouse")**: Neuramerce zet deze status op een order zodra hij naar GP/Warehouse Online wordt doorgestuurd. Hierdoor blijft `processing` (Verwerken) gereserveerd voor orders die nog handmatig opgepakt moeten worden — fulfillment-flow direct zichtbaar in Woo admin
- Status verschijnt naast `Verwerken` in de orderstatus-dropdown, telt mee in WC reports, en gedraagt zich als een 'paid' status (refund-flow + stock-decrement werken correct)

## [1.4.5] — 2026-04-11

### Fixed
- **PHP fatal on `/products`**: `get_product_addons()` returned `null` instead of `[]`, causing a crash on sites with product add-on plugins
- **PHP fatal on `/shipping`**: `array_map` on zone settings that could be a non-array string value (invalid string offset)
- **WPML deadlock on `/status`**: `apply_filters('wpml_active_languages')` triggered loopback REST requests inside an active REST dispatch, causing PHP-FPM workers to deadlock. Fixed by removing the filter before calling it
- **WPML infinite loop on `/menus`**: `WPML_LS_Render::wp_get_nav_menu_items_filter` injected language-switcher items by recursively calling `wp_get_nav_menu_items()`, causing an infinite loop. Fixed by saving and removing `wp_get_nav_menu_items` filters around menu fetching, and passing `suppress_filters => true` to prevent WPML query interception
- **PHP fatal on `/nwws/v1/stats`, `/nwws/v1/products`, `/nwws/v1/orders`** when WooCommerce is not active — all three endpoints now return `400 WooCommerce niet actief` gracefully
- **CDN cache bypass**: Kinsta's edge cache ignored `Cache-Control: no-store` and served authenticated 200 responses for unauthenticated requests. Fixed by adding `Vary: *` and `Surrogate-Control: no-store` headers to all `/neuramerce/v1/` and `/nwws/v1/` responses

## [1.3.3] — 2026-03-21

### Added
- Tracked previously unversioned server-side files in git:
  - `includes/class-content-cleaner.php` — HTML sanitisation for migration
  - `includes/class-elementor-parser.php` — Elementor blocks → plain HTML
  - `includes/class-gutenberg-parser.php` — Gutenberg blocks → plain HTML
  - `templates/migration-tab.php` — Admin migration tab template

## [1.3.2] — previous

- Stable release with Migrator REST API, GitHub auto-updater, WooCommerce sync

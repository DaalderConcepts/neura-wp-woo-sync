# Changelog — Neura WooCommerce Sync

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

<?php
declare(strict_types=1);
/**
 * Plugin Name:  Neura WooCommerce Sync
 * Plugin URI:   https://github.com/DaalderConcepts/neura-wp-woo-sync
 * Description:  Synchroniseert WooCommerce data (producten, orders, klanten, COGS) met Neuramerce voor accurate ROAS tracking en conversie-optimalisatie.
 * Version:      1.12.0
 * Author:       Daalder Concepts
 * Author URI:   https://daalderconcepts.com
 * Text Domain:  neura-wp-woo-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * Update URI:   https://github.com/DaalderConcepts/neura-wp-woo-sync
 */

defined('ABSPATH') || exit;

define('NWWS_VERSION',    '1.12.0');
define('NWWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NWWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NWWS_PLUGIN_FILE', __FILE__);
define('NWWS_GITHUB_OWNER', 'DaalderConcepts');
define('NWWS_GITHUB_REPO',  'neura-wp-woo-sync');

// Neuramerce Migrator — always loaded, works without WooCommerce
require_once NWWS_PLUGIN_DIR . 'includes/class-migrator-auth.php';
require_once NWWS_PLUGIN_DIR . 'includes/class-content-cleaner.php';
require_once NWWS_PLUGIN_DIR . 'includes/class-elementor-parser.php';
require_once NWWS_PLUGIN_DIR . 'includes/class-gutenberg-parser.php';
require_once NWWS_PLUGIN_DIR . 'includes/class-migrator-api.php';

// Check if WooCommerce is active (per-site or network-wide for multisite)
$nwws_wc_active = in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))
    || array_key_exists('woocommerce/woocommerce.php', (array) get_site_option('active_sitewide_plugins', []));

if ( ! $nwws_wc_active ) {
    // Show admin notice — WC sync disabled, but Migrator API and full settings page remain available
    add_action('admin_notices', function () {
        echo '<div class="notice notice-warning"><p><strong>Neura WooCommerce Sync</strong>: WooCommerce niet gevonden — WooCommerce sync is uitgeschakeld. De Migrator API is wel beschikbaar.</p></div>';
    });
    // Do NOT return — let the main class boot so the full settings page (chat, API key, etc.) is accessible
}

// Declare HPOS (Custom Order Tables) compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', NWWS_PLUGIN_FILE, true);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// GitHub Auto-Updater
// Checks /releases/latest on GitHub and injects into WP update transient.
// ─────────────────────────────────────────────────────────────────────────────
class Neura_GitHub_Updater {

    private string $plugin_slug;
    private string $plugin_file;
    private string $version;
    private string $owner;
    private string $repo;

    public function __construct(string $plugin_file, string $version, string $owner, string $repo) {
        $this->plugin_file  = $plugin_file;
        $this->plugin_slug  = plugin_basename($plugin_file);
        $this->version      = $version;
        $this->owner        = $owner;
        $this->repo         = $repo;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api',                           [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_post_install',                 [$this, 'after_install'], 10, 3);
    }

    private function get_release(): ?array {
        $cache_key      = 'nwws_github_release';
        $fail_cache_key = 'nwws_github_release_fail';

        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        // Don't retry within 30 min after a failed call (prevents blocking on every admin page)
        if (get_transient($fail_cache_key)) return null;

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases/latest",
            ['headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version')], 'timeout' => 5]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($fail_cache_key, 1, 30 * MINUTE_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['tag_name'])) {
            set_transient($fail_cache_key, 1, 30 * MINUTE_IN_SECONDS);
            return null;
        }

        // Prefer a release asset ZIP (correct folder structure) over the raw zipball
        $zip_url = $data['zipball_url'];
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (str_ends_with($asset['name'], '.zip')) {
                    $zip_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = [
            'version'     => ltrim($data['tag_name'], 'v'),
            'zip_url'     => $zip_url,
            'body'        => $data['body'] ?? '',
            'published'   => $data['published_at'] ?? '',
        ];

        set_transient($cache_key, $release, 12 * HOUR_IN_SECONDS);
        return $release;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) return $transient;

        $release = $this->get_release();
        if (!$release) return $transient;

        if (version_compare($release['version'], $this->version, '>')) {
            $transient->response[$this->plugin_slug] = (object) [
                'slug'        => dirname($this->plugin_slug),
                'plugin'      => $this->plugin_slug,
                'new_version' => $release['version'],
                'url'         => "https://github.com/{$this->owner}/{$this->repo}",
                'package'     => $release['zip_url'],
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) return $result;

        $release = $this->get_release();
        if (!$release) return $result;

        return (object) [
            'name'          => 'Neura WooCommerce Sync',
            'slug'          => dirname($this->plugin_slug),
            'version'       => $release['version'],
            'author'        => '<a href="https://neuramerce.com">Neuramerce</a>',
            'homepage'      => "https://github.com/{$this->owner}/{$this->repo}",
            'download_link' => $release['zip_url'],
            'sections'      => ['changelog' => nl2br($release['body'])],
            'last_updated'  => $release['published'],
        ];
    }

    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $response;
        }

        // Release asset ZIPs unpack to neura-wp-woo-sync/ directly — ensure correct destination
        global $wp_filesystem;
        $target = WP_PLUGIN_DIR . '/neura-wp-woo-sync';
        if ($result['destination'] !== $target) {
            $wp_filesystem->move($result['destination'], $target, true);
            $result['destination'] = $target;
        }

        activate_plugin('neura-wp-woo-sync/neura-wp-woo-sync.php');
        return $result;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Main Plugin Class
// ─────────────────────────────────────────────────────────────────────────────
class Neura_WooCommerce_Sync {

    private static ?self $instance = null;

    public static function instance(): self {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('init',                         [$this, 'init']);
        add_action('admin_menu',                   [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts',        [$this, 'enqueue_admin_assets']);

        // WooCommerce hooks
        add_action('woocommerce_new_order',           [$this, 'sync_new_order'],        10, 1);
        add_action('woocommerce_update_order',        [$this, 'sync_order_update'],     10, 1);
        add_action('woocommerce_order_status_changed',[$this, 'sync_order_status_change'], 10, 4);
        add_action('woocommerce_new_product',         [$this, 'sync_new_product'],      10, 1);
        add_action('woocommerce_update_product',      [$this, 'sync_product_update'],   10, 1);

        // Custom WooCommerce order status: "Naar warehouse" — gezet door Neuramerce
        // wanneer een order naar een warehouse (GP/WO) is doorgestuurd. Hierdoor
        // ziet de webshop-eigenaar in de Woo admin direct dat een order in
        // fulfillment-flow zit, en blijft 'Verwerken' gereserveerd voor orders
        // die nog door de operator opgepakt moeten worden.
        add_action('init',                                       [$this, 'register_naar_warehouse_status']);
        add_filter('wc_order_statuses',                          [$this, 'add_naar_warehouse_to_order_statuses']);
        add_filter('woocommerce_order_is_paid_statuses',         [$this, 'mark_naar_warehouse_as_paid']);
        add_filter('woocommerce_reports_order_statuses',         [$this, 'mark_naar_warehouse_for_reports']);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', [$this, 'mark_naar_warehouse_payment_complete']);

        // v1.11.0 — Multi-warehouse split-shipments krijgen 'wc-partially-shipped'
        // status zodra Neura's Postgres-trigger detecteert dat sommige (niet
        // alle) regels al verzonden zijn. Geeft klant + WC-admin duidelijk
        // beeld bij split-orders Eindhoven/Venlo etc.
        add_action('init',                                                  [$this, 'register_partially_shipped_status']);
        add_filter('wc_order_statuses',                                     [$this, 'add_partially_shipped_to_order_statuses']);
        add_filter('woocommerce_order_is_paid_statuses',                    [$this, 'mark_partially_shipped_as_paid']);
        add_filter('woocommerce_reports_order_statuses',                    [$this, 'mark_partially_shipped_for_reports']);
        add_filter('woocommerce_valid_order_statuses_for_payment_complete', [$this, 'mark_partially_shipped_payment_complete']);
        // HPOS-compat (review-fix): WC 8.x slaat order-status op in wc_orders.status
        // tabel ipv post_status. Zonder deze filter valt de status uit de admin-
        // dropdown wanneer custom_orders_table_usage_is_enabled() true is.
        add_filter('woocommerce_register_shop_order_post_statuses',         [$this, 'add_partially_shipped_to_post_statuses']);

        // v1.11.0 — Voorkom dubbele klant-mails: WC's eigen mailer onderdrukken
        // voor custom statussen waar Neura zelf via inbox-template-engine mailt
        // (snooze, partially_shipped). Klant krijgt anders WC-default mail +
        // Neura-mail voor zelfde event.
        add_filter('woocommerce_email_enabled_customer_on_hold_order',  [$this, 'maybe_suppress_default_email'], 10, 3);
        add_filter('woocommerce_email_enabled_customer_processing_order', [$this, 'maybe_suppress_default_email'], 10, 3);
        add_filter('woocommerce_email_enabled_customer_completed_order',  [$this, 'maybe_suppress_default_email'], 10, 3);

        // v1.3.0 — Custom orderstatussen + voorraadtekst (Neura beheert de definities,
        // pusht naar WP-option `nwws_custom_order_statuses`. Hieronder de WC-integraties
        // die ervoor zorgen dat WC die statussen kent en stock-tekst toont op productpagina.)
        add_action('init',                               [$this, 'nwws_register_custom_statuses'], 11);
        add_filter('wc_order_statuses',                  [$this, 'nwws_add_custom_statuses_to_dropdown']);
        add_filter('woocommerce_order_is_paid_statuses', [$this, 'nwws_mark_custom_paid']);
        add_filter('woocommerce_reports_order_statuses', [$this, 'nwws_include_custom_in_reports']);
        // Voorraadstatus-tekst override (per-product > per-categorie > WC default).
        add_filter('woocommerce_get_availability',       [$this, 'nwws_filter_availability'], 10, 2);
        // Dynamisch verzendregeltje op productpagina ("Vóór 16:00 besteld? Dinsdag verzonden.").
        // Priority 25 = na price (10), voor add-to-cart (30).
        add_action('woocommerce_single_product_summary', [$this, 'nwws_render_shipping_info'], 25);

        // COGS custom fields
        add_action('woocommerce_product_options_pricing',  [$this, 'add_cogs_field']);
        add_action('woocommerce_process_product_meta',     [$this, 'save_cogs_field']);
        add_action('woocommerce_variation_options_pricing',[$this, 'add_variation_cogs_field'], 10, 3);
        add_action('woocommerce_save_product_variation',   [$this, 'save_variation_cogs_field'], 10, 2);

        // Frontend chat widget + cart tracking
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // Prevent WP Rocket from minifying, caching, or delaying the Neuramerce widget script.
        // Without these filters WP Rocket serves a locally-cached stale copy and marks it as
        // text/rocketlazyloadscript, which delays the chat widget indefinitely.
        add_filter('rocket_exclude_js',          [$this, 'rocket_exclude_widget']);
        add_filter('rocket_delay_js_exclusions', [$this, 'rocket_exclude_widget']);
        add_filter('rocket_minify_excluded_src', [$this, 'rocket_exclude_widget']);

        // Attribution tracking script + WooCommerce purchase event
        add_action('wp_enqueue_scripts', [$this, 'enqueue_tracking_script']);
        add_action('woocommerce_thankyou', [$this, 'fire_purchase_event']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('rest_api_init', ['NWWS_Migrator_API', 'register_routes']);

        // Prevent CDN/proxy (Kinsta edge, Cloudflare) from caching REST responses.
        // Kinsta's edge cache ignores Cache-Control: no-store; Vary: * is the
        // standard RFC signal that a response must not be reused for any other request.
        add_filter('rest_post_dispatch', function(WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request): WP_REST_Response {
            $route = $request->get_route();
            if (str_starts_with($route, '/neuramerce/v1/') || str_starts_with($route, '/nwws/v1/')) {
                $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
                $response->header('Pragma', 'no-cache');
                $response->header('Vary', '*');
                $response->header('Surrogate-Control', 'no-store');
                $response->header('Expires', '0');
            }
            return $response;
        }, 10, 3);

        // Background order/product sync (WP-Cron callback — MUST be registered or cron fires silently)
        add_action('nwws_sync_order_bg',        [$this, 'sync_order'],             10, 1);
        add_action('nwws_sync_product_bg',       [$this, 'sync_product'],           10, 1);
        add_action('nwws_track_conversion_bg',   [$this, 'run_track_conversion_bg'], 10, 1);

        // AJAX
        add_action('admin_init',                      [$this, 'handle_setup_redirect']);
        add_action('wp_ajax_nwws_test_connection',    [$this, 'ajax_test_connection']);
        add_action('wp_ajax_nwws_diagnostics',        [$this, 'ajax_diagnostics']);
        add_action('wp_ajax_nwws_disconnect',         [$this, 'ajax_disconnect']);
        add_action('wp_ajax_nwws_sync_all_products',  [$this, 'ajax_sync_all_products']);
        add_action('wp_ajax_nwws_sync_all_orders',    [$this, 'ajax_sync_all_orders']);
        add_action('wp_ajax_nwws_get_sync_stats',     [$this, 'ajax_get_sync_stats']);
        add_action('wp_ajax_nwws_test_push',          [$this, 'ajax_test_push']);
    }

    public function init(): void {
        load_plugin_textdomain('neura-wp-woo-sync', false, dirname(plugin_basename(NWWS_PLUGIN_FILE)) . '/languages');
    }

    // =========================================================================
    // ADMIN MENU & SETTINGS
    // =========================================================================

    public function add_admin_menu(): void {
        if ( class_exists( 'WooCommerce' ) ) {
            add_submenu_page(
                'woocommerce',
                'Neura WooCommerce Sync',
                'Neura Sync',
                'manage_woocommerce',
                'neura-wp-woo-sync',
                [$this, 'render_settings_page']
            );
        } else {
            add_menu_page(
                'Neuramerce',
                'Neuramerce',
                'manage_options',
                'neura-wp-woo-sync',
                [$this, 'render_settings_page'],
                'dashicons-migrate',
                80
            );
        }
    }

    public function enqueue_admin_assets(string $hook): void {
        if ( ! in_array( $hook, [ 'woocommerce_page_neura-wp-woo-sync', 'toplevel_page_neura-wp-woo-sync' ], true ) ) return;

        wp_enqueue_style('nwws-admin',  NWWS_PLUGIN_URL . 'assets/css/admin.css', [], NWWS_VERSION);
        wp_enqueue_script('nwws-admin', NWWS_PLUGIN_URL . 'assets/js/admin.js',  ['jquery'], NWWS_VERSION, true);

        wp_localize_script('nwws-admin', 'nwwsData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('nwws_nonce'),
            'apiUrl'  => get_option('nwws_api_url', ''),
            'apiKey'  => get_option('nwws_api_key', ''),
            'pushUrl' => get_option('nwws_push_url', ''),
            'pushKey' => get_option('nwws_push_key', ''),
        ]);
    }

    public function enqueue_frontend_assets(): void {
        if (get_option('nwws_chat_enabled', '0') !== '1') return;

        $inbox_key = sanitize_text_field(get_option('nwws_chat_inbox_key', ''));
        if (empty($inbox_key)) return;

        // Base URL: strip /api suffix (with or without trailing path) from nwws_api_url.
        // Pattern: /api(/...)?$  — handles both "https://app.neuramerce.com/api" and ".../api/v1"
        $raw_url  = get_option('nwws_api_url', '');
        $base_url = $raw_url
            ? rtrim(preg_replace('#/api(/.*)?$#', '', $raw_url), '/')
            : 'https://app.neuramerce.com';

        // Current cart data (safe for JS output)
        $cart_items = [];
        $cart_total = 0.0;
        if (function_exists('WC') && WC()->cart && !is_admin()) {
            foreach (WC()->cart->get_cart() as $item) {
                $product      = $item['data'] ?? null;
                $cart_items[] = [
                    'product_id' => (int) $item['product_id'],
                    'name'       => $product ? wp_strip_all_tags($product->get_name()) : '',
                    'quantity'   => (int) $item['quantity'],
                    'price'      => $product ? (float) wc_get_price_including_tax($product) : 0.0,
                ];
            }
            $cart_total = (float) WC()->cart->get_cart_contents_total();
        }

        // Use data-widget-key attribute instead of ?key= URL param.
        // WP Rocket and other caching plugins strip query params when minifying external scripts,
        // which would make the widget unable to identify the inbox. HTML data-* attributes survive caching.
        $widget_loader_url = esc_url($base_url . '/widget-loader.js');
        $widget_key_attr   = esc_attr($inbox_key);
        $inbox_key_js      = wp_json_encode($inbox_key);
        $base_url_js       = wp_json_encode($base_url);
        $cart_js           = wp_json_encode(['items' => $cart_items, 'total' => $cart_total]);

        add_action('wp_footer', static function () use ($widget_loader_url, $widget_key_attr, $inbox_key_js, $base_url_js, $cart_js): void {
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            // Output a real <script src> tag — not via wp_register_script (dummy handle) because
            // caching plugins intercept that pattern and delay/strip it.
            // data-widget-key survives WP Rocket minification (unlike ?key= URL params).
            echo "\n" . '<script src="' . $widget_loader_url . '" data-widget-key="' . $widget_key_attr . '" async></script>' . "\n";
            echo '<script>' . "\n";
            echo '(function () {' . "\n";
            echo '  var inboxKey = ' . $inbox_key_js . ';' . "\n";
            echo '  var base     = ' . $base_url_js . ';' . "\n";
            echo '  var cart     = ' . $cart_js . ';' . "\n";
            echo <<<'JS'

  // ── Cart tracking helper ──────────────────────────────────────────────────
  function sendCartUpdate(cartData) {
    var fp = '';
    try { fp = localStorage.getItem('nmrc_fp') || ''; } catch (e) {}
    if (!fp) {
      setTimeout(function () { sendCartUpdate(cartData); }, 1500);
      return;
    }
    fetch(base + '/api/v1/inbox/track', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        event:       'cart_update',
        fingerprint: fp,
        inboxKey:    inboxKey,
        data:        { items: cartData.items, total: cartData.total }
      })
    }).catch(function () {});
  }

  // ── Send initial cart state after widget script loads ────────────────────
  // Use window event since WP Rocket may delay script loading until after DOMContentLoaded.
  window.addEventListener('load', function () {
    if (cart.items.length > 0 || cart.total > 0) {
      setTimeout(function () { sendCartUpdate(cart); }, 1200);
    }
  });

  // ── Listen for live WooCommerce cart events ───────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof jQuery === 'undefined') return;

    jQuery(document.body).on(
      'added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed',
      function () {
        var ajaxUrl = (typeof wc_cart_fragments_params !== 'undefined')
          ? wc_cart_fragments_params.wc_ajax_url
          : (typeof woocommerce_params !== 'undefined' ? woocommerce_params.ajax_url : '/');

        if (!ajaxUrl || ajaxUrl === '/') return;

        jQuery.post(
          ajaxUrl.replace('%%endpoint%%', 'get_refreshed_fragments'),
          {},
          function (data) {
            if (data && data.cart_hash !== undefined) {
              fetch(base + '/api/v1/inbox/widget-cart?inboxKey=' + encodeURIComponent(inboxKey), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
              }).then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d && d.cart) sendCartUpdate(d.cart); })
                .catch(function () {
                  var totalEl = document.querySelector('.cart-subtotal .woocommerce-Price-amount');
                  var totalText = totalEl ? totalEl.textContent.replace(/[^0-9,.]/g, '').replace(',', '.') : '0';
                  sendCartUpdate({ items: [], total: parseFloat(totalText) || 0 });
                });
            }
          }
        );
      }
    );

    jQuery(document.body).on('click', '.add_to_cart_button, .single_add_to_cart_button', function () {
      setTimeout(function () {
        var totalEl = document.querySelector('.cart-subtotal .woocommerce-Price-amount, .order-total .woocommerce-Price-amount');
        if (totalEl) {
          var total = parseFloat(totalEl.textContent.replace(/[^0-9,.]/g, '').replace(',', '.')) || 0;
          sendCartUpdate({ items: [], total: total });
        }
      }, 800);
    });
  });
})();
JS;
            echo '</script>' . "\n";
            // phpcs:enable
        }, 100);
    }

    /**
     * Excludes the Neuramerce widget script from WP Rocket optimizations.
     * Called by rocket_exclude_js, rocket_delay_js_exclusions, rocket_minify_excluded_src filters.
     *
     * @param array<string> $exclusions
     * @return array<string>
     */
    public function rocket_exclude_widget(array $exclusions): array {
        // Exclude the Neuramerce widget-loader from WP Rocket delay/minification.
        // Pattern matches both the external URL and the locally cached minified copy.
        $exclusions[] = 'widget-loader';
        // Ook track.js (server-side attributie) + de inline NauraTrack-bootstrap uitsluiten,
        // anders vertraagt WP Rocket de fingerprint vroeg in <head> en missen we touchpoints.
        $exclusions[] = 'track.js';
        $exclusions[] = 'NauraTrack';
        return $exclusions;
    }

    /**
     * Injecteert track.js voor conversie-tracking en multi-touch attributie.
     * Activeert alleen als nwws_tracking_token is ingevuld.
     */
    public function enqueue_tracking_script(): void {
        $token = sanitize_text_field(get_option('nwws_tracking_token', ''));
        if (empty($token)) return;

        $raw_url  = get_option('nwws_api_url', '');
        $base_url = $raw_url
            ? rtrim(preg_replace('#/api/.*$#', '', $raw_url), '/')
            : 'https://app.neuramerce.com';

        $inbox_key = sanitize_text_field(get_option('nwws_chat_inbox_key', ''));

        $token_js     = wp_json_encode($token);
        $base_url_js  = wp_json_encode($base_url);
        $inbox_key_js = wp_json_encode($inbox_key);

        // Inline script VÓÓR track.js zodat de variabelen beschikbaar zijn
        $before_inline = <<<JS
var NMRC_TOKEN = {$token_js};
window.NauraTrack = window.NauraTrack || {};
window.NauraTrack.key  = {$inbox_key_js};
window.NauraTrack.base = {$base_url_js};
JS;

        wp_register_script(
            'neuramerce-track',
            $base_url . '/track.js',
            [],
            NWWS_VERSION,
            false // in <head> — fingerprint zo vroeg mogelijk beschikbaar
        );
        wp_enqueue_script('neuramerce-track');
        wp_add_inline_script('neuramerce-track', $before_inline, 'before');
    }

    /**
     * Vuurt het purchase event op de WooCommerce bedankt-pagina.
     * Koppelt de aankoop aan de bezoekers-fingerprint voor multi-touch attributie.
     *
     * @param int $order_id WooCommerce order ID
     */
    public function fire_purchase_event(int $order_id): void {
        $token = sanitize_text_field(get_option('nwws_tracking_token', ''));
        if (empty($token)) return;

        $order    = wc_get_order($order_id);
        if (!$order) return;

        $order_id_js = wp_json_encode((string) $order_id);
        $value_js    = wp_json_encode((float) $order->get_total());
        $currency_js = wp_json_encode($order->get_currency());

        // Wacht tot track.js geladen is, dan stuur purchase event
        echo "<script>
(function waitForTrack() {
  if (window.NauraTrack && window.NauraTrack.purchase) {
    window.NauraTrack.purchase({$order_id_js}, {$value_js}, {$currency_js});
  } else {
    setTimeout(waitForTrack, 200);
  }
})();
</script>\n";
    }

    public function render_settings_page(): void {
        if (isset($_POST['nwws_save_settings'])) {
            check_admin_referer('nwws_settings');
            $save_section = sanitize_key($_POST['nwws_save_section'] ?? '');

            // --- Connectie-velden: Connect-tab (of legacy gecombineerd formulier) ---
            // Section-guard: opslaan vanuit één tab mag de velden van de andere tab niet wissen.
            if ($save_section !== 'woocommerce') {
            $prev_api_key = get_option('nwws_api_key', '');
            $prev_conn_id = get_option('nwws_connection_id', '');
            $new_api_url  = sanitize_text_field($_POST['nwws_api_url'] ?? 'https://app.neuramerce.com/api');
            $new_api_key  = sanitize_text_field($_POST['nwws_api_key'] ?? '');
            $new_conn_id  = sanitize_text_field($_POST['nwws_connection_id'] ?? '');
            update_option('nwws_api_url',       $new_api_url ?: 'https://app.neuramerce.com/api');
            update_option('nwws_api_key',       $new_api_key);
            update_option('nwws_connection_id', $new_conn_id);

            // Reset transient wanneer credentials gewijzigd zijn — voorkomt stale cache van vorig account
            if ($new_api_key !== $prev_api_key || $new_conn_id !== $prev_conn_id) {
                delete_transient('nwws_config_synced');
            }
            $saved_push_url = esc_url_raw($_POST['nwws_push_url'] ?? '');
            update_option('nwws_push_url', $saved_push_url);
            update_option('nwws_push_key', sanitize_text_field($_POST['nwws_push_key'] ?? ''));
            // Auto-extract workspace ID from push URL query string (?workspace=...)
            $parsed_qs = [];
            parse_str(parse_url($saved_push_url, PHP_URL_QUERY) ?? '', $parsed_qs);
            $extracted_ws = sanitize_text_field($parsed_qs['workspace'] ?? $_POST['nwws_workspace_id'] ?? '');
            if (!empty($extracted_ws)) update_option('nwws_workspace_id', $extracted_ws);
            } // einde connectie-velden

            // --- Sync-/veld-/chat-/tracking-velden: WooCommerce-tab (of legacy) ---
            if ($save_section !== 'connect') {
            update_option('nwws_sync_enabled',             !empty($_POST['nwws_sync_enabled'])             ? '1' : '0');
            update_option('nwws_sync_products',            !empty($_POST['nwws_sync_products'])            ? '1' : '0');
            update_option('nwws_sync_orders',              !empty($_POST['nwws_sync_orders'])              ? '1' : '0');
            update_option('nwws_sync_customers',           !empty($_POST['nwws_sync_customers'])           ? '1' : '0');
            update_option('nwws_track_conversions',        !empty($_POST['nwws_track_conversions'])        ? '1' : '0');
            update_option('nwws_sync_fields_prices',       !empty($_POST['nwws_sync_fields_prices'])       ? '1' : '0');
            update_option('nwws_sync_fields_cogs',         !empty($_POST['nwws_sync_fields_cogs'])         ? '1' : '0');
            update_option('nwws_sync_fields_stock',        !empty($_POST['nwws_sync_fields_stock'])        ? '1' : '0');
            update_option('nwws_sync_fields_ean',          !empty($_POST['nwws_sync_fields_ean'])          ? '1' : '0');
            update_option('nwws_sync_fields_categories',   !empty($_POST['nwws_sync_fields_categories'])   ? '1' : '0');
            $raw_attrs = $_POST['nwws_sync_attr'] ?? [];
            $selected_attrs = is_array($raw_attrs)
                ? implode(',', array_map('sanitize_key', $raw_attrs))
                : '';
            update_option('nwws_sync_attrs', $selected_attrs);

            // Chat widget
            update_option('nwws_chat_enabled',   !empty($_POST['nwws_chat_enabled'])   ? '1' : '0');
            update_option('nwws_chat_inbox_key', sanitize_text_field($_POST['nwws_chat_inbox_key'] ?? ''));

            // Attribution tracking
            update_option('nwws_tracking_token', sanitize_text_field($_POST['nwws_tracking_token'] ?? ''));

            // AI Chat Data
            update_option('nwws_ai_expose_order_status',  !empty($_POST['nwws_ai_expose_order_status'])  ? '1' : '0');
            update_option('nwws_ai_expose_customer_data', !empty($_POST['nwws_ai_expose_customer_data']) ? '1' : '0');
            update_option('nwws_ai_expose_cart_contents', !empty($_POST['nwws_ai_expose_cart_contents']) ? '1' : '0');
            } // einde sync-velden

            // --- Auto-fetch workspace config (alleen bij Connect-save of legacy) ---
            // Auto-fetch workspace config als inbox key nog leeg is na opslaan POST-data
            // (auto-fetch pas hier zodat POST-waarden niet overschreven worden door de fetch)
            if ($save_section !== 'woocommerce') {
            $current_inbox_key = get_option('nwws_chat_inbox_key', '');
            if (!empty($new_api_key) && !empty($new_conn_id) && empty($current_inbox_key)) {
                $config_url = rtrim($new_api_url ?: 'https://app.neuramerce.com/api', '/') . '/v1/woocommerce/workspace-config';
                $config_res = wp_remote_get(add_query_arg('connectionId', rawurlencode($new_conn_id), $config_url), [
                    'headers' => ['X-Neuramerce-Plugin-Key' => $new_api_key],
                    'timeout' => 10,
                ]);
                $notice_type = 'warning';
                $notice_msg  = '&#9888; Instellingen opgeslagen, maar verbinding met Neuramerce kon niet worden geverifieerd — controleer API Key en Connection ID.';
                if (!is_wp_error($config_res) && wp_remote_retrieve_response_code($config_res) === 200) {
                    $config        = json_decode(wp_remote_retrieve_body($config_res), true);
                    $fetched_inbox = !empty($config['inboxKey']);
                    $fetched_token = !empty($config['trackingToken']);
                    if ($fetched_inbox) update_option('nwws_chat_inbox_key', sanitize_text_field($config['inboxKey']));
                    if ($fetched_token) update_option('nwws_tracking_token',  sanitize_text_field($config['trackingToken']));
                    set_transient('nwws_config_synced', '1', HOUR_IN_SECONDS);
                    $notice_type = 'success';
                    $notice_msg  = $fetched_inbox
                        ? '&#10003; Verbinding geverifieerd &amp; chat widget automatisch geconfigureerd.'
                        : '&#10003; Verbinding met Neuramerce geverifieerd. Chat widget wordt geconfigureerd zodra je inbox beschikbaar is — <a href="' . esc_url(admin_url('admin.php?page=neura-wp-woo-sync&tab=connect')) . '">vernieuw deze pagina</a>.';
                }
                echo '<div class="notice notice-' . esc_attr($notice_type) . '"><p>' . wp_kses($notice_msg, ['a' => ['href' => []]]) . '</p></div>';
            }
            } // einde auto-fetch

            echo '<div class="notice notice-success"><p>Instellingen opgeslagen!</p></div>';
        }

        if (isset($_POST['wcmac_migrator_action'])) {
            check_admin_referer('wcmac_migrator');
            $action = sanitize_key($_POST['wcmac_migrator_action']);
            if ($action === 'generate') {
                update_option('neuramerce_api_key', wp_generate_password(32, false));
                echo '<div class="notice notice-success"><p>Nieuwe API key gegenereerd.</p></div>';
            } elseif ($action === 'revoke') {
                delete_option('neuramerce_api_key');
                echo '<div class="notice notice-warning"><p>API key ingetrokken.</p></div>';
            }
        }

        if (isset($_POST['nwws_clear_log'])) {
            check_admin_referer('nwws_clear_log');
            update_option('nwws_sync_log', '[]');
            echo '<div class="notice notice-success"><p>Synchronisatie-log gewist.</p></div>';
        }

        // Force refresh via URL param (wist transient + status zodat opnieuw wordt opgehaald)
        if (!empty($_GET['nwws_force_refresh']) && current_user_can('manage_options')) {
            delete_transient('nwws_config_synced');
            delete_option('nwws_config_fetch_status');
        }

        // Auto-fetch inbox key + tracking token als die nog leeg zijn maar API credentials bekend zijn
        $this->maybe_auto_fetch_workspace_config();

        $active_tab = sanitize_key($_GET['tab'] ?? 'connect');
        $page_url   = admin_url('admin.php?page=neura-wp-woo-sync');
        $wc_active  = class_exists('WooCommerce');
        $connected  = !empty(get_option('nwws_api_url')) && !empty(get_option('nwws_api_key'));
        $conn_badge = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-left:5px;vertical-align:middle;background:' . ($connected ? '#46b450' : '#dc3232') . ';" title="' . ($connected ? 'Verbonden' : 'Niet verbonden') . '"></span>';
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:1.5rem">
            <a href="<?php echo esc_url($page_url . '&tab=connect'); ?>"
               class="nav-tab <?php echo $active_tab === 'connect' ? 'nav-tab-active' : ''; ?>">
                Neura Connect
            </a>
            <a href="<?php echo esc_url($page_url . '&tab=woocommerce'); ?>"
               class="nav-tab <?php echo $active_tab === 'woocommerce' ? 'nav-tab-active' : ''; ?>">
                WordPress &amp; WooCommerce<?php echo $conn_badge; ?>
            </a>
            <a href="<?php echo esc_url($page_url . '&tab=migrate'); ?>"
               class="nav-tab <?php echo $active_tab === 'migrate' ? 'nav-tab-active' : ''; ?>">
                Migratie
            </a>
            <a href="<?php echo esc_url($page_url . '&tab=logs'); ?>"
               class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                Logs
            </a>
        </nav>
        <?php
        if ($active_tab === 'woocommerce') {
            include NWWS_PLUGIN_DIR . 'templates/tab-woocommerce.php';
        } elseif ($active_tab === 'migrate') {
            include NWWS_PLUGIN_DIR . 'templates/migration-tab.php';
        } elseif ($active_tab === 'logs') {
            include NWWS_PLUGIN_DIR . 'templates/tab-logs.php';
        } else {
            include NWWS_PLUGIN_DIR . 'templates/tab-connect.php';
        }
    }

    // =========================================================================
    // SETUP REDIRECT — admin_init (vóór headers verstuurd)
    // =========================================================================

    /**
     * Verwerkt de auto-configure URL params die Neuramerce meestuurt bij het openen van WP admin.
     * Aangeroepen via admin_init hook — op dat moment zijn HTTP-headers nog NIET verstuurd,
     * dus wp_safe_redirect() + exit werkt hier correct.
     * render_admin_page() wordt te laat aangeroepen (headers al weg) voor een redirect.
     */
    public function handle_setup_redirect(): void {
        if (!is_admin()) return;
        if (($_GET['page'] ?? '') !== 'neura-wp-woo-sync') return;
        if (empty($_GET['nwws_setup'])) return;
        if (!current_user_can('manage_options')) return;

        $new_api_key = sanitize_text_field(wp_unslash($_GET['nwws_api_key'] ?? ''));
        $new_conn_id = sanitize_text_field(wp_unslash($_GET['nwws_conn_id'] ?? ''));
        if (empty($new_api_key) || empty($new_conn_id)) return;

        update_option('nwws_api_url',       'https://app.neuramerce.com/api');
        update_option('nwws_api_key',       $new_api_key);
        update_option('nwws_connection_id', $new_conn_id);
        delete_transient('nwws_config_synced');
        delete_option('nwws_config_fetch_status');

        // Haal direct workspace config op (inbox key + tracking token + auto-enables chat widget)
        $this->do_fetch_workspace_config($new_api_key, $new_conn_id);

        // Sla success flag op in transient zodat het template een inline notice kan tonen
        set_transient('nwws_just_configured', '1', 60);

        wp_safe_redirect(admin_url('admin.php?page=neura-wp-woo-sync&tab=connect'));
        exit;
    }

    // =========================================================================
    // AUTO-FETCH WORKSPACE CONFIG
    // =========================================================================

    /**
     * Haalt inbox key + tracking token op van Neuramerce als die nog leeg zijn.
     * Wordt aangeroepen bij het laden van de settings pagina.
     * Slaat altijd het resultaat op in nwws_config_fetch_status zodat het template
     * een passende boodschap kan tonen (ok_configured, ok_no_inbox, error_401, etc.).
     * Gebruikt een transient zodat het niet bij elke pageload een HTTP request doet.
     */
    private function maybe_auto_fetch_workspace_config(): void {
        $api_key = get_option('nwws_api_key', '');
        $conn_id = get_option('nwws_connection_id', '');
        if (empty($api_key) || empty($conn_id)) return;

        // Transient check altijd vooraan — ongeacht of inbox key leeg is of niet.
        // Zonder dit maakt de plugin bij elke pageload een HTTP request als inbox key leeg is.
        if (get_transient('nwws_config_synced')) return;

        $this->do_fetch_workspace_config($api_key, $conn_id);
    }

    /**
     * Voert de daadwerkelijke API call uit en slaat het resultaat op.
     * Aanroepbaar vanuit zowel de auto-fetch als de AJAX test handler.
     */
    private function do_fetch_workspace_config(string $api_key, string $conn_id): string {
        $api_url    = get_option('nwws_api_url', 'https://app.neuramerce.com/api');
        $config_url = rtrim($api_url, '/') . '/v1/woocommerce/workspace-config';
        $response   = wp_remote_get(
            add_query_arg('connectionId', rawurlencode($conn_id), $config_url),
            ['headers' => ['X-Neuramerce-Plugin-Key' => $api_key], 'timeout' => 8, 'sslverify' => true]
        );

        if (is_wp_error($response)) {
            update_option('nwws_config_fetch_status', 'error_network');
            set_transient('nwws_config_synced', '1', 2 * MINUTE_IN_SECONDS);
            return 'error_network';
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 401) {
            update_option('nwws_config_fetch_status', 'error_401');
            set_transient('nwws_config_synced', '1', 5 * MINUTE_IN_SECONDS);
            return 'error_401';
        }

        if ($code === 404) {
            // Verbinding bestaat niet meer op Neuramerce — verwijder gecachte credentials automatisch.
            // De plugin toont daarna een "opnieuw koppelen" notice i.p.v. een onoplosbare fout.
            $this->clear_connection_options();
            update_option('nwws_config_fetch_status', 'error_404');
            set_transient('nwws_config_synced', '1', 5 * MINUTE_IN_SECONDS);
            return 'error_404';
        }

        if ($code !== 200) {
            $status = 'error_' . $code;
            update_option('nwws_config_fetch_status', $status);
            set_transient('nwws_config_synced', '1', 5 * MINUTE_IN_SECONDS);
            return $status;
        }

        $config         = json_decode(wp_remote_retrieve_body($response), true);
        $inbox_key      = !empty($config['inboxKey'])      ? sanitize_text_field($config['inboxKey'])      : null;
        $tracking_token = !empty($config['trackingToken']) ? sanitize_text_field($config['trackingToken']) : null;

        if ($inbox_key) {
            update_option('nwws_chat_inbox_key', $inbox_key);
            update_option('nwws_chat_enabled', '1'); // auto-enable widget zodra inbox key beschikbaar is
        }
        if ($tracking_token) update_option('nwws_tracking_token', $tracking_token);

        $status = ($inbox_key !== null) ? 'ok_configured' : 'ok_no_inbox';
        update_option('nwws_config_fetch_status', $status);
        set_transient('nwws_config_synced', '1', HOUR_IN_SECONDS);
        return $status;
    }

    /**
     * Wist alle verbindingsgegevens uit WordPress opties en transients.
     * Aanroepbaar vanuit AJAX disconnect en automatisch bij 404-respons.
     */
    private function clear_connection_options(): void {
        delete_option('nwws_api_key');
        delete_option('nwws_connection_id');
        delete_option('nwws_api_url');
        delete_option('nwws_chat_inbox_key');
        delete_option('nwws_tracking_token');
        delete_option('nwws_chat_enabled');
        delete_option('nwws_config_fetch_status');
        delete_transient('nwws_config_synced');
    }

    // COGS (Cost of Goods Sold) FIELDS
    // =========================================================================

    public function add_cogs_field(): void {
        global $post;

        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id'          => '_cogs',
            'label'       => 'Cost of Goods (COGS)',
            'desc_tip'    => true,
            'description' => 'Kostprijs van dit product voor accurate winstmarge en ROAS berekening',
            'type'        => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
        ]);

        woocommerce_wp_select([
            'id'          => '_cogs_currency',
            'label'       => 'COGS Valuta',
            'desc_tip'    => true,
            'description' => 'Valuta van de inkoopprijs (EUR of USD)',
            'options'     => ['EUR' => 'EUR (€)', 'USD' => 'USD ($)'],
            'value'       => get_post_meta($post->ID, '_cogs_currency', true) ?: 'EUR',
        ]);

        echo '</div>';
    }

    public function save_cogs_field(int $post_id): void {
        $cogs = isset($_POST['_cogs']) ? sanitize_text_field($_POST['_cogs']) : '';
        update_post_meta($post_id, '_cogs', $cogs);

        $currency = isset($_POST['_cogs_currency']) && in_array($_POST['_cogs_currency'], ['EUR', 'USD'], true)
            ? $_POST['_cogs_currency'] : 'EUR';
        update_post_meta($post_id, '_cogs_currency', $currency);
    }

    public function add_variation_cogs_field(int $loop, array $variation_data, \WP_Post $variation): void {
        woocommerce_wp_text_input([
            'id'          => '_cogs[' . $loop . ']',
            'label'       => 'COGS',
            'desc_tip'    => true,
            'description' => 'Kostprijs van deze variatie',
            'type'        => 'number',
            'value'       => get_post_meta($variation->ID, '_cogs', true),
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
        ]);

        woocommerce_wp_select([
            'id'      => '_cogs_currency[' . $loop . ']',
            'label'   => 'COGS Valuta',
            'options' => ['EUR' => 'EUR (€)', 'USD' => 'USD ($)'],
            'value'   => get_post_meta($variation->ID, '_cogs_currency', true) ?: 'EUR',
        ]);
    }

    public function save_variation_cogs_field(int $variation_id, int $i): void {
        $cogs = isset($_POST['_cogs'][$i]) ? sanitize_text_field($_POST['_cogs'][$i]) : '';
        update_post_meta($variation_id, '_cogs', $cogs);

        $currency = isset($_POST['_cogs_currency'][$i]) && in_array($_POST['_cogs_currency'][$i], ['EUR', 'USD'], true)
            ? $_POST['_cogs_currency'][$i] : 'EUR';
        update_post_meta($variation_id, '_cogs_currency', $currency);
    }

    // =========================================================================
    // PRODUCT SYNC
    // =========================================================================

    public function sync_new_product(int $product_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_products') !== '1') return;
        if (!wp_next_scheduled('nwws_sync_product_bg', [$product_id])) {
            wp_schedule_single_event(time(), 'nwws_sync_product_bg', [$product_id]);
        }
    }

    public function sync_product_update(int $product_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_products') !== '1') return;
        if (!wp_next_scheduled('nwws_sync_product_bg', [$product_id])) {
            wp_schedule_single_event(time(), 'nwws_sync_product_bg', [$product_id]);
        }
    }

    public function sync_product(int $product_id): void {
        $product = wc_get_product($product_id);
        if (!$product) return;

        $push_url = get_option('nwws_push_url', '');
        $push_key = get_option('nwws_push_key', '');

        if (!empty($push_url) && !empty($push_key)) {
            $this->push_product_to_neuramerce($product, $product_id, $push_url, $push_key);
            return;
        }

        // Legacy fallback (no dedicated push endpoint configured)
        $cogs_currency = get_post_meta($product_id, '_cogs_currency', true) ?: 'EUR';
        $data = [
            'id'             => $product->get_id(),
            'name'           => $product->get_name(),
            'sku'            => $product->get_sku(),
            'price'          => $product->get_price(),
            'regular_price'  => $product->get_regular_price(),
            'sale_price'     => $product->get_sale_price(),
            'cogs'           => get_post_meta($product_id, '_cogs', true),
            'cogs_currency'  => $cogs_currency,
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'image_url'      => wp_get_attachment_url($product->get_image_id()),
            'permalink'      => $product->get_permalink(),
            'categories'     => wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']),
            'type'           => $product->get_type(),
            'updated_at'     => current_time('mysql'),
        ];
        $this->send_to_api('products', $data, 'POST');
        update_post_meta($product_id, '_nwws_last_sync', current_time('timestamp'));
    }

    private function push_product_to_neuramerce(\WC_Product $product, int $product_id, string $push_url, string $push_key): void {
        $sku = $product->get_sku();
        if (empty($sku)) return; // Neuramerce requires SKU

        // Always send identity fields
        $data = [
            'id'        => $product->get_id(),
            'sku'       => $sku,
            'name'      => $product->get_name(),
            'permalink' => $product->get_permalink(),
        ];

        // Prices
        if (get_option('nwws_sync_fields_prices', '1') === '1') {
            $data['regular_price'] = $product->get_regular_price();
            $data['sale_price']    = $product->get_sale_price();
        }

        // COGS
        if (get_option('nwws_sync_fields_cogs', '1') === '1') {
            $data['cogs']          = get_post_meta($product_id, '_cogs', true);
            $data['cogs_currency'] = get_post_meta($product_id, '_cogs_currency', true) ?: 'EUR';
        }

        // Stock
        if (get_option('nwws_sync_fields_stock', '1') === '1') {
            $data['stock_status']   = $product->get_stock_status();
            $data['stock_quantity'] = $product->get_stock_quantity();
        }

        // EAN / GTIN / UPC / ISBN
        if (get_option('nwws_sync_fields_ean', '1') === '1') {
            $ean = '';
            // WooCommerce 8.6+ stores GTIN as global_unique_id
            if (method_exists($product, 'get_global_unique_id')) {
                $ean = (string) $product->get_global_unique_id();
            }
            // Fallback: common meta_data keys
            if (empty($ean)) {
                foreach (['_ean', '_gtin', 'barcode', '_barcode', 'ean', 'gtin', '_wc_gtin'] as $meta_key) {
                    $val = get_post_meta($product_id, $meta_key, true);
                    if (!empty($val)) { $ean = (string) $val; break; }
                }
            }
            $data['ean'] = $ean ?: null;
        }

        // Brand / Color / Size from selected WooCommerce attribute taxonomies
        $sync_attrs_raw  = get_option('nwws_sync_attrs', '');
        $sync_attrs_list = $sync_attrs_raw !== ''
            ? array_filter(array_map('trim', explode(',', $sync_attrs_raw)))
            : null; // null = legacy mode (sync all by pattern)

        if ($sync_attrs_list !== null && !empty($sync_attrs_list)) {
            $brand = $color = $size = null;
            foreach ($product->get_attributes() as $slug => $attribute) {
                if (!in_array($slug, $sync_attrs_list, true)) continue;
                if ($attribute->is_taxonomy()) {
                    $label   = strtolower(wc_attribute_label($slug));
                    $options = wc_get_product_terms($product_id, $slug, ['fields' => 'names']);
                    $value   = implode(', ', $options);
                } else {
                    $label   = strtolower($attribute->get_name());
                    $value   = implode(', ', $attribute->get_options());
                }
                if (preg_match('/brand|merk|fabrikant|manufacturer/', $label)) {
                    $brand = $value ?: null;
                } elseif (preg_match('/colou?r|kleur/', $label)) {
                    $color = $value ?: null;
                } elseif (preg_match('/size|maat|afmeting|formaat/', $label)) {
                    $size = $value ?: null;
                }
            }
            $data['brand'] = $brand;
            $data['color'] = $color;
            $data['size']  = $size;
        } elseif ($sync_attrs_list === null) {
            // Legacy: sync brand/color/size via individual options
            $sync_brand = get_option('nwws_sync_fields_brand', '1') === '1';
            $sync_color = get_option('nwws_sync_fields_color', '1') === '1';
            $sync_size  = get_option('nwws_sync_fields_size',  '1') === '1';
            if ($sync_brand || $sync_color || $sync_size) {
                $brand = $color = $size = null;
                foreach ($product->get_attributes() as $slug => $attribute) {
                    if ($attribute->is_taxonomy()) {
                        $label   = strtolower(wc_attribute_label($slug));
                        $options = wc_get_product_terms($product_id, $slug, ['fields' => 'names']);
                        $value   = implode(', ', $options);
                    } else {
                        $label   = strtolower($attribute->get_name());
                        $value   = implode(', ', $attribute->get_options());
                    }
                    if (preg_match('/brand|merk|fabrikant|manufacturer/', $label)) $brand = $value ?: null;
                    elseif (preg_match('/colou?r|kleur/', $label))                 $color = $value ?: null;
                    elseif (preg_match('/size|maat|afmeting|formaat/', $label))    $size  = $value ?: null;
                }
                if ($sync_brand) $data['brand'] = $brand;
                if ($sync_color) $data['color'] = $color;
                if ($sync_size)  $data['size']  = $size;
            }
        }

        // Categories
        if (get_option('nwws_sync_fields_categories', '1') === '1') {
            $data['categories'] = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
        }

        $response = wp_remote_post($push_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $push_key,
            ],
            'body'    => wp_json_encode($data),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('NWWS Push Error: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            update_post_meta($product_id, '_nwws_last_sync', current_time('timestamp'));
        } else {
            error_log('NWWS Push Error: HTTP ' . $code . ' — ' . wp_remote_retrieve_body($response));
        }
    }

    // =========================================================================
    // CUSTOM ORDER STATUS — "Naar warehouse"
    // =========================================================================

    /**
     * Registreer de custom order-status `wc-naar-warehouse`.
     *
     * Doel: zodra Neuramerce een order naar GP/Warehouse Online doorstuurt
     * verandert de Woo-status naar deze custom status. Daarmee blijft
     * 'processing' (Verwerken) gereserveerd voor orders die nog handmatig
     * opgepakt moeten worden — en is in één oogopslag duidelijk welke orders
     * al in fulfillment-flow zitten.
     */
    public function register_naar_warehouse_status(): void {
        register_post_status('wc-naar-warehouse', [
            'label'                     => _x('Naar warehouse', 'Order status', 'neura-wp-woo-sync'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: aantal orders met deze status
            'label_count'               => _n_noop(
                'Naar warehouse <span class="count">(%s)</span>',
                'Naar warehouse <span class="count">(%s)</span>',
                'neura-wp-woo-sync'
            ),
        ]);
    }

    /**
     * Voeg toe aan de WC orderstatus-dropdown (admin én rapporten).
     * Volgorde: na 'processing' zodat ze visueel naast elkaar staan.
     */
    public function add_naar_warehouse_to_order_statuses(array $statuses): array {
        $new_statuses = [];
        foreach ($statuses as $key => $label) {
            $new_statuses[$key] = $label;
            if ('wc-processing' === $key) {
                $new_statuses['wc-naar-warehouse'] = _x('Naar warehouse', 'Order status', 'neura-wp-woo-sync');
            }
        }
        return $new_statuses;
    }

    /** Markeer als 'paid' status zodat WC stock-decrement, refund-flow etc. correct werken. */
    public function mark_naar_warehouse_as_paid(array $statuses): array {
        $statuses[] = 'naar-warehouse';
        return $statuses;
    }

    /** Includer in WC reports (omzet/aantal verkopen). */
    public function mark_naar_warehouse_for_reports(array $statuses): array {
        $statuses[] = 'naar-warehouse';
        return $statuses;
    }

    /** Sta toe dat een order in deze status nog payment_complete krijgt (refund/herzending). */
    public function mark_naar_warehouse_payment_complete(array $statuses): array {
        $statuses[] = 'naar-warehouse';
        return $statuses;
    }

    // =========================================================================
    // MULTI-SHIPMENT STATUS (v1.11.0) — partially_shipped voor split-orders
    // =========================================================================
    //
    // Doel: order met regels naar verschillende warehouses (Eindhoven via GP +
    // Venlo via Warehouse Online) toont 'wc-partially-shipped' zodra eerste
    // shipment binnen is, en pas 'completed' wanneer alle shipments klaar zijn.
    // Neura's Postgres-trigger op order_shipments hercomputeert dit automatisch
    // en pusht via plugin-endpoint /orders/{id}/status.

    /**
     * Registreer custom order-status `wc-partially-shipped`.
     */
    public function register_partially_shipped_status(): void {
        register_post_status('wc-partially-shipped', [
            'label'                     => _x('Gedeeltelijk verzonden', 'Order status', 'neura-wp-woo-sync'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: aantal orders met deze status
            'label_count'               => _n_noop(
                'Gedeeltelijk verzonden <span class="count">(%s)</span>',
                'Gedeeltelijk verzonden <span class="count">(%s)</span>',
                'neura-wp-woo-sync'
            ),
        ]);
    }

    /** Voeg toe aan de WC orderstatus-dropdown direct vóór 'completed'. */
    public function add_partially_shipped_to_order_statuses(array $statuses): array {
        $new_statuses = [];
        foreach ($statuses as $key => $label) {
            if ('wc-completed' === $key) {
                $new_statuses['wc-partially-shipped'] = _x('Gedeeltelijk verzonden', 'Order status', 'neura-wp-woo-sync');
            }
            $new_statuses[$key] = $label;
        }
        return $new_statuses;
    }

    /** Markeer als 'paid' status — geld is binnen, alleen levering loopt nog. */
    public function mark_partially_shipped_as_paid(array $statuses): array {
        $statuses[] = 'partially-shipped';
        return $statuses;
    }

    /** Includer in WC reports (omzet/aantal verkopen). */
    public function mark_partially_shipped_for_reports(array $statuses): array {
        $statuses[] = 'partially-shipped';
        return $statuses;
    }

    /**
     * Sta toe dat order in deze status nog payment_complete krijgt.
     *
     * Scenario: handmatig-betaalde gateway (bank-transfer) → order in
     * 'pending' → admin pakt 'm op → split naar 2 warehouses → eerste
     * shipment komt aan → status springt naar 'partially-shipped' zonder
     * ooit door 'processing' te zijn gegaan. Zonder deze filter wordt
     * payment_complete() nooit getriggerd → stock-decrement, downloadable
     * products en transactional emails missen.
     */
    public function mark_partially_shipped_payment_complete(array $statuses): array {
        $statuses[] = 'partially-shipped';
        return $statuses;
    }

    /**
     * HPOS-compatibele registratie: WC 8.x leest order-status uit het
     * dedicated `wc_orders.status`-veld via deze filter, niet via WP's
     * post_status-registry. Zonder deze filter valt 'wc-partially-shipped'
     * uit de admin-dropdown op shops met custom_orders_table_usage_is_enabled().
     *
     * Filter komt parallel met register_post_status — dubbele registratie is
     * idempotent want WC merge't ze.
     */
    public function add_partially_shipped_to_post_statuses(array $statuses): array {
        $statuses['wc-partially-shipped'] = [
            'label'                     => _x('Gedeeltelijk verzonden', 'Order status', 'neura-wp-woo-sync'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Gedeeltelijk verzonden <span class="count">(%s)</span>',
                'Gedeeltelijk verzonden <span class="count">(%s)</span>',
                'neura-wp-woo-sync'
            ),
        ];
        return $statuses;
    }

    // =========================================================================
    // EMAIL-SUPPRESSION (v1.11.0) — A1+A2 dubbele-mail-fix
    // =========================================================================

    /**
     * Onderdruk WC-default klant-mail wanneer Neura via z'n inbox-template-
     * engine al een mail stuurt voor deze status. Voorkomt dubbele mails bij
     * snooze (Neura snooze-mail) en partially_shipped (Neura split-shipment-mail).
     *
     * Filter-API: WooCommerce roept woocommerce_email_enabled_customer_<status>_order
     * aan vóór het verzenden. Returnt false → mail wordt niet verstuurd.
     *
     * @param bool   $enabled Standaard-state (true = mail wordt gestuurd)
     * @param object $order   WC_Order
     * @return bool
     */
    public function maybe_suppress_default_email($enabled, $order, $email = null): bool {
        if (!$enabled) return false;
        if (!is_object($order) || !method_exists($order, 'get_status')) return $enabled;

        $status = $order->get_status();
        // Custom statussen waar Neura zelf de mail-flow regelt:
        $neura_handled_statuses = [
            'gesnoozed',          // snooze-mail via inbox-template
            'partially-shipped',  // split-shipment-mail via inbox-template
        ];

        // Allow filter — site-eigenaar kan extra statussen toevoegen via
        // wp-config.php of mu-plugin als ze aanvullende mails via Neura sturen.
        $neura_handled_statuses = apply_filters('nwws_neura_handled_email_statuses', $neura_handled_statuses);

        if (in_array($status, $neura_handled_statuses, true)) {
            return false;
        }
        return $enabled;
    }

    // =========================================================================
    // CUSTOM ORDER STATUSSEN (v1.3.0) — door Neura aangeleverd via /order-statuses
    // =========================================================================

    /**
     * Lees lijst custom statussen uit WP option (gepushed door Neura via REST).
     * Returnt array met sanitized rijen — elke rij heeft tenminste 'key' en 'label'.
     */
    private function nwws_get_custom_statuses(): array {
        $raw = get_option('nwws_custom_order_statuses', []);
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, fn($s) => is_array($s) && !empty($s['key'])));
    }

    /**
     * Registreer alle door Neura aangeleverde statussen als post_status.
     * Draait op `init` priority 11 — ná register_naar_warehouse_status zodat dat
     * gegarandeerd eerst klaar is en custom statussen daar nooit overheen schrijven.
     */
    public function nwws_register_custom_statuses(): void {
        foreach ($this->nwws_get_custom_statuses() as $s) {
            if (empty($s['is_active']) && isset($s['is_active'])) continue;
            $key = sanitize_key((string) $s['key']);
            register_post_status('wc-' . $key, [
                'label'                     => sanitize_text_field((string) ($s['label'] ?? $key)),
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
            ]);
        }
    }

    /** Voeg de custom statussen toe aan de WC orderstatus-dropdown. */
    public function nwws_add_custom_statuses_to_dropdown(array $statuses): array {
        foreach ($this->nwws_get_custom_statuses() as $s) {
            if (isset($s['is_active']) && empty($s['is_active'])) continue;
            $key   = 'wc-' . sanitize_key((string) $s['key']);
            $label = sanitize_text_field((string) ($s['label'] ?? $s['key']));
            $statuses[$key] = $label;
        }
        return $statuses;
    }

    /** Markeer custom statussen die als 'betaald' tellen (uit Neura-config). */
    public function nwws_mark_custom_paid(array $statuses): array {
        foreach ($this->nwws_get_custom_statuses() as $s) {
            if (!empty($s['counts_as_paid'])) {
                $statuses[] = sanitize_key((string) $s['key']);
            }
        }
        return $statuses;
    }

    /** Includer custom statussen in WC reports (omzet/aantal). */
    public function nwws_include_custom_in_reports(array $statuses): array {
        foreach ($this->nwws_get_custom_statuses() as $s) {
            $statuses[] = sanitize_key((string) $s['key']);
        }
        return $statuses;
    }

    // =========================================================================
    // VOORRAADSTATUS-TEKST (v1.3.0) — vervangt Woo Custom Stock Status Pro
    // =========================================================================

    /**
     * Vervang de standaard WC-availability-tekst door de Neura-tekst.
     * Volgorde: product-override > eerste matchende categorie > WC default.
     * Beïnvloedt alleen de getoonde tekst, niet de instock/outofstock class.
     */
    public function nwws_filter_availability(array $availability, $product): array {
        if (!is_object($product) || !method_exists($product, 'get_id')) return $availability;

        $product_id = $product->get_id();
        $text = (string) get_post_meta($product_id, '_nwws_stock_status_text', true);

        if ($text === '') {
            $cat_ids = method_exists($product, 'get_category_ids') ? (array) $product->get_category_ids() : [];
            foreach ($cat_ids as $cat_id) {
                $cat_text = (string) get_term_meta((int) $cat_id, '_nwws_stock_status_text', true);
                if ($cat_text !== '') { $text = $cat_text; break; }
            }
        }

        if ($text !== '') {
            $availability['availability'] = esc_html($text);
        }
        return $availability;
    }

    // =========================================================================
    // ORDER SYNC
    // =========================================================================

    public function sync_new_order(int $order_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_orders') !== '1') return;
        if (doing_action('nwws_sync_order_bg')) return;
        if (!wp_next_scheduled('nwws_sync_order_bg', [$order_id])) {
            wp_schedule_single_event(time(), 'nwws_sync_order_bg', [$order_id]);
        }
    }

    public function sync_order_update(int $order_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_orders') !== '1') return;
        if (doing_action('nwws_sync_order_bg')) return;
        if (!wp_next_scheduled('nwws_sync_order_bg', [$order_id])) {
            wp_schedule_single_event(time(), 'nwws_sync_order_bg', [$order_id]);
        }
    }

    public function sync_order_status_change(int $order_id, string $old_status, string $new_status, \WC_Order $order): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_orders') !== '1') return;
        try { $this->sync_order($order_id); } catch (\Throwable $e) { error_log('NWWS sync_order_status error: ' . $e->getMessage()); }

        if (get_option('nwws_track_conversions') === '1' && $new_status === 'completed') {
            if (!wp_next_scheduled('nwws_track_conversion_bg', [$order_id])) {
                wp_schedule_single_event(time(), 'nwws_track_conversion_bg', [$order_id]);
            }
        }
    }

    public function sync_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $items      = [];
        $total_cogs = 0;

        // Prime product meta cache to avoid N+1 queries per order line item
        $line_product_ids = array_filter(array_map(fn($item) => $item->get_product_id(), $order->get_items()));
        if (!empty($line_product_ids)) update_meta_cache('post', array_unique(array_values($line_product_ids)));

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $cogs    = 0;

            if ($product) {
                $cogs        = (float) get_post_meta($product->get_id(), '_cogs', true);
                $total_cogs += $cogs * $item->get_quantity();
            }

            $items[] = [
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'sku'          => $product ? $product->get_sku() : null,
                'quantity'     => $item->get_quantity(),
                'price'        => $product ? (float) $product->get_price() : null,
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'cogs'         => $cogs,
                'unit_cogs'    => $product ? (float)($product->get_meta('_cogs_price') ?: $product->get_meta('_purchase_price') ?: $cogs) : $cogs,
                'variant_name' => $item->get_variation_id() ? implode(' / ', array_filter(array_values(wc_get_product_variation_attributes($item->get_variation_id())))) : null,
            ];
        }

        $revenue       = (float) $order->get_total();
        $profit        = $revenue - $total_cogs;
        $profit_margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        $data = [
            'id'              => $order->get_id(),
            'order_number'    => $order->get_order_number(),
            'status'          => $order->get_status(),
            'currency'        => $order->get_currency(),
            'total'           => $revenue,
            'subtotal'        => $order->get_subtotal(),
            'shipping'        => $order->get_shipping_total(),
            'tax'             => $order->get_total_tax(),
            'discount'        => $order->get_discount_total(),
            'total_cogs'      => $total_cogs,
            'profit'          => $profit,
            'profit_margin'   => round($profit_margin, 2),
            'items'           => $items,
            'customer_id'     => $order->get_customer_id(),
            'customer_email'  => $order->get_billing_email(),
            'customer_name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'billing_country' => $order->get_billing_country(),
            'payment_method'  => $order->get_payment_method_title(),
            'created_at'      => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
            'updated_at'      => current_time('mysql'),
            'utm_source'      => $order->get_meta('_utm_source'),
            'utm_medium'      => $order->get_meta('_utm_medium'),
            'utm_campaign'    => $order->get_meta('_utm_campaign'),
            'utm_content'     => $order->get_meta('_utm_content'),
            'utm_term'        => $order->get_meta('_utm_term'),
            'fbclid'          => $order->get_meta('_fbclid'),
            'billing'          => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
            ],
            'shipping_address' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ],
            'coupon_codes'     => array_map(function($item) { return $item->get_code(); }, $order->get_coupon_codes()),
            'tracking_number'  => $order->get_meta('_tracking_number') ?: $order->get_meta('_wc_shipment_tracking_items') ?: null,
            'created_via'      => $order->get_created_via(),
        ];

        $this->send_to_api('orders', $data, 'POST');
    }

    /**
     * WP-Cron callback voor nwws_track_conversion_bg.
     * Haalt de order op en verstuurt het conversie-event naar Neuramerce.
     */
    public function run_track_conversion_bg(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $this->track_conversion($order);
    }

    private function track_conversion(\WC_Order $order): void {
        $this->send_to_api('conversions', [
            'event_name'     => 'Purchase',
            'order_id'       => $order->get_id(),
            'value'          => $order->get_total(),
            'currency'       => $order->get_currency(),
            'customer_email' => $order->get_billing_email(),
            'fbclid'         => $order->get_meta('_fbclid'),
            'timestamp'      => current_time('timestamp'),
        ], 'POST');
    }

    // =========================================================================
    // REST API ENDPOINTS
    // =========================================================================

    public function register_rest_routes(): void {
        $opts = ['permission_callback' => [$this, 'rest_permission_check']];

        register_rest_route('nwws/v1', '/products', array_merge($opts, ['methods' => 'GET', 'callback' => [$this, 'rest_get_products']]));
        register_rest_route('nwws/v1', '/orders',   array_merge($opts, ['methods' => 'GET', 'callback' => [$this, 'rest_get_orders']]));
        register_rest_route('nwws/v1', '/stats',    array_merge($opts, ['methods' => 'GET', 'callback' => [$this, 'rest_get_stats']]));

        // Settings endpoint — authenticated by WP admin (Application Password)
        register_rest_route('nwws/v1', '/settings', [
            ['methods' => 'GET',  'callback' => [$this, 'rest_get_settings'],    'permission_callback' => [$this, 'rest_admin_check']],
            ['methods' => 'POST', 'callback' => [$this, 'rest_update_settings'], 'permission_callback' => [$this, 'rest_admin_check']],
        ]);
    }

    public function rest_admin_check(): bool {
        return current_user_can('manage_woocommerce');
    }

    public function rest_get_settings(): \WP_REST_Response {
        return rest_ensure_response([
            'api_url'               => get_option('nwws_api_url', ''),
            'api_key'               => get_option('nwws_api_key', '') ? '***' : '',
            'push_url'              => get_option('nwws_push_url', ''),
            'push_key'              => get_option('nwws_push_key', '') ? '***' : '',
            'sync_enabled'          => get_option('nwws_sync_enabled', '0') === '1',
            'sync_products'         => get_option('nwws_sync_products', '1') === '1',
            'sync_orders'           => get_option('nwws_sync_orders', '1') === '1',
            'sync_customers'        => get_option('nwws_sync_customers', '1') === '1',
            'track_conversions'     => get_option('nwws_track_conversions', '1') === '1',
            'sync_fields_prices'    => get_option('nwws_sync_fields_prices', '1') === '1',
            'sync_fields_cogs'      => get_option('nwws_sync_fields_cogs', '1') === '1',
            'sync_fields_stock'     => get_option('nwws_sync_fields_stock', '1') === '1',
            'sync_fields_ean'       => get_option('nwws_sync_fields_ean', '1') === '1',
            'sync_fields_brand'     => get_option('nwws_sync_fields_brand', '1') === '1',
            'sync_fields_color'     => get_option('nwws_sync_fields_color', '1') === '1',
            'sync_fields_size'      => get_option('nwws_sync_fields_size', '1') === '1',
            'sync_fields_categories'    => get_option('nwws_sync_fields_categories', '1') === '1',
            'ai_expose_order_status'    => get_option('nwws_ai_expose_order_status',  '1') === '1',
            'ai_expose_customer_data'   => get_option('nwws_ai_expose_customer_data', '1') === '1',
            'ai_expose_cart_contents'   => get_option('nwws_ai_expose_cart_contents', '0') === '1',
            'version'                   => NWWS_VERSION,
        ]);
    }

    public function rest_update_settings(\WP_REST_Request $request): \WP_REST_Response {
        $body = $request->get_json_params();

        $map = [
            'api_url'                => 'nwws_api_url',
            'api_key'                => 'nwws_api_key',
            'push_url'               => 'nwws_push_url',
            'push_key'               => 'nwws_push_key',
            'sync_enabled'           => 'nwws_sync_enabled',
            'sync_products'          => 'nwws_sync_products',
            'sync_orders'            => 'nwws_sync_orders',
            'sync_customers'         => 'nwws_sync_customers',
            'track_conversions'      => 'nwws_track_conversions',
            'sync_fields_prices'     => 'nwws_sync_fields_prices',
            'sync_fields_cogs'       => 'nwws_sync_fields_cogs',
            'sync_fields_stock'      => 'nwws_sync_fields_stock',
            'sync_fields_ean'        => 'nwws_sync_fields_ean',
            'sync_fields_brand'      => 'nwws_sync_fields_brand',
            'sync_fields_color'      => 'nwws_sync_fields_color',
            'sync_fields_size'       => 'nwws_sync_fields_size',
            'sync_fields_categories'  => 'nwws_sync_fields_categories',
            'ai_expose_order_status'  => 'nwws_ai_expose_order_status',
            'ai_expose_customer_data' => 'nwws_ai_expose_customer_data',
            'ai_expose_cart_contents' => 'nwws_ai_expose_cart_contents',
        ];

        foreach ($map as $key => $option) {
            if (!array_key_exists($key, $body)) continue;
            $val = $body[$key];
            // Booleans → '1'/'0'
            if (is_bool($val)) $val = $val ? '1' : '0';
            update_option($option, sanitize_text_field((string) $val));
        }

        return $this->rest_get_settings();
    }

    public function rest_permission_check(\WP_REST_Request $request): bool {
        $api_key    = $request->get_header('X-API-Key');
        $stored_key = get_option('nwws_api_key', '');
        return !empty($api_key) && !empty($stored_key) && hash_equals($stored_key, $api_key);
    }

    public function rest_get_products(\WP_REST_Request $request): \WP_REST_Response {
        if (!class_exists('WooCommerce')) {
            return new \WP_REST_Response(['error' => 'WooCommerce niet actief'], 400);
        }
        $products = wc_get_products([
            'limit'  => $request->get_param('per_page') ?: 50,
            'page'   => $request->get_param('page') ?: 1,
            'status' => 'publish',
        ]);

        // Prime meta cache -> eliminates N+1 queries for cogs/cogs_currency
        $product_ids = array_map(fn($p) => $p->get_id(), $products);
        if (!empty($product_ids)) update_meta_cache('post', $product_ids);

        $data = array_map(function ($product) {
            return [
                'id'            => $product->get_id(),
                'name'          => $product->get_name(),
                'sku'           => $product->get_sku(),
                'price'         => $product->get_price(),
                'cogs'          => get_post_meta($product->get_id(), '_cogs', true),
                'cogs_currency' => get_post_meta($product->get_id(), '_cogs_currency', true) ?: 'EUR',
            ];
        }, $products);

        return rest_ensure_response($data);
    }

    public function rest_get_orders(\WP_REST_Request $request): \WP_REST_Response {
        if (!class_exists('WooCommerce')) {
            return new \WP_REST_Response(['error' => 'WooCommerce niet actief'], 400);
        }
        $orders = wc_get_orders([
            'limit' => $request->get_param('per_page') ?: 50,
            'page'  => $request->get_param('page') ?: 1,
        ]);

        $data = array_map(function ($order) {
            return [
                'id'     => $order->get_id(),
                'total'  => $order->get_total(),
                'status' => $order->get_status(),
                'date'   => $order->get_date_created()->format('Y-m-d H:i:s'),
            ];
        }, $orders);

        return rest_ensure_response($data);
    }

    private function is_hpos_enabled(): bool {
        return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public function rest_get_stats(\WP_REST_Request $request): \WP_REST_Response {
        if (!class_exists('WooCommerce')) {
            return new \WP_REST_Response(['error' => 'WooCommerce niet actief'], 400);
        }
        global $wpdb;
        if ($this->is_hpos_enabled()) {
            $total_revenue = $wpdb->get_var("SELECT SUM(total_amount) FROM {$wpdb->prefix}wc_orders WHERE status IN ('wc-completed','wc-processing') AND type = 'shop_order'");
        } else {
            $total_revenue = $wpdb->get_var("SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_order_total'");
        }
        return rest_ensure_response([
            'total_products'      => wp_count_posts('product')->publish,
            'total_orders'        => wc_orders_count('completed'),
            'total_revenue'       => (float) ($total_revenue ?? 0),
            'products_with_cogs'  => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_cogs' AND meta_value != ''"),
        ]);
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: verwijder alle Neuramerce verbindingsgegevens uit WordPress.
     * Simuleert een "ontkoppelen" — de plugin is daarna klaar voor een nieuwe koppeling.
     */
    public function ajax_disconnect(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
            return;
        }
        $this->clear_connection_options();
        wp_send_json_success('Ontkoppeld. Koppel de site opnieuw via Neuramerce → Instellingen → Koppelingen.');
    }

    public function ajax_test_connection(): void {
        check_ajax_referer('nwws_nonce', 'nonce');

        $api_key = get_option('nwws_api_key', '');
        $conn_id = get_option('nwws_connection_id', '');

        if (empty($api_key) || empty($conn_id)) {
            wp_send_json_error('API Key en Connection ID zijn beide vereist voor de verbindingstest.');
            return;
        }

        // Wis transient zodat we vers ophalen (niet geblokkeerd door cache)
        delete_transient('nwws_config_synced');

        $status = $this->do_fetch_workspace_config($api_key, $conn_id);

        switch ($status) {
            case 'ok_configured':
                $inbox_key = get_option('nwws_chat_inbox_key', '');
                wp_send_json_success('✓ Verbinding OK — chat widget geconfigureerd (inbox: ' . esc_html(substr($inbox_key, 0, 8)) . '…).');
                break;
            case 'ok_no_inbox':
                wp_send_json_success('✓ API verbinding OK — maar geen Inbox Key beschikbaar. Activeer de Inbox module in Neuramerce (Inbox → Instellingen → Widget).');
                break;
            case 'error_401':
                wp_send_json_error('Ongeldige API Key — kopieer de sleutel opnieuw via Neuramerce → Instellingen → Koppelingen → WordPress.');
                break;
            case 'error_404':
                wp_send_json_error('Connection ID niet gevonden — voeg de site opnieuw toe via Neuramerce → Instellingen → Koppelingen.');
                break;
            case 'error_network':
                wp_send_json_error('Kan Neuramerce niet bereiken — controleer je internetverbinding of probeer later opnieuw.');
                break;
            default:
                wp_send_json_error('Verbinding mislukt (' . esc_html($status) . ') — probeer later opnieuw.');
        }
    }

    /**
     * AJAX: voer een ruwe diagnostische HTTP call uit naar de workspace-config endpoint.
     * Geeft de exacte URL, headers, HTTP status code en response body terug.
     * Alleen toegankelijk voor beheerders.
     */
    public function ajax_diagnostics(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang.');
            return;
        }

        $api_key = get_option('nwws_api_key', '');
        $conn_id = get_option('nwws_connection_id', '');
        $api_url = get_option('nwws_api_url', 'https://app.neuramerce.com/api');
        $inbox   = get_option('nwws_chat_inbox_key', '');
        $token   = get_option('nwws_tracking_token', '');
        $status  = get_option('nwws_config_fetch_status', '(niet opgeslagen)');
        $transient_active = (bool) get_transient('nwws_config_synced');

        // Wis transient zodat we een verse call doen
        delete_transient('nwws_config_synced');

        $config_url = rtrim($api_url, '/') . '/v1/woocommerce/workspace-config';
        $request_url = add_query_arg('connectionId', rawurlencode($conn_id), $config_url);

        $response = wp_remote_get($request_url, [
            'headers'   => ['X-Neuramerce-Plugin-Key' => $api_key],
            'timeout'   => 10,
            'sslverify' => true,
        ]);

        $report = [
            'stored' => [
                'api_url'      => $api_url,
                'api_key'      => $api_key ? (substr($api_key, 0, 8) . '…(' . strlen($api_key) . ' chars)') : '(leeg)',
                'conn_id'      => $conn_id ?: '(leeg)',
                'inbox_key'    => $inbox   ? (substr($inbox, 0, 8) . '…') : '(leeg)',
                'tracking'     => $token   ? (substr($token, 0, 8) . '…') : '(leeg)',
                'fetch_status' => $status,
                'transient_was_active' => $transient_active,
            ],
            'request' => [
                'url'    => $request_url,
                'method' => 'GET',
                'header' => 'X-Neuramerce-Plugin-Key: ' . (substr($api_key, 0, 8) . '…'),
            ],
        ];

        if (is_wp_error($response)) {
            $report['response'] = ['error' => $response->get_error_message()];
            update_option('nwws_config_fetch_status', 'error_network');
            set_transient('nwws_config_synced', '1', 2 * MINUTE_IN_SECONDS);
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $report['response'] = [
                'http_status' => $code,
                'body'        => substr($body, 0, 500),
            ];

            if ($code === 200) {
                $parsed = json_decode($body, true);
                $report['parsed'] = [
                    'inboxKey'      => $parsed['inboxKey'] ?? null,
                    'trackingToken' => isset($parsed['trackingToken']) ? substr((string)$parsed['trackingToken'], 0, 8) . '…' : null,
                    'connectionId'  => $parsed['connectionId'] ?? null,
                ];
                if (!empty($parsed['inboxKey'])) {
                    update_option('nwws_chat_inbox_key', sanitize_text_field($parsed['inboxKey']));
                    update_option('nwws_config_fetch_status', 'ok_configured');
                } else {
                    update_option('nwws_config_fetch_status', 'ok_no_inbox');
                }
                if (!empty($parsed['trackingToken'])) {
                    update_option('nwws_tracking_token', sanitize_text_field($parsed['trackingToken']));
                }
                set_transient('nwws_config_synced', '1', HOUR_IN_SECONDS);
            }

            error_log('[NWWS Diagnostics] HTTP ' . $code . ' from ' . $request_url . ' — body: ' . substr($body, 0, 200));
        }

        wp_send_json_success($report);
    }

    public function ajax_sync_all_products(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        set_time_limit(300);

        $page = 1; $batch = 50; $count = 0;
        do {
            $products = wc_get_products(['limit' => $batch, 'page' => $page, 'status' => 'publish']);
            foreach ($products as $product) {
                $this->sync_product($product->get_id());
                $count++;
            }
            $page++;
        } while (count($products) === $batch);

        $this->append_sync_log('Producten', $count, []);
        wp_send_json_success($count . ' producten gesynchroniseerd.');
    }

    public function ajax_sync_all_orders(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        set_time_limit(300);

        $page = 1; $batch = 50; $count = 0;
        do {
            $orders = wc_get_orders(['limit' => $batch, 'page' => $page]);
            foreach ($orders as $order) {
                $this->sync_order($order->get_id());
                $count++;
            }
            $page++;
        } while (count($orders) === $batch);

        $this->append_sync_log('Orders', $count, []);
        wp_send_json_success($count . ' orders gesynchroniseerd.');
    }

    /**
     * Voeg een sync-actie toe aan het logboek (max. 50 entries, FIFO).
     *
     * @param string   $type   Leesbaar type, bijv. 'Producten' of 'Orders'.
     * @param int      $count  Aantal verwerkte items.
     * @param string[] $errors Lijst van foutmeldingen (leeg = geslaagd).
     */
    private function append_sync_log(string $type, int $count, array $errors): void {
        $raw     = get_option('nwws_sync_log', '[]');
        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'timestamp' => time(),
            'type'      => $type,
            'count'     => $count,
            'errors'    => $errors,
        ];

        // Bewaar maximaal 50 entries
        if (count($entries) > 50) {
            $entries = array_slice($entries, -50);
        }

        update_option('nwws_sync_log', (string) wp_json_encode($entries));
    }

    public function ajax_test_push(): void {
        check_ajax_referer('nwws_nonce', 'nonce');

        $push_url = get_option('nwws_push_url', '');
        $push_key = get_option('nwws_push_key', '');

        if (empty($push_url) || empty($push_key)) {
            wp_send_json_error('Webhook URL en API Key zijn vereist.');
            return;
        }

        $response = wp_remote_get($push_url, [
            'headers' => ['X-API-Key' => $push_key],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbinding mislukt: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success('Neuramerce webhook bereikbaar!');
        } else {
            wp_send_json_error('Verbinding mislukt (HTTP ' . $code . ')');
        }
    }

    public function ajax_get_sync_stats(): void {
        check_ajax_referer('nwws_nonce', 'nonce');

        if (!class_exists('WooCommerce')) {
            wp_send_json_error('WooCommerce niet actief.');
            return;
        }

        global $wpdb;

        if ($this->is_hpos_enabled()) {
            $orders_synced = (int) $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_nwws_last_sync'");
        } else {
            $orders_synced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_nwws_last_sync' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order')");
        }

        $product_counts = wp_count_posts('product');

        wp_send_json_success([
            'total_products'     => isset($product_counts->publish) ? (int) $product_counts->publish : 0,
            'products_synced'    => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_nwws_last_sync'"),
            'products_with_cogs' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_cogs' AND meta_value != ''"),
            'total_orders'       => wc_orders_count('all'),
            'orders_synced'      => $orders_synced,
        ]);
    }

    // =========================================================================
    // API COMMUNICATION
    // =========================================================================

    private function send_to_api(string $endpoint, array $data, string $method = 'POST'): bool {
        $api_url = get_option('nwws_api_url', '');
        // nwws_push_key is the HMAC-derived key validated by the Next.js app.
        // nwws_api_key is a legacy field; prefer push_key with fallback.
        $api_key = get_option('nwws_push_key', '') ?: get_option('nwws_api_key', '');

        if (empty($api_url) || empty($api_key)) return false;

        // Non-blocking: fire-and-forget so checkout/save actions are never delayed.
        wp_remote_request(trailingslashit($api_url) . $endpoint, [
            'method'   => $method,
            'headers'  => ['Content-Type' => 'application/json', 'X-API-Key' => $api_key],
            'body'     => wp_json_encode($data),
            'timeout'  => 5,
            'blocking' => false,
        ]);

        return true;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SHIPPING — dynamische verzenddag op productpagina (v1.10.0)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Bereken eerstvolgende verzenddag — exact dezelfde logica als
     * `src/lib/shipping/calculate-next-ship-day.ts` zodat presence-sites
     * en WC-shops identieke uitkomsten geven.
     *
     * @param array              $week_config  ['mon' => ['ships'=>bool, 'cutoff'=>'HH:MM'|null], ...]
     * @param array              $exceptions   [['date'=>'YYYY-MM-DD', 'type'=>'no_shipping'|'shipping_day'], ...]
     * @param DateTimeImmutable  $now          Huidige datum/tijd in WP-timezone
     * @return array  ['date' => DateTimeImmutable|null, 'is_today' => bool, 'cutoff' => string|null, 'cutoff_passed' => bool]
     */
    public function nwws_calculate_next_ship_day(array $week_config, array $exceptions, DateTimeImmutable $now): array {
        $day_keys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        // Index excepties op date-key (Y-m-d)
        $ex_map = [];
        foreach ($exceptions as $ex) {
            if (!empty($ex['date']) && !empty($ex['type'])) {
                $ex_map[$ex['date']] = $ex['type'];
            }
        }

        $today_date_key = $now->format('Y-m-d');
        $today_day_idx  = (int) $now->format('w'); // 0=sunday
        $today_key      = $day_keys[$today_day_idx];

        $today_cfg               = $week_config[$today_key] ?? ['ships' => false];
        $today_exception         = $ex_map[$today_date_key] ?? null;
        $today_ships_by_config   = !empty($today_cfg['ships']);
        $today_ships_by_exc      = $today_exception === 'shipping_day';
        $today_blocked_by_exc    = $today_exception === 'no_shipping';
        $today_ships_effective   = ($today_ships_by_config || $today_ships_by_exc) && !$today_blocked_by_exc;
        $today_cutoff            = $today_ships_by_config ? ($today_cfg['cutoff'] ?? null) : null;

        $cutoff_passed = false;
        if ($today_cutoff && preg_match('/^(\d{2}):(\d{2})$/', $today_cutoff, $m)) {
            $cutoff_mins = ((int)$m[1]) * 60 + (int)$m[2];
            $now_mins    = ((int)$now->format('H')) * 60 + (int)$now->format('i');
            $cutoff_passed = $now_mins >= $cutoff_mins;
        }

        // 1. Vandaag verzenden?
        if ($today_ships_effective && !$cutoff_passed) {
            return [
                'date'           => $now->setTime(0, 0, 0),
                'is_today'       => true,
                'cutoff'         => $today_cutoff,
                'cutoff_passed'  => false,
            ];
        }

        // 2. Loop max 14 dagen forward
        for ($i = 1; $i <= 14; $i++) {
            $cand = $now->modify("+{$i} days")->setTime(0, 0, 0);
            $cand_key       = $day_keys[(int) $cand->format('w')];
            $cand_date_key  = $cand->format('Y-m-d');
            $cand_exception = $ex_map[$cand_date_key] ?? null;
            $cand_ships     = !empty($week_config[$cand_key]['ships']);
            $cand_by_exc    = $cand_exception === 'shipping_day';
            $cand_blocked   = $cand_exception === 'no_shipping';

            if (($cand_ships || $cand_by_exc) && !$cand_blocked) {
                return [
                    'date'           => $cand,
                    'is_today'       => false,
                    'cutoff'         => $today_cutoff,
                    'cutoff_passed'  => $cutoff_passed,
                ];
            }
        }

        return [
            'date'           => null,
            'is_today'       => false,
            'cutoff'         => $today_cutoff,
            'cutoff_passed'  => $cutoff_passed,
        ];
    }

    /**
     * Render het verzendregeltje onder de productprijs. Hook:
     * `woocommerce_single_product_summary` priority 25.
     */
    public function nwws_render_shipping_info(): void {
        global $product;
        if (!$product || !function_exists('wc_get_product')) return;

        $default    = get_option('nwws_shipping_default', null);
        $overrides  = get_option('nwws_shipping_overrides', []);
        $exceptions = get_option('nwws_shipping_exceptions', []);

        if (!is_array($default) || empty($default['week_config'])) return;

        // Match categorie-override: pak de eerste matching categorie van het product
        $week_config = $default['week_config'];
        $message_tpl = $default['message_tpl'] ?? 'Vóór [cutoff] besteld? [verzenddag] verzonden.';

        $product_cat_terms = get_the_terms($product->get_id(), 'product_cat');
        if (is_array($overrides) && is_array($product_cat_terms)) {
            foreach ($product_cat_terms as $term) {
                foreach ($overrides as $ov) {
                    if (!empty($ov['category_slug']) && $ov['category_slug'] === $term->slug && !empty($ov['week_config'])) {
                        $week_config = $ov['week_config'];
                        if (!empty($ov['message_tpl'])) $message_tpl = $ov['message_tpl'];
                        break 2;
                    }
                }
            }
        }

        try {
            $tz  = wp_timezone();
            $now = new DateTimeImmutable('now', $tz);
        } catch (Exception $e) {
            return;
        }

        $result = $this->nwws_calculate_next_ship_day($week_config, is_array($exceptions) ? $exceptions : [], $now);
        if (empty($result['date'])) return;

        // Render template
        $weekdays = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
        $verzenddag = $result['is_today'] ? 'vandaag' : $weekdays[(int) $result['date']->format('w')];
        $datum_fmt = wp_date('j F Y', $result['date']->getTimestamp());
        $cutoff = $result['cutoff'] ?? '';

        $message = $message_tpl;
        $message = str_ireplace('[verzenddag]', $verzenddag, $message);
        $message = str_ireplace('[datum]',      $datum_fmt,  $message);
        $message = str_ireplace('[cutoff]',     $cutoff,     $message);

        echo '<p class="nwws-shipping-info" style="margin:0.5em 0;color:#555;font-size:0.9em;">' . esc_html($message) . '</p>';
    }
}

// Boot
function nwws_init(): Neura_WooCommerce_Sync {
    return Neura_WooCommerce_Sync::instance();
}
add_action('plugins_loaded', 'nwws_init');

// Auto-updater (runs after plugins_loaded so WP is ready)
add_action('plugins_loaded', function () {
    new Neura_GitHub_Updater(NWWS_PLUGIN_FILE, NWWS_VERSION, NWWS_GITHUB_OWNER, NWWS_GITHUB_REPO);
}, 20);

// Activation defaults + cron schedule
register_activation_hook(__FILE__, function () {
    add_option('nwws_sync_enabled',           '0');
    add_option('nwws_sync_products',          '1');
    add_option('nwws_sync_orders',            '1');
    add_option('nwws_sync_customers',         '1');
    add_option('nwws_track_conversions',      '1');
    add_option('nwws_push_url',               '');
    add_option('nwws_push_key',               '');
    add_option('nwws_sync_fields_prices',     '1');
    add_option('nwws_sync_fields_cogs',       '1');
    add_option('nwws_sync_fields_stock',      '1');
    add_option('nwws_sync_fields_ean',        '1');
    add_option('nwws_sync_fields_brand',      '1');
    add_option('nwws_sync_fields_color',      '1');
    add_option('nwws_sync_fields_size',       '1');
    add_option('nwws_sync_fields_categories', '1');
    add_option('nwws_chat_enabled',           '0');
    add_option('nwws_chat_inbox_key',         '');

    if (!wp_next_scheduled('nwws_poll_sync')) {
        wp_schedule_event(time(), 'nwws_every_minute', 'nwws_poll_sync');
    }
});

// =============================================================================
// NEURAMERCE SYNC POLLING (WP Cron — elke minuut)
//
// Omdat Railway's servers geblokkeerd worden door Cloudflare op nomadfire.shop,
// draait de sync omgekeerd: de WP plugin pollt Neuramerce voor sync-aanvragen.
// Stroom:
//   1. Dashboard "Nu synchroniseren" → POST /api/woocommerce/request-sync
//   2. Plugin pollt GET /api/v1/inventory/woocommerce/sync-check elke minuut
//   3. Als syncRequested=true: plugin haalt WC-data op en pusht naar Neuramerce
//   4. Plugin meldt klaar via POST /api/v1/inventory/woocommerce/push {type:'done'}
// =============================================================================

// Elke-minuut schedule registreren
add_filter('cron_schedules', function (array $schedules): array {
    if (!isset($schedules['nwws_every_minute'])) {
        $schedules['nwws_every_minute'] = [
            'interval' => 60,
            'display'  => 'Elke minuut (Neuramerce sync check)',
        ];
    }
    return $schedules;
});

// Cron event verwijderen bij deactivatie
register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('nwws_poll_sync');
    if ($timestamp) wp_unschedule_event($timestamp, 'nwws_poll_sync');
});

// Cron-callback: check of Neuramerce een sync wil
add_action('nwws_poll_sync', 'nwws_run_poll_sync');

function nwws_run_poll_sync(): void {
    $push_url  = get_option('nwws_push_url', '');  // webhook base URL (bijv. https://app.neuramerce.com/api/v1/inventory/woocommerce)
    $push_key  = get_option('nwws_push_key', '');
    $workspace = get_option('nwws_workspace_id', '');

    if (empty($push_url) || empty($push_key) || empty($workspace)) return;

    // Leid de base URL af (bijv. https://app.neuramerce.com)
    $app_base = rtrim(preg_replace('#/api/v1/inventory/woocommerce.*#', '', $push_url), '/');
    if (empty($app_base)) return;

    // 1. Check of er een sync aangevraagd is
    $check_url = $app_base . '/api/v1/inventory/woocommerce/sync-check?workspace=' . urlencode($workspace);
    $response  = wp_remote_get($check_url, [
        'headers' => ['X-API-Key' => $push_key],
        'timeout' => 10,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['syncRequested'])) return;

    // 2. Sync uitvoeren
    $push_endpoint = $app_base . '/api/v1/inventory/woocommerce/push?workspace=' . urlencode($workspace);
    $products_count = 0;
    $orders_count   = 0;

    // 2a. Producten ophalen en pushen in batches van 50
    if (get_option('nwws_sync_products', '1') === '1') {
        $page = 1; $batch = 50;
        do {
            $products = wc_get_products([
                'limit'  => $batch,
                'page'   => $page,
                'status' => 'publish',
                'return' => 'objects',
            ]);

            if (empty($products)) break;

            $payload = [];
            foreach ($products as $product) {
                $attrs = [];
                foreach ($product->get_attributes() as $attr_key => $attr) {
                    $attrs[] = [
                        'name'    => wc_attribute_label($attr_key),
                        'options' => $attr->get_options() ?: [$attr->get_option()],
                    ];
                }
                $payload[] = [
                    'id'            => $product->get_id(),
                    'sku'           => $product->get_sku(),
                    'name'          => $product->get_name(),
                    'status'        => $product->get_status(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price'    => $product->get_sale_price(),
                    'permalink'     => get_permalink($product->get_id()),
                    'images'        => array_values(array_map(
                        fn($img_id) => ['src' => wp_get_attachment_url($img_id)],
                        array_filter([$product->get_image_id()])
                    )),
                    'categories'    => array_values(array_map(
                        fn($term) => ['name' => $term->name],
                        get_the_terms($product->get_id(), 'product_cat') ?: []
                    )),
                    'attributes'    => $attrs,
                    'meta_data'     => [
                        ['key' => '_cogs',          'value' => get_post_meta($product->get_id(), '_cogs', true)],
                        ['key' => '_cogs_currency', 'value' => get_post_meta($product->get_id(), '_cogs_currency', true)],
                        ['key' => '_ean',           'value' => get_post_meta($product->get_id(), '_ean', true)],
                    ],
                ];
            }

            wp_remote_post($push_endpoint, [
                'headers'     => ['Content-Type' => 'application/json', 'X-API-Key' => $push_key],
                'body'        => wp_json_encode(['type' => 'products', 'products' => $payload]),
                'timeout'     => 30,
                'data_format' => 'body',
            ]);

            $products_count += count($products);
            $page++;
        } while (count($products) === $batch);
    }

    // 2b. Orders ophalen en pushen in batches van 50
    if (get_option('nwws_sync_orders', '1') === '1') {
        $page = 1; $batch = 50;
        do {
            $orders = wc_get_orders(['limit' => $batch, 'page' => $page, 'return' => 'objects']);
            if (empty($orders)) break;

            $payload = [];
            foreach ($orders as $order) {
                $items = [];
                foreach ($order->get_items() as $item) {
                    $items[] = [
                        'sku'      => $item->get_product() ? $item->get_product()->get_sku() : '',
                        'name'     => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'price'    => $order->get_item_subtotal($item, false, true),
                    ];
                }
                $payload[] = [
                    'id'       => $order->get_id(),
                    'status'   => $order->get_status(),
                    'total'    => $order->get_total(),
                    'billing'  => [
                        'first_name' => $order->get_billing_first_name(),
                        'last_name'  => $order->get_billing_last_name(),
                        'email'      => $order->get_billing_email(),
                        'country'    => $order->get_billing_country(),
                    ],
                    'shipping' => ['country' => $order->get_shipping_country()],
                    'line_items' => $items,
                ];
            }

            wp_remote_post($push_endpoint, [
                'headers'     => ['Content-Type' => 'application/json', 'X-API-Key' => $push_key],
                'body'        => wp_json_encode(['type' => 'orders', 'orders' => $payload]),
                'timeout'     => 30,
                'data_format' => 'body',
            ]);

            $orders_count += count($orders);
            $page++;
        } while (count($orders) === $batch);
    }

    // 3. Sync afmelden bij Neuramerce
    wp_remote_post($push_endpoint, [
        'headers'     => ['Content-Type' => 'application/json', 'X-API-Key' => $push_key],
        'body'        => wp_json_encode([
            'type'  => 'done',
            'stats' => ['products' => $products_count, 'orders' => $orders_count],
        ]),
        'timeout'     => 10,
        'data_format' => 'body',
    ]);
}

// Uninstall cleanup
register_uninstall_hook(__FILE__, 'nwws_uninstall');

function nwws_uninstall(): void {
    $options = [
        'nwws_sync_enabled', 'nwws_sync_products', 'nwws_sync_orders',
        'nwws_sync_customers', 'nwws_track_conversions',
        'nwws_api_url', 'nwws_api_key', 'nwws_push_url', 'nwws_push_key',
        'nwws_sync_fields_prices', 'nwws_sync_fields_cogs', 'nwws_sync_fields_stock',
        'nwws_sync_fields_ean', 'nwws_sync_fields_brand', 'nwws_sync_fields_color',
        'nwws_sync_fields_size', 'nwws_sync_fields_categories',
    ];
    foreach ($options as $opt) delete_option($opt);
    // Remove post meta added by this plugin
    delete_post_meta_by_key('_nwws_last_sync');
    delete_post_meta_by_key('_cogs');
    delete_post_meta_by_key('_cogs_currency');
}

<?php
/**
 * Plugin Name:  Neura WooCommerce Sync
 * Plugin URI:   https://github.com/DaalderConcepts/neura-wp-woo-sync
 * Description:  Synchroniseert WooCommerce data (producten, orders, klanten, COGS) met Neuramerce voor accurate ROAS tracking en conversie-optimalisatie.
 * Version:      1.3.10
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

define('NWWS_VERSION',    '1.3.10');
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
    // Register Migrator API routes even without WooCommerce
    add_action('rest_api_init', ['NWWS_Migrator_API', 'register_routes']);
    add_action('admin_notices', function () {
        echo '<div class="notice notice-warning"><p><strong>Neura WooCommerce Sync</strong>: WooCommerce niet gevonden — WooCommerce sync is uitgeschakeld. De Migrator API is wel beschikbaar.</p></div>';
    });
    return;
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
        $cache_key = 'nwws_github_release';
        $cached    = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases/latest",
            ['headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo('version')], 'timeout' => 15]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['tag_name'])) return null;

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

        set_transient($cache_key, $release, 6 * HOUR_IN_SECONDS);
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

        // COGS custom fields
        add_action('woocommerce_product_options_pricing',  [$this, 'add_cogs_field']);
        add_action('woocommerce_process_product_meta',     [$this, 'save_cogs_field']);
        add_action('woocommerce_variation_options_pricing',[$this, 'add_variation_cogs_field'], 10, 3);
        add_action('woocommerce_save_product_variation',   [$this, 'save_variation_cogs_field'], 10, 2);

        // Frontend chat widget + cart tracking
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('rest_api_init', ['NWWS_Migrator_API', 'register_routes']);

        // Background order/product sync (WP-Cron callback — MUST be registered or cron fires silently)
        add_action('nwws_sync_order_bg',   [$this, 'sync_order'],   10, 1);
        add_action('nwws_sync_product_bg', [$this, 'sync_product'], 10, 1);

        // AJAX
        add_action('wp_ajax_nwws_test_connection',    [$this, 'ajax_test_connection']);
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
        add_submenu_page(
            'woocommerce',
            'Neura WooCommerce Sync',
            'Neura Sync',
            'manage_woocommerce',
            'neura-wp-woo-sync',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'woocommerce_page_neura-wp-woo-sync') return;

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

        // Base URL: strip /api/... suffix from nwws_api_url if present, or use fallback
        $raw_url  = get_option('nwws_api_url', '');
        $base_url = $raw_url
            ? rtrim(preg_replace('#/api/.*$#', '', $raw_url), '/')
            : 'https://app.neuramerce.com';

        // Current cart data (safe for JS output)
        $cart_items = [];
        $cart_total = 0.0;
        if (function_exists('WC') && WC()->cart && !is_admin()) {
            foreach (WC()->cart->get_cart() as $item) {
                $product     = $item['data'] ?? null;
                $cart_items[] = [
                    'product_id' => (int) $item['product_id'],
                    'name'       => $product ? wp_strip_all_tags($product->get_name()) : '',
                    'quantity'   => (int) $item['quantity'],
                    'price'      => $product ? (float) wc_get_price_including_tax($product) : 0.0,
                ];
            }
            $cart_total = (float) WC()->cart->get_cart_contents_total();
        }

        // Register a dummy script handle so we can attach inline JS
        wp_register_script('nwws-chat', false, [], NWWS_VERSION, true);
        wp_enqueue_script('nwws-chat');

        $inbox_key_js = wp_json_encode($inbox_key);
        $base_url_js  = wp_json_encode($base_url);
        $cart_js      = wp_json_encode(['items' => $cart_items, 'total' => $cart_total]);

        $inline = <<<JS
(function () {
  var inboxKey = {$inbox_key_js};
  var base     = {$base_url_js};
  var cart     = {$cart_js};

  // ── Inject widget loader ──────────────────────────────────────────────────
  var s = document.createElement('script');
  s.src   = base + '/widget-loader.js?key=' + encodeURIComponent(inboxKey);
  s.defer = true;
  document.body.appendChild(s);

  // ── Cart tracking helper ──────────────────────────────────────────────────
  function sendCartUpdate(cartData) {
    var fp = '';
    try { fp = localStorage.getItem('nmrc_fp') || ''; } catch (e) {}
    if (!fp) {
      // Fingerprint not yet set — retry once after widget has initialised
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

  // ── Send initial cart state after widget loads ────────────────────────────
  s.addEventListener('load', function () {
    if (cart.items.length > 0 || cart.total > 0) {
      setTimeout(function () { sendCartUpdate(cart); }, 1200);
    }
  });

  // ── Listen for live WooCommerce cart events ───────────────────────────────
  // WooCommerce fires these jQuery events on document.body
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof jQuery === 'undefined') return;

    jQuery(document.body).on(
      'added_to_cart removed_from_cart updated_cart_totals wc_fragments_refreshed',
      function () {
        // Fetch fresh cart from WooCommerce AJAX endpoint
        var ajaxUrl = (typeof wc_cart_fragments_params !== 'undefined')
          ? wc_cart_fragments_params.wc_ajax_url
          : (typeof woocommerce_params !== 'undefined' ? woocommerce_params.ajax_url : '/');

        if (!ajaxUrl || ajaxUrl === '/') return;

        jQuery.post(
          ajaxUrl.replace('%%endpoint%%', 'get_refreshed_fragments'),
          {},
          function (data) {
            if (data && data.cart_hash !== undefined) {
              // Re-fetch cart totals via a separate lightweight endpoint
              fetch(base + '/api/v1/inbox/widget-cart?inboxKey=' + encodeURIComponent(inboxKey), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Pass WC session cookie automatically (same origin if widget is on same domain)
              }).then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d && d.cart) sendCartUpdate(d.cart); })
                .catch(function () {
                  // Fallback: send total from page if available
                  var totalEl = document.querySelector('.cart-subtotal .woocommerce-Price-amount');
                  var totalText = totalEl ? totalEl.textContent.replace(/[^0-9,.]/g, '').replace(',', '.') : '0';
                  sendCartUpdate({ items: [], total: parseFloat(totalText) || 0 });
                });
            }
          }
        );
      }
    );

    // Also track on add-to-cart button click (fires before AJAX completes)
    jQuery(document.body).on('click', '.add_to_cart_button, .single_add_to_cart_button', function () {
      // Brief delay so WooCommerce AJAX has finished
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

        wp_add_inline_script('nwws-chat', $inline);
    }

    public function render_settings_page(): void {
        if (isset($_POST['nwws_save_settings'])) {
            check_admin_referer('nwws_settings');

            update_option('nwws_api_url',                  sanitize_text_field($_POST['nwws_api_url']));
            update_option('nwws_api_key',                  sanitize_text_field($_POST['nwws_api_key']));
            $saved_push_url = esc_url_raw($_POST['nwws_push_url'] ?? '');
            update_option('nwws_push_url', $saved_push_url);
            update_option('nwws_push_key', sanitize_text_field($_POST['nwws_push_key'] ?? ''));
            // Auto-extract workspace ID from push URL query string (?workspace=...)
            $parsed_qs = [];
            parse_str(parse_url($saved_push_url, PHP_URL_QUERY) ?? '', $parsed_qs);
            $extracted_ws = sanitize_text_field($parsed_qs['workspace'] ?? $_POST['nwws_workspace_id'] ?? '');
            if (!empty($extracted_ws)) update_option('nwws_workspace_id', $extracted_ws);
            update_option('nwws_sync_enabled',             isset($_POST['nwws_sync_enabled'])             ? '1' : '0');
            update_option('nwws_sync_products',            isset($_POST['nwws_sync_products'])            ? '1' : '0');
            update_option('nwws_sync_orders',              isset($_POST['nwws_sync_orders'])              ? '1' : '0');
            update_option('nwws_sync_customers',           isset($_POST['nwws_sync_customers'])           ? '1' : '0');
            update_option('nwws_track_conversions',        isset($_POST['nwws_track_conversions'])        ? '1' : '0');
            update_option('nwws_sync_fields_prices',       isset($_POST['nwws_sync_fields_prices'])       ? '1' : '0');
            update_option('nwws_sync_fields_cogs',         isset($_POST['nwws_sync_fields_cogs'])         ? '1' : '0');
            update_option('nwws_sync_fields_stock',        isset($_POST['nwws_sync_fields_stock'])        ? '1' : '0');
            update_option('nwws_sync_fields_ean',          isset($_POST['nwws_sync_fields_ean'])          ? '1' : '0');
            update_option('nwws_sync_fields_categories',   isset($_POST['nwws_sync_fields_categories'])   ? '1' : '0');
            $selected_attrs = isset($_POST['nwws_sync_attr']) && is_array($_POST['nwws_sync_attr'])
                ? implode(',', array_map('sanitize_key', $_POST['nwws_sync_attr']))
                : '';
            update_option('nwws_sync_attrs', $selected_attrs);

            // Chat widget
            update_option('nwws_chat_enabled',   isset($_POST['nwws_chat_enabled'])   ? '1' : '0');
            update_option('nwws_chat_inbox_key', sanitize_text_field($_POST['nwws_chat_inbox_key'] ?? ''));

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
                $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'sync';
        $page_url   = admin_url('admin.php?page=neura-wp-woo-sync');
        ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:1.5rem">
            <a href="<?php echo esc_url($page_url . '&tab=sync'); ?>" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">Instellingen</a>
            <a href="<?php echo esc_url($page_url . '&tab=migrate'); ?>" class="nav-tab <?php echo $active_tab === 'migrate' ? 'nav-tab-active' : ''; ?>">Migratie</a>
        </nav>
        <?php
        if ($active_tab === 'sync') {
            include NWWS_PLUGIN_DIR . 'templates/settings-page.php';
        } else {
            include NWWS_PLUGIN_DIR . 'templates/migration-tab.php';
        }
    }

    // =========================================================================
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
                'variant_name' => $product ? implode(' / ', array_filter(array_map(function($attr) { return $attr->get_option(); }, $product->get_attributes()))) : null,
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
            'sync_fields_categories'=> get_option('nwws_sync_fields_categories', '1') === '1',
            'version'               => NWWS_VERSION,
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
            'sync_fields_categories' => 'nwws_sync_fields_categories',
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

    public function ajax_test_connection(): void {
        check_ajax_referer('nwws_nonce', 'nonce');

        $api_url = get_option('nwws_api_url', '');
        $api_key = get_option('nwws_api_key', '');

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error('API URL en API Key zijn vereist.');
        }

        $response = wp_remote_post($api_url . '/test', [
            'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $api_key],
            'body'    => wp_json_encode(['test' => true]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbinding mislukt: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success('Verbinding succesvol!');
        } else {
            wp_send_json_error('Verbinding mislukt (HTTP ' . $code . ')');
        }
    }

    public function ajax_sync_all_products(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        @set_time_limit(300);

        $page = 1; $batch = 50; $count = 0;
        do {
            $products = wc_get_products(['limit' => $batch, 'page' => $page, 'status' => 'publish']);
            foreach ($products as $product) {
                $this->sync_product($product->get_id());
                $count++;
            }
            $page++;
        } while (count($products) === $batch);

        wp_send_json_success($count . ' producten gesynchroniseerd.');
    }

    public function ajax_sync_all_orders(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        @set_time_limit(300);

        $page = 1; $batch = 50; $count = 0;
        do {
            $orders = wc_get_orders(['limit' => $batch, 'page' => $page]);
            foreach ($orders as $order) {
                $this->sync_order($order->get_id());
                $count++;
            }
            $page++;
        } while (count($orders) === $batch);

        wp_send_json_success($count . ' orders gesynchroniseerd.');
    }

    public function ajax_test_push(): void {
        check_ajax_referer('nwws_nonce', 'nonce');

        $push_url = get_option('nwws_push_url', '');
        $push_key = get_option('nwws_push_key', '');

        if (empty($push_url) || empty($push_key)) {
            wp_send_json_error('Webhook URL en API Key zijn vereist.');
        }

        $response = wp_remote_get($push_url, [
            'headers' => ['X-API-Key' => $push_key],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Verbinding mislukt: ' . $response->get_error_message());
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
        global $wpdb;

        if ($this->is_hpos_enabled()) {
            $orders_synced = (int) $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_nwws_last_sync'");
        } else {
            $orders_synced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_nwws_last_sync' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order')");
        }

        wp_send_json_success([
            'total_products'      => wp_count_posts('product')->publish,
            'products_synced'     => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_nwws_last_sync'"),
            'products_with_cogs'  => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_cogs' AND meta_value != ''"),
            'total_orders'        => wc_orders_count('all'),
            'orders_synced'       => $orders_synced,
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

// Activation defaults
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

// Cron event plannen bij activatie
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('nwws_poll_sync')) {
        wp_schedule_event(time(), 'nwws_every_minute', 'nwws_poll_sync');
    }
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
                $data_store = $product->get_data();
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

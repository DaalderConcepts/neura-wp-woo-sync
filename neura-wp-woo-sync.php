<?php
/**
 * Plugin Name:  Neura WooCommerce Sync
 * Plugin URI:   https://github.com/DaalderConcepts/neura-wp-woo-sync
 * Description:  Synchroniseert WooCommerce data (producten, orders, klanten, COGS) met Neuramerce voor accurate ROAS tracking en conversie-optimalisatie.
 * Version:      1.1.1
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

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>Neura WooCommerce Sync</strong> vereist WooCommerce om te werken.</p></div>';
    });
    return;
}

define('NWWS_VERSION',    '1.1.1');
define('NWWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NWWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NWWS_PLUGIN_FILE', __FILE__);
define('NWWS_GITHUB_OWNER', 'DaalderConcepts');
define('NWWS_GITHUB_REPO',  'neura-wp-woo-sync');

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

        $release = [
            'version'     => ltrim($data['tag_name'], 'v'),
            'zip_url'     => $data['zipball_url'],
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

        // GitHub zips unpack naar owner-repo-sha/, hernoem naar plugin folder
        global $wp_filesystem;
        $target = WP_PLUGIN_DIR . '/' . dirname($this->plugin_slug);
        $wp_filesystem->move($result['destination'], $target, true);
        $result['destination'] = $target;

        activate_plugin($this->plugin_slug);
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

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // AJAX
        add_action('wp_ajax_nwws_test_connection',    [$this, 'ajax_test_connection']);
        add_action('wp_ajax_nwws_sync_all_products',  [$this, 'ajax_sync_all_products']);
        add_action('wp_ajax_nwws_sync_all_orders',    [$this, 'ajax_sync_all_orders']);
        add_action('wp_ajax_nwws_get_sync_stats',     [$this, 'ajax_get_sync_stats']);
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
        ]);
    }

    public function render_settings_page(): void {
        if (isset($_POST['nwws_save_settings'])) {
            check_admin_referer('nwws_settings');

            update_option('nwws_api_url',          sanitize_text_field($_POST['nwws_api_url']));
            update_option('nwws_api_key',          sanitize_text_field($_POST['nwws_api_key']));
            update_option('nwws_sync_enabled',     isset($_POST['nwws_sync_enabled'])     ? '1' : '0');
            update_option('nwws_sync_products',    isset($_POST['nwws_sync_products'])    ? '1' : '0');
            update_option('nwws_sync_orders',      isset($_POST['nwws_sync_orders'])      ? '1' : '0');
            update_option('nwws_sync_customers',   isset($_POST['nwws_sync_customers'])   ? '1' : '0');
            update_option('nwws_track_conversions',isset($_POST['nwws_track_conversions'])? '1' : '0');

            echo '<div class="notice notice-success"><p>Instellingen opgeslagen!</p></div>';
        }

        include NWWS_PLUGIN_DIR . 'templates/settings-page.php';
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
        $this->sync_product($product_id);
    }

    public function sync_product_update(int $product_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_products') !== '1') return;
        $this->sync_product($product_id);
    }

    private function sync_product(int $product_id): void {
        $product = wc_get_product($product_id);
        if (!$product) return;

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

        if ($product->is_type('variable')) {
            $variations = [];
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variations[] = [
                        'id'            => $variation->get_id(),
                        'sku'           => $variation->get_sku(),
                        'price'         => $variation->get_price(),
                        'cogs'          => get_post_meta($variation_id, '_cogs', true),
                        'cogs_currency' => get_post_meta($variation_id, '_cogs_currency', true) ?: 'EUR',
                        'attributes'    => $variation->get_attributes(),
                    ];
                }
            }
            $data['variations'] = $variations;
        }

        $this->send_to_api('products', $data, 'POST');
        update_post_meta($product_id, '_nwws_last_sync', current_time('timestamp'));
    }

    // =========================================================================
    // ORDER SYNC
    // =========================================================================

    public function sync_new_order(int $order_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_orders') !== '1') return;
        $this->sync_order($order_id);
    }

    public function sync_order_update(int $order_id): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_orders') !== '1') return;
        $this->sync_order($order_id);
    }

    public function sync_order_status_change(int $order_id, string $old_status, string $new_status, \WC_Order $order): void {
        if (get_option('nwws_sync_enabled') !== '1' || get_option('nwws_sync_orders') !== '1') return;
        $this->sync_order($order_id);

        if (get_option('nwws_track_conversions') === '1' && $new_status === 'completed') {
            $this->track_conversion($order);
        }
    }

    private function sync_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $items      = [];
        $total_cogs = 0;

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
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'cogs'         => $cogs,
            ];
        }

        $revenue       = (float) $order->get_total();
        $profit        = $revenue - $total_cogs - (float) $order->get_shipping_total();
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
            'created_at'      => $order->get_date_created()->format('Y-m-d H:i:s'),
            'updated_at'      => current_time('mysql'),
            'utm_source'      => $order->get_meta('_utm_source'),
            'utm_medium'      => $order->get_meta('_utm_medium'),
            'utm_campaign'    => $order->get_meta('_utm_campaign'),
            'utm_content'     => $order->get_meta('_utm_content'),
            'utm_term'        => $order->get_meta('_utm_term'),
            'fbclid'          => $order->get_meta('_fbclid'),
        ];

        $this->send_to_api('orders', $data, 'POST');
        $order->update_meta_data('_nwws_last_sync', current_time('timestamp'));
        $order->save();
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
            'api_url'          => get_option('nwws_api_url', ''),
            'api_key'          => get_option('nwws_api_key', '') ? '***' : '',
            'sync_enabled'     => get_option('nwws_sync_enabled', '0') === '1',
            'sync_products'    => get_option('nwws_sync_products', '1') === '1',
            'sync_orders'      => get_option('nwws_sync_orders', '1') === '1',
            'sync_customers'   => get_option('nwws_sync_customers', '1') === '1',
            'track_conversions'=> get_option('nwws_track_conversions', '1') === '1',
            'version'          => NWWS_VERSION,
        ]);
    }

    public function rest_update_settings(\WP_REST_Request $request): \WP_REST_Response {
        $body = $request->get_json_params();

        $map = [
            'api_url'           => 'nwws_api_url',
            'api_key'           => 'nwws_api_key',
            'sync_enabled'      => 'nwws_sync_enabled',
            'sync_products'     => 'nwws_sync_products',
            'sync_orders'       => 'nwws_sync_orders',
            'sync_customers'    => 'nwws_sync_customers',
            'track_conversions' => 'nwws_track_conversions',
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

    public function rest_get_stats(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        return rest_ensure_response([
            'total_products'      => wp_count_posts('product')->publish,
            'total_orders'        => wc_orders_count('completed'),
            'total_revenue'       => $wpdb->get_var("SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_order_total'"),
            'products_with_cogs'  => $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_cogs' AND meta_value != ''"),
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

        $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
        foreach ($products as $product) {
            $this->sync_product($product->get_id());
        }

        wp_send_json_success(count($products) . ' producten gesynchroniseerd.');
    }

    public function ajax_sync_all_orders(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        @set_time_limit(300);

        $orders = wc_get_orders(['limit' => -1]);
        foreach ($orders as $order) {
            $this->sync_order($order->get_id());
        }

        wp_send_json_success(count($orders) . ' orders gesynchroniseerd.');
    }

    public function ajax_get_sync_stats(): void {
        check_ajax_referer('nwws_nonce', 'nonce');
        global $wpdb;

        wp_send_json_success([
            'total_products'      => wp_count_posts('product')->publish,
            'products_synced'     => $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_nwws_last_sync'"),
            'products_with_cogs'  => $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_cogs' AND meta_value != ''"),
            'total_orders'        => wc_orders_count('all'),
            'orders_synced'       => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_nwws_last_sync' AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order')"),
        ]);
    }

    // =========================================================================
    // API COMMUNICATION
    // =========================================================================

    private function send_to_api(string $endpoint, array $data, string $method = 'POST'): bool {
        $api_url = get_option('nwws_api_url', '');
        $api_key = get_option('nwws_api_key', '');

        if (empty($api_url) || empty($api_key)) return false;

        $response = wp_remote_request(trailingslashit($api_url) . $endpoint, [
            'method'  => $method,
            'headers' => ['Content-Type' => 'application/json', 'X-API-Key' => $api_key],
            'body'    => wp_json_encode($data),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('NWWS API Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
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
    add_option('nwws_sync_enabled',      '0');
    add_option('nwws_sync_products',     '1');
    add_option('nwws_sync_orders',       '1');
    add_option('nwws_sync_customers',    '1');
    add_option('nwws_track_conversions', '1');
});

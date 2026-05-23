<?php
declare(strict_types=1);
/**
 * REST API endpoints voor Neuramerce Migrator.
 * Base: /wp-json/neuramerce/v1/
 *
 * Authenticatie: X-Neuramerce-Key header
 */

defined('ABSPATH') || exit;

class NWWS_Migrator_API {

    const NAMESPACE = 'neuramerce/v1';

    public static function register_routes(): void {
        $auth = fn( $r ) => NWWS_Migrator_Auth::validate( $r )
            ? true
            : new WP_Error( 'unauthorized', 'Ongeldige API key', [ 'status' => 401 ] );

        $routes = [
            'status'              => 'get_status',
            'site'                => 'get_site',
            'sites'               => 'get_sites',         // v1.6: multisite blogs lijst
            'pages'               => 'get_pages',
            'posts'               => 'get_posts',
            'terms'               => 'get_terms',         // v1.6: generic taxonomy-terms fetcher
            'media'               => 'get_media',
            'products'            => 'get_products',
            'categories'          => 'get_categories',
            'brands'              => 'get_brands',
            'attributes'          => 'get_product_attributes',
            'orders'              => 'get_orders',
            'customers'           => 'get_customers',
            'reviews'             => 'get_reviews',
            'coupons'             => 'get_coupons',
            'shipping'            => 'get_shipping',
            'redirects'           => 'get_redirects',
            'menus'               => 'get_menus',
            'snippets'            => 'get_snippets',
            'recipes'             => 'get_recipes',
            'courses'             => 'get_courses',
            'design'              => 'get_global_design',
            'elementor-templates' => 'get_elementor_templates',
            'stock'               => 'get_stock',
        ];

        $paginate_args = [
            'page'     => [ 'sanitize_callback' => 'absint', 'default' => 1 ],
            'per_page' => [ 'sanitize_callback' => 'absint', 'default' => 10 ],
            'lang'     => [ 'sanitize_callback' => 'sanitize_key' ],
            // v1.6: multisite blog selector. Als site_id leeg/0 → huidige blog.
            'site_id'  => [ 'sanitize_callback' => 'absint' ],
            // v1.6: 'html' = rendered + Elementor stripped naar schone HTML.
            //       'sections' (default) = bestaande structured output.
            'format'   => [ 'sanitize_callback' => 'sanitize_key' ],
            // v1.6: taxonomy-filters (alleen gebruikt door /terms)
            'taxonomy'  => [ 'sanitize_callback' => 'sanitize_key' ],
            'post_type' => [ 'sanitize_callback' => 'sanitize_key' ],
        ];

        foreach ( $routes as $route => $method ) {
            register_rest_route( self::NAMESPACE, '/' . $route, [
                'methods'             => 'GET',
                'callback'            => function ( WP_REST_Request $req ) use ( $method ) {
                    return self::with_site_switch( $req, [ __CLASS__, $method ] );
                },
                'permission_callback' => $auth,
                'args'                => $paginate_args,
            ] );
        }

        // order-status is intentionally public — security via order number + email combination.
        // No Neuramerce API key needed so it always works from the chat widget.
        register_rest_route( self::NAMESPACE, '/order-status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_order_status' ],
            'permission_callback' => fn() => true, // intentionally public — see comment above
            'args'                => [
                'order_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'email'    => [ 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
            ],
        ] );

        // Auth verify (POST)
        register_rest_route( self::NAMESPACE, '/auth/verify', [
            'methods'             => 'POST',
            'callback'            => fn() => new WP_REST_Response( [ 'ok' => true ] ),
            'permission_callback' => $auth,
        ] );

        // current-customer is intentionally public — uses the visitor's WP session cookie.
        // Returns logged-in WP/WooCommerce user so the chat widget knows name + email.
        register_rest_route( self::NAMESPACE, '/current-customer', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_current_customer' ],
            'permission_callback' => fn() => true, // intentionally public — see comment above
        ] );

        // media-binary streamt een afbeelding uit /wp-content/uploads/ direct via PHP.
        // Bypasst Cloudflare/Wordfence hotlink-protection die externe origins blokkeert.
        // Auth via dezelfde API key als andere endpoints.
        register_rest_route( self::NAMESPACE, '/media-binary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_media_binary' ],
            'permission_callback' => $auth,
            'args'                => [
                'url' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_string( $v ) && filter_var( $v, FILTER_VALIDATE_URL ),
                ],
            ],
        ] );

        // media-binary/test is een dev/diagnose endpoint — geeft uploads dir + sample bestanden terug
        // zodat je kunt verifiëren of de streamer werkt zonder een echte URL te kennen.
        register_rest_route( self::NAMESPACE, '/media-binary/test', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_media_binary_test' ],
            'permission_callback' => $auth,
        ] );

        // products/count (v1.7) — snelle voortgangsbalk-bron voor POS-import preview.
        // Gebruikt wp_count_posts (1 SQL count) i.p.v. wc_get_products(limit=-1) dat ALLE
        // product-IDs uit de DB pulls — laatst gemeten op nomadfire 5+ seconden voor 2k+ shops.
        // Gewikkeld in with_site_switch zodat multisite ?site_id= werkt.
        register_rest_route( self::NAMESPACE, '/products/count', [
            'methods'             => 'GET',
            'callback'            => function ( WP_REST_Request $req ) {
                return self::with_site_switch( $req, [ __CLASS__, 'get_products_count' ] );
            },
            'permission_callback' => $auth,
            'args'                => [
                'site_id' => [ 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // ─── Push-endpoints vanuit Neuramerce ────────────────────────────────
        // Vervangen "Woo Custom Stock Status Pro" + dynamische orderstatussen.
        // Auth via dezelfde Bearer/X-Neuramerce-Key flow als andere protected routes.

        // /order-statuses — POST = bulk-overschrijven van WP-option;
        // GET (v1.11.0) = live-fetch van actieve wc-<key> post_statuses
        // voor Neura's safeguard_status_mapping_consistency drift-detectie.
        //
        // Review-fix: één register_rest_route call met dual methods ipv twee
        // aparte calls. Voorkomt edge-case op multisite waar tweede registratie
        // de eerste kan overriden bij dubbele plugin-init.
        register_rest_route( self::NAMESPACE, '/order-statuses', [
            'methods'             => WP_REST_Server::READABLE | WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'order_statuses_dispatch' ],
            'permission_callback' => $auth,
        ] );

        // POST /stock-texts — bulk voorraadtekst-update voor producten + categorieën.
        // Body: { products: [{ wp_id, text }], categories: [{ wp_id, text }] }
        register_rest_route( self::NAMESPACE, '/stock-texts', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'sync_stock_texts' ],
            'permission_callback' => $auth,
        ] );

        // POST /orders/{id}/status — zet custom status op één WC-order + leverdatum.
        // Body: { status_key, planned_delivery_at? }
        register_rest_route( self::NAMESPACE, '/orders/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'set_order_status' ],
            'permission_callback' => $auth,
            'args'                => [
                'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // POST /shipping-schedules — volledige snapshot van shipping-config.
        // Body: { default: {...}, overrides: [{category_slug, week_config, message_tpl}], exceptions: [{date, type, label}] }
        register_rest_route( self::NAMESPACE, '/shipping-schedules', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'sync_shipping_schedules' ],
            'permission_callback' => $auth,
        ] );
    }

    // ─── Plugin meta-block (v1.7 feature-detectie) ───────────────────────────
    //
    // Frontend leest meta.features uit /products + /categories responses om te
    // beslissen welke code-paths te gebruiken (light_mode, paginated_categories, count_endpoint).
    // Backwards-compat: oude clients die meta-block negeren werken gewoon door.

    private static function meta_block(): array {
        return [
            'plugin_version' => defined( 'NWWS_VERSION' ) ? NWWS_VERSION : 'unknown',
            'features'       => [
                'light_mode',           // /products?light=1 → format_product_light (~10× sneller)
                'paginated_categories', // /categories?page=N&per_page=M (backwards-compat: zonder = alles)
                'count_endpoint',       // /products/count
                'format_hardened',      // try/catch rond format_product et al — 1 kapot product crasht niet
                'multisite',            // v1.6: ?site_id= switch_to_blog support
                'media_binary',         // v1.6: image streaming voor CF-bypass
                'taxonomy_terms',       // v1.6: generic /terms fetcher
            ],
        ];
    }

    // ─── Multisite helpers (v1.6) ─────────────────────────────────────────────

    /**
     * Wrapper die bij elke endpoint eerst switch_to_blog($site_id) doet zodra de
     * caller een `site_id` query-param heeft meegegeven. Werkt alleen op WP-multisite
     * installs; op single-site wordt site_id stil genegeerd. Altijd met try/finally
     * zodat restore_current_blog() ook bij exceptions draait — anders blijft de
     * hoofd-blog permanent op de verkeerde state staan.
     */
    private static function with_site_switch( WP_REST_Request $req, callable $handler ) {
        $site_id = (int) $req->get_param( 'site_id' );
        $switched = false;

        if ( $site_id > 0 && is_multisite() ) {
            // Verifieer dat deze blog-ID bestaat binnen het netwerk — anders 404.
            $blog = get_site( $site_id );
            if ( ! $blog ) {
                return new WP_Error( 'unknown_site', "Blog {$site_id} niet gevonden in netwerk", [ 'status' => 404 ] );
            }
            switch_to_blog( $site_id );
            $switched = true;
        }

        try {
            return call_user_func( $handler, $req );
        } finally {
            if ( $switched ) restore_current_blog();
        }
    }

    /**
     * Lijst alle blogs in een WP multisite-installatie. Op single-site retourneert
     * dit één rij: de huidige site. Handig voor de Neuramerce import-UI om een
     * dropdown te vullen.
     */
    public static function get_sites(): WP_REST_Response {
        if ( ! is_multisite() ) {
            return new WP_REST_Response( [ 'items' => [ [
                'id'       => 1,
                'name'     => get_bloginfo( 'name' ),
                'url'      => get_site_url(),
                'path'     => '/',
                'public'   => true,
                'archived' => false,
            ] ] ] );
        }

        $blogs = get_sites( [ 'number' => 200, 'spam' => 0, 'deleted' => 0 ] );
        $items = array_map( function ( $b ) {
            switch_to_blog( (int) $b->blog_id );
            $data = [
                'id'       => (int) $b->blog_id,
                'name'     => get_bloginfo( 'name' ),
                'url'      => get_site_url(),
                'path'     => $b->path,
                'public'   => (int) $b->public === 1,
                'archived' => (int) $b->archived === 1,
            ];
            restore_current_blog();
            return $data;
        }, $blogs );

        return new WP_REST_Response( [ 'items' => $items, 'total' => count( $items ) ] );
    }

    /**
     * Generieke taxonomy-terms fetcher. Eén endpoint voor alle type taxonomieën:
     *   - ?taxonomy=category       → blog-categorieën
     *   - ?taxonomy=post_tag       → blog-tags
     *   - ?taxonomy=product_cat    → WooCommerce product-categorieën
     *   - ?taxonomy=page_category  → als custom taxonomy op pages bestaat
     *   - ?taxonomy=recipe_category, recipe_tag, etc.
     *
     * Optionele post_type filter beperkt tot taxonomieën die op dat post-type actief zijn.
     * Als taxonomy niet bestaat → lege array (niet 404 — makkelijker voor UI die
     * meerdere taxonomieën in parallel ophaalt).
     */
    public static function get_terms( WP_REST_Request $req ): WP_REST_Response {
        $taxonomy = (string) $req->get_param( 'taxonomy' );
        if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
            return new WP_REST_Response( [ 'items' => [], 'total' => 0, 'taxonomy' => $taxonomy ?: null ] );
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 500,
        ] );

        if ( is_wp_error( $terms ) ) {
            return new WP_REST_Response( [ 'items' => [], 'error' => $terms->get_error_message() ], 200 );
        }

        $data = array_map( function ( $t ) {
            $thumb_id  = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
            $image_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : null;
            return [
                'id'          => (int) $t->term_id,
                'name'        => $t->name,
                'slug'        => $t->slug,
                'description' => wp_strip_all_tags( (string) $t->description ),
                'parentId'    => $t->parent ? (int) $t->parent : null,
                'count'       => (int) $t->count,
                'image'       => $image_url,
                'taxonomy'    => $t->taxonomy,
            ];
        }, $terms );

        return new WP_REST_Response( [
            'items'    => $data,
            'total'    => count( $data ),
            'taxonomy' => $taxonomy,
        ] );
    }

    /**
     * Render een post (pagina of blogpost) naar schone HTML, ongeacht of hij met
     * Elementor, Gutenberg of de Classic editor is gebouwd. Output = semantische
     * HTML, Elementor-wrapper-divs gestript, style/class attributes weg.
     *
     * Voor Elementor: gebruik frontend-renderer zodat widgets server-side
     * geëxpandeerd worden (anders krijg je alleen shortcodes + data-attributes).
     */
    private static function render_clean_html( WP_Post $post ): string {
        $is_elementor = ! empty( get_post_meta( $post->ID, '_elementor_data', true ) );

        if ( $is_elementor && class_exists( '\\Elementor\\Plugin' ) ) {
            try {
                $rendered = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post->ID, true );
            } catch ( \Throwable $e ) {
                $rendered = apply_filters( 'the_content', $post->post_content );
            }
        } else {
            // Gutenberg-blocks, classic content en shortcodes — allemaal via the_content filter
            // waardoor blocks gerenderd worden en shortcodes verwerkt.
            $rendered = apply_filters( 'the_content', $post->post_content );
        }

        return NWWS_Content_Cleaner::clean( (string) $rendered );
    }

    // ─── Status ───────────────────────────────────────────────────────────────

    public static function get_status(): WP_REST_Response {
        $wc_active   = class_exists( 'WooCommerce' );
        $wpml_active = defined( 'ICL_SITEPRESS_VERSION' );

        // WPML's wpml_active_languages filter can trigger loopback REST requests on some
        // server configurations (WPML makes internal HTTP calls to refresh language data).
        // We suppress all WPML/WCML translation filters before calling it to prevent
        // recursive REST dispatches that cause PHP-FPM deadlocks.
        if ( $wpml_active ) {
            remove_all_filters( 'wpml_active_languages' );
        }
        $wpml_langs = $wpml_active
            ? array_keys( (array) apply_filters( 'wpml_active_languages', null ) )
            : [];

        return new WP_REST_Response( [
            'wp_version'      => get_bloginfo( 'version' ),
            'site_url'        => get_site_url(),
            'woocommerce'     => $wc_active,
            'elementor'       => did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' ),
            'wpml'            => $wpml_active,
            'wpml_languages'  => $wpml_langs,
            'counts'          => [
                'pages'       => wp_count_posts( 'page' )->publish,
                'posts'       => wp_count_posts( 'post' )->publish,
                'media'       => wp_count_posts( 'attachment' )->inherit,
                'products'    => $wc_active ? wp_count_posts( 'product' )->publish : 0,
                'orders'      => $wc_active ? self::count_wc_orders() : 0,
                'reviews'     => $wc_active ? self::count_wc_reviews() : 0,
                'customers'   => $wc_active ? self::count_wc_customers() : 0,
                'coupons'     => $wc_active ? wp_count_posts( 'shop_coupon' )->publish : 0,
                'recipes'     => post_type_exists( 'wpzoom_rcb' ) ? wp_count_posts( 'wpzoom_rcb' )->publish : 0,
                'courses'     => post_type_exists( 'courses' ) ? wp_count_posts( 'courses' )->publish : 0,
                'redirects'   => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}rank_math_redirections WHERE status='active'" ),
                'brands'      => taxonomy_exists( 'product_brand' ) ? wp_count_terms( 'product_brand' ) : 0,
                'elTemplates' => wp_count_posts( 'elementor_library' )->publish,
            ],
        ] );
    }

    // ─── Site info ────────────────────────────────────────────────────────────

    public static function get_site(): WP_REST_Response {
        $logo_id  = get_theme_mod( 'custom_logo' );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : null;
        $wc       = class_exists( 'WooCommerce' );

        // Special page assignments
        $front_type     = get_option( 'show_on_front' );
        $homepage_id    = (int) get_option( 'page_on_front' );
        $blog_id        = (int) get_option( 'page_for_posts' );
        $shop_id        = $wc ? (int) wc_get_page_id( 'shop' )     : null;
        $cart_id        = $wc ? (int) wc_get_page_id( 'cart' )     : null;
        $checkout_id    = $wc ? (int) wc_get_page_id( 'checkout' ) : null;

        // Tracking IDs
        $fb_settings = get_option( 'woocommerce_facebook_for_woocommerce_settings', [] );
        $fb_pixel    = $fb_settings['pixel_id'] ?? get_option( 'wc_facebook_pixel_id', null );

        return new WP_REST_Response( [
            'name'          => get_bloginfo( 'name' ),
            'tagline'       => get_bloginfo( 'description' ),
            'url'           => get_site_url(),
            'language'      => get_bloginfo( 'language' ),
            'logo'          => $logo_url,
            'favicon'       => get_site_icon_url(),
            'currency'      => $wc ? get_woocommerce_currency() : 'EUR',
            'currencySymbol'=> $wc ? html_entity_decode( get_woocommerce_currency_symbol() ) : '€',
            'weightUnit'    => $wc ? get_option( 'woocommerce_weight_unit',    'kg' ) : null,
            'dimensionUnit' => $wc ? get_option( 'woocommerce_dimension_unit', 'cm' ) : null,
            'taxIncluded'   => $wc ? get_option( 'woocommerce_prices_include_tax' ) === 'yes' : null,
            'storeAddress'  => $wc ? [
                'address' => get_option( 'woocommerce_store_address' ),
                'city'    => get_option( 'woocommerce_store_city' ),
                'postcode'=> get_option( 'woocommerce_store_postcode' ),
                'country' => get_option( 'woocommerce_default_country' ),
            ] : null,
            'timezone'      => get_option( 'timezone_string' ) ?: 'Europe/Amsterdam',
            'email'         => get_option( 'admin_email' ),
            'frontPageType' => $front_type,  // 'page' or 'posts'
            'homepageId'    => $front_type === 'page' ? $homepage_id : null,
            'blogPageId'    => $blog_id ?: null,
            'shopPageId'    => $shop_id,
            'cartPageId'    => $cart_id,
            'checkoutPageId'=> $checkout_id,
            'tracking'      => [
                'facebookPixelId' => $fb_pixel ?: null,
            ],
        ] );
    }

    // ─── Pages ────────────────────────────────────────────────────────────────

    public static function get_pages( WP_REST_Request $req ): WP_REST_Response {
        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        $offset = ( $page - 1 ) * $per;

        $prev_lang = self::wpml_switch_to_nl();

        $query = new WP_Query( [
            'post_type'           => 'page',
            'post_status'         => 'publish',
            'posts_per_page'      => $per,
            'offset'              => $offset,
            'orderby'             => 'menu_order',
            'order'               => 'ASC',
            'suppress_filters'    => false,
            'ignore_sticky_posts' => true,
        ] );

        $format = (string) $req->get_param( 'format' );
        $total  = $query->found_posts;
        $data   = array_map( fn( $p ) => self::format_page( $p, $format === 'html' ), $query->posts );

        self::wpml_restore_language( $prev_lang );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    private static function format_page( WP_Post $post, bool $html_only = false ): array {
        $is_elementor = ! empty( get_post_meta( $post->ID, '_elementor_data', true ) );
        $is_gutenberg = ! $is_elementor && has_blocks( $post->post_content );
        $builder      = $is_elementor ? 'elementor' : ( $is_gutenberg ? 'gutenberg' : 'classic' );

        $payload = [
            'id'          => $post->ID,
            'slug'        => $post->post_name,
            'title'       => $post->post_title,
            'status'      => $post->post_status,
            'parentId'    => $post->post_parent ?: null,
            'menuOrder'   => $post->menu_order,
            'lang'        => defined( 'ICL_SITEPRESS_VERSION' )
                ? ( apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $post->ID, 'element_type' => 'post_page' ] ) ?? 'nl' )
                : 'nl',
            'builder'     => $builder,
            'seo'         => self::get_seo_meta( $post->ID ),
            'featuredImg' => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
            'createdAt'   => $post->post_date,
            'updatedAt'   => $post->post_modified,
        ];

        if ( $html_only ) {
            // v1.6: clean HTML output — Elementor/Gutenberg/Classic naar één semantische string.
            $payload['html'] = self::render_clean_html( $post );
        } else {
            // Legacy: structured sections (backwards compat voor bestaande consumers).
            if ( $is_elementor ) {
                $parsed          = NWWS_Elementor_Parser::parse( $post->ID );
                $payload['sections'] = $parsed['sections'];
            } elseif ( $is_gutenberg ) {
                $payload['sections'] = NWWS_Gutenberg_Parser::parse( $post->post_content );
            } else {
                $payload['sections'] = [ [
                    'type'  => 'html',
                    'props' => [ 'html' => NWWS_Content_Cleaner::clean( $post->post_content ) ],
                ] ];
            }
        }

        return $payload;
    }

    // ─── Blog posts ───────────────────────────────────────────────────────────

    public static function get_posts( WP_REST_Request $req ): WP_REST_Response {
        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        $offset = ( $page - 1 ) * $per;

        $prev_lang = self::wpml_switch_to_nl();

        $query = new WP_Query( [
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $per,
            'offset'              => $offset,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'suppress_filters'    => false,
            'ignore_sticky_posts' => true,
        ] );

        $total     = $query->found_posts;
        $posts     = $query->posts;
        $html_only = $req->get_param( 'format' ) === 'html';

        self::wpml_restore_language( $prev_lang );

        $data = array_map( function( $p ) use ( $html_only ) {
            $is_elementor = ! empty( get_post_meta( $p->ID, '_elementor_data', true ) );
            $builder      = $is_elementor ? 'elementor' : ( has_blocks( $p->post_content ) ? 'gutenberg' : 'classic' );

            // Excerpt: gebruik ingesteld excerpt of eerste 30 woorden van clean content
            $excerpt = $p->post_excerpt ?: wp_trim_words(
                wp_strip_all_tags( $p->post_content ), 30, '…'
            );

            $lang = defined( 'ICL_SITEPRESS_VERSION' )
                ? apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $p->ID, 'element_type' => 'post_post' ] )
                : null;

            // Category + tag objects met term_ids zodat Neuramerce M:N assignments kan maken.
            $cat_terms = wp_get_post_terms( $p->ID, 'category', [ 'fields' => 'all' ] );
            $tag_terms = wp_get_post_terms( $p->ID, 'post_tag', [ 'fields' => 'all' ] );

            $payload = [
                'id'          => $p->ID,
                'slug'        => $p->post_name,
                'title'       => $p->post_title,
                'excerpt'     => $excerpt,
                'lang'        => $lang ?? 'nl',
                'builder'     => $builder,
                'seo'         => self::get_seo_meta( $p->ID ),
                'featuredImg' => get_the_post_thumbnail_url( $p->ID, 'full' ) ?: null,
                'categories'  => array_map( fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], is_wp_error( $cat_terms ) ? [] : $cat_terms ),
                'tags'        => array_map( fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], is_wp_error( $tag_terms ) ? [] : $tag_terms ),
                'publishedAt' => $p->post_date,
                'updatedAt'   => $p->post_modified,
            ];

            if ( $html_only ) {
                $payload['html'] = self::render_clean_html( $p );
            } else {
                // Legacy: sections-formaat (backwards compat).
                if ( $is_elementor ) {
                    $parsed             = NWWS_Elementor_Parser::parse( $p->ID );
                    $payload['sections'] = $parsed['sections'];
                } else {
                    $payload['sections'] = NWWS_Gutenberg_Parser::parse( $p->post_content );
                }
            }

            return $payload;
        }, $posts );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── Media ────────────────────────────────────────────────────────────────

    public static function get_media( WP_REST_Request $req ): WP_REST_Response {
        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );
        $offset = ( $page - 1 ) * $per;

        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $total = wp_count_posts( 'attachment' )->inherit;

        $data = array_map( function( $a ) {
            $meta = wp_get_attachment_metadata( $a->ID );
            return [
                'id'       => $a->ID,
                'url'      => wp_get_attachment_url( $a->ID ),
                'filename' => basename( get_attached_file( $a->ID ) ),
                'mimeType' => $a->post_mime_type,
                'alt'      => get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
                'caption'  => $a->post_excerpt,
                'width'    => $meta['width']  ?? null,
                'height'   => $meta['height'] ?? null,
                'filesize' => $meta['filesize'] ?? null,
            ];
        }, $attachments );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── WooCommerce Products ─────────────────────────────────────────────────

    public static function get_products( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $page  = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per   = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        // v1.7: ?light=1 → format_product_light, ~10× sneller (skip gallery, variations,
        // attributes, addons, SEO, related). Voor POS-import preview. Volledige
        // format_product blijft default voor migratie/full-sync.
        $light = (bool) (int) ( $req->get_param( 'light' ) ?? 0 );

        $prev_lang = self::wpml_switch_to_nl();

        $query = new WC_Product_Query( [
            'limit'            => $per,
            'page'             => $page,
            'status'           => 'publish',
            'orderby'          => 'date',
            'order'            => 'DESC',
            'return'           => 'objects',
            'suppress_filters' => false,
        ] );
        $products = $query->get_products();
        // v1.7: wp_count_posts is een 1-query indexed lookup, vele malen sneller dan
        // wc_get_products( limit=-1 ) dat alle product-IDs in PHP-memory laadt.
        $total    = (int) wp_count_posts( 'product' )->publish;

        self::wpml_restore_language( $prev_lang );

        $formatter = $light
            ? [ __CLASS__, 'format_product_light' ]
            : [ __CLASS__, 'format_product' ];
        $data = array_map( $formatter, $products );

        return new WP_REST_Response( [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => $per > 0 ? (int) ceil( $total / $per ) : 1,
            'items'    => $data,
            'meta'     => self::meta_block(),
        ] );
    }

    /**
     * v1.7: Snelle count-endpoint voor frontend-voortgangsbalk.
     * Pre-1.7 gebruikte de frontend wc_get_products(limit=-1) wat traag is bij grote shops.
     * Wikkeling met with_site_switch bij multisite gebeurt in register_routes().
     */
    public static function get_products_count(): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }
        $total = (int) wp_count_posts( 'product' )->publish;
        return new WP_REST_Response( [
            'total'    => $total,
            'pages_at' => [
                '5'  => (int) ceil( $total / 5 ),
                '10' => (int) ceil( $total / 10 ),
                '15' => (int) ceil( $total / 15 ),
                '25' => (int) ceil( $total / 25 ),
                '50' => (int) ceil( $total / 50 ),
            ],
            'meta'     => self::meta_block(),
        ] );
    }

    /**
     * v1.7: Lichtgewicht product-formatter voor POS-import preview.
     *
     * Verschil met format_product():
     *   - alleen featured image in 'medium' size (geen full-res gallery)
     *   - geen variations (skip wc_get_product per variation)
     *   - geen attribute-taxonomy queries
     *   - geen TM Extra Options / WooSB / SEO meta / related products
     *   - shortDesc truncated 500 chars
     *
     * Resultaat: ~10× sneller. Voor preview heeft frontend toch alleen
     * id/sku/name/price/image nodig om matching + selectie te tonen.
     * Volledige product-data wordt later via execute-flow opgehaald (zonder ?light=1).
     */
    private static function format_product_light( WC_Product $p ): array {
        try {
            $pid = $p->get_id();
            $img = wp_get_attachment_image_url( $p->get_image_id(), 'medium' );
            $short = wp_strip_all_tags( (string) $p->get_short_description() );
            return [
                'id'           => $pid,
                'sku'          => $p->get_sku(),
                'name'         => $p->get_name(),
                'slug'         => $p->get_slug(),
                'price'        => (float) $p->get_price(),
                'regularPrice' => (float) $p->get_regular_price(),
                'salePrice'    => $p->is_on_sale() ? (float) $p->get_sale_price() : null,
                'stock'        => $p->get_stock_quantity(),
                'stockStatus'  => $p->get_stock_status(),
                'manageStock'  => $p->get_manage_stock(),
                'taxClass'     => $p->get_tax_class(),
                'taxStatus'    => $p->get_tax_status(),
                'type'         => $p->get_type(),
                'shortDesc'    => mb_substr( $short, 0, 500 ),
                // Backwards-compat: frontend pluginFetch leest images[] array
                'images'       => $img ? [ [ 'url' => $img, 'alt' => '' ] ] : [],
                'categories'   => wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'names' ] ),
                'permalink'    => get_permalink( $pid ),
                // Velden die light NIET levert maar volledige format_product wel
                'description'  => null,
                'ean'          => null,
            ];
        } catch ( \Throwable $e ) {
            error_log( '[NWWS] format_product_light failed for product ' . $p->get_id() . ': ' . $e->getMessage() );
            return [
                'id'     => $p->get_id(),
                'sku'    => method_exists( $p, 'get_sku' ) ? $p->get_sku() : null,
                'name'   => method_exists( $p, 'get_name' ) ? $p->get_name() : '(format failed)',
                '_error' => 'format_failed',
            ];
        }
    }

    private static function format_product( WC_Product $p ): array {
        // v1.7 hardening: één kapot product (corrupte serialized meta, missing parent term,
        // plugin-conflict) mag niet de hele page-fetch crashen. Bij exception: log + return
        // minimal stub zodat de andere producten in de batch wel doorkomen.
        try {
            $images = array_map( fn( $id ) => [
                'url' => wp_get_attachment_image_url( $id, 'full' ),
                'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            ], array_filter( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) ) );

            $variations = [];
            if ( $p->is_type( 'variable' ) ) {
                foreach ( $p->get_children() as $var_id ) {
                    try {
                        $v = wc_get_product( $var_id );
                        if ( ! $v ) continue;
                        $variations[] = [
                            'id'         => $var_id,
                            'sku'        => $v->get_sku(),
                            'price'      => (float) $v->get_price(),
                            'salePrice'  => $v->is_on_sale() ? (float) $v->get_sale_price() : null,
                            'stock'      => $v->get_stock_quantity(),
                            'attributes' => $v->get_variation_attributes(),
                        ];
                    } catch ( \Throwable $e ) {
                        error_log( '[NWWS] variation ' . $var_id . ' failed: ' . $e->getMessage() );
                    }
                }
            }

            // Haal merk/kleur/maat op uit product attributes
            $attrs = [];
            foreach ( $p->get_attributes() as $attr ) {
                try {
                    $name = wc_attribute_label( $attr->get_name() );
                    $vals = $attr->is_taxonomy()
                        ? wc_get_product_terms( $p->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
                        : $attr->get_options();
                    $attrs[ strtolower( $name ) ] = implode( ', ', $vals );
                } catch ( \Throwable $e ) {
                    error_log( '[NWWS] attribute parse failed on ' . $p->get_id() . ': ' . $e->getMessage() );
                }
            }

            $pid = $p->get_id();

            return [
                'id'             => $pid,
                'sku'            => $p->get_sku(),
                'name'           => $p->get_name(),
                'slug'           => $p->get_slug(),
                'description'    => NWWS_Content_Cleaner::clean( $p->get_description() ),
                'shortDesc'      => NWWS_Content_Cleaner::clean( $p->get_short_description() ),
                'price'          => (float) $p->get_price(),
                'regularPrice'   => (float) $p->get_regular_price(),
                'salePrice'      => $p->is_on_sale() ? (float) $p->get_sale_price() : null,
                'stock'          => $p->get_stock_quantity(),
                'stockStatus'    => $p->get_stock_status(),
                'manageStock'    => $p->get_manage_stock(),
                'backorders'     => $p->get_backorders() !== 'no',
                'weight'         => $p->get_weight(),
                'dimensions'     => [
                    'length' => $p->get_length(),
                    'width'  => $p->get_width(),
                    'height' => $p->get_height(),
                ],
                'shippingClass'  => $p->get_shipping_class() ?: null,
                'type'           => $p->get_type(), // simple | variable | grouped | external
                'categories'     => wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'names' ] ),
                'tags'           => wp_get_post_terms( $pid, 'product_tag', [ 'fields' => 'names' ] ),
                'images'         => $images,
                'attributes'     => $attrs,
                'brand'          => $attrs['merk'] ?? $attrs['brand'] ?? null,
                'ean'            => get_post_meta( $pid, '_ean', true )
                                 ?: get_post_meta( $pid, '_gtin', true )
                                 ?: get_post_meta( $pid, '_cr_gtin', true )
                                 ?: null,
                'variations'     => $variations,
                'crossSells'     => self::format_related_products( $p->get_cross_sell_ids() ),
                'upsells'        => self::format_related_products( $p->get_upsell_ids() ),
                'addons'         => self::get_product_addons( $pid ),
                'seo'            => self::get_seo_meta( $pid ),
                'permalink'      => get_permalink( $pid ),
                'createdAt'      => $p->get_date_created() ? $p->get_date_created()->date( 'c' ) : null,
            ];
        } catch ( \Throwable $e ) {
            error_log( '[NWWS] format_product failed for product ' . $p->get_id() . ': ' . $e->getMessage() );
            return [
                'id'     => $p->get_id(),
                'sku'    => method_exists( $p, 'get_sku' ) ? $p->get_sku() : null,
                'name'   => method_exists( $p, 'get_name' ) ? $p->get_name() : '(format failed)',
                '_error' => 'format_failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Minimal product stub for cross-sells, upsells, bought-together lists.
     * Avoids full format_product recursion.
     */
    private static function format_related_products( array $ids ): array {
        $out = [];
        foreach ( array_slice( array_filter( $ids ), 0, 20 ) as $id ) {
            try {
                $rp = wc_get_product( (int) $id );
                if ( ! $rp ) continue;
                $out[] = [
                    'id'    => $rp->get_id(),
                    'sku'   => $rp->get_sku(),
                    'name'  => $rp->get_name(),
                    'slug'  => $rp->get_slug(),
                    'price' => (float) $rp->get_price(),
                    'image' => wp_get_attachment_image_url( $rp->get_image_id(), 'woocommerce_thumbnail' ) ?: null,
                ];
            } catch ( \Throwable $e ) {
                error_log( '[NWWS] format_related_products failed for ' . $id . ': ' . $e->getMessage() );
            }
        }
        return $out;
    }

    /**
     * Extract product add-on / extra options configuration.
     * Supports: TM Extra Product Options, WooSB bundles.
     * v1.7: hardened met try/catch zodat corrupte serialized meta niet de hele page crasht.
     */
    private static function get_product_addons( int $pid ): array {
        try {
            return self::get_product_addons_inner( $pid );
        } catch ( \Throwable $e ) {
            error_log( '[NWWS] get_product_addons failed for ' . $pid . ': ' . $e->getMessage() );
            return [];
        }
    }

    private static function get_product_addons_inner( int $pid ): array {
        $result = [];

        // ── TM Extra Product Options ──────────────────────────────────────────
        $tm_raw = get_post_meta( $pid, 'tm_meta', true );
        if ( ! empty( $tm_raw ) ) {
            $tm = maybe_unserialize( $tm_raw );
            if ( is_array( $tm ) && isset( $tm['tmfbuilder'] ) ) {
                $fb       = $tm['tmfbuilder'];
                $sections = [];

                $section_names   = $fb['sections_internal_name'] ?? [];
                $elements_in_sec = $fb['elements_in_section']    ?? [];

                foreach ( $section_names as $si => $sec_name ) {
                    $n_elements   = (int) ( $elements_in_sec[ $si ] ?? 0 );
                    $section_key  = 'section_' . $si . '_';
                    $fields       = [];

                    for ( $ei = 0; $ei < $n_elements; $ei++ ) {
                        $el_key   = $section_key . $ei . '_';
                        $el_type  = $fb[ $el_key . 'type' ]  ?? $fb[ 'element_type' ][ $si ][ $ei ] ?? null;
                        $el_label = $fb[ $el_key . 'name' ]  ?? null;
                        $el_opts  = $fb[ $el_key . 'options' ] ?? null;

                        if ( ! $el_type ) continue;

                        $field = [ 'type' => $el_type, 'label' => $el_label ];
                        if ( $el_opts ) $field['options'] = $el_opts;
                        $fields[] = $field;
                    }

                    $sections[] = [ 'name' => $sec_name, 'fields' => $fields ];
                }

                // Price display override
                $tm_cpf = maybe_unserialize( get_post_meta( $pid, 'tm_meta_cpf', true ) );

                $result['tmExtraOptions'] = [
                    'plugin'        => 'tm-extra-product-options',
                    'sections'      => $sections,
                    'priceMode'     => $tm_cpf['price_display_mode'] ?? null,
                    'priceOverride' => $tm_cpf['price_display_override'] ?? null,
                ];
            }
        }

        // ── WooSB (WooCommerce Smart Bundles) ─────────────────────────────────
        $woosb_ids = get_post_meta( $pid, 'woosb_ids', true );
        if ( ! empty( $woosb_ids ) ) {
            $bundle_items = [];
            foreach ( explode( ',', $woosb_ids ) as $entry ) {
                [ $bpid, $qty ] = array_pad( explode( '/', trim( $entry ) ), 2, 1 );
                $bpid = (int) $bpid;
                if ( ! $bpid ) continue;
                $bp = wc_get_product( $bpid );
                if ( ! $bp ) continue;
                $bundle_items[] = [
                    'id'       => $bpid,
                    'sku'      => $bp->get_sku(),
                    'name'     => $bp->get_name(),
                    'qty'      => (int) $qty,
                    'optional' => (bool) get_post_meta( $pid, 'woosb_optional_products', true ),
                ];
            }
            $result['smartBundle'] = [
                'plugin'   => 'woosb',
                'discount' => get_post_meta( $pid, 'woosb_discount', true ),
                'items'    => $bundle_items,
            ];
        }

        return $result ?: [];
    }

    // ─── WooCommerce Categories ───────────────────────────────────────────────

    public static function get_categories( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        // v1.7: backwards-compat paginering. Pre-1.7 clients sturen geen page/per_page →
        // krijg de volledige unpaginated lijst (oude gedrag). Nieuwe clients krijgen
        // netjes paginated met total + pages voor frontend voortgangsbalk.
        $page_param     = $req->get_param( 'page' );
        $per_page_param = $req->get_param( 'per_page' );
        $has_pagination = $page_param !== null && $per_page_param !== null;

        // hide_empty: default false (alle cats incl. leeg). Frontend kan filteren.
        $hide_empty = (bool) (int) ( $req->get_param( 'hide_empty' ) ?? 0 );

        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => $hide_empty,
        ];

        $page = 1;
        $per  = 0; // 0 = unlimited (oud gedrag)
        if ( $has_pagination ) {
            $page = max( 1, (int) $page_param );
            // Plafond op 200 om OOM te voorkomen op shops met enorme cat-aantallen
            $per  = min( 200, max( 1, (int) $per_page_param ) );
            $args['number'] = $per;
            $args['offset'] = ( $page - 1 ) * $per;
        }

        $terms = get_terms( $args );

        // Total via wp_count_terms (1 SQL count) — onafhankelijk van paginering
        $total = (int) wp_count_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => $hide_empty,
        ] );

        $data = array_map( function( $t ) {
            try {
                $thumb_id = get_term_meta( $t->term_id, 'thumbnail_id', true );
                return [
                    'id'          => $t->term_id,
                    'name'        => $t->name,
                    'slug'        => $t->slug,
                    'description' => wp_strip_all_tags( $t->description ),
                    'parentId'    => $t->parent ?: null,
                    'count'       => $t->count,
                    'image'       => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : null,
                ];
            } catch ( \Throwable $e ) {
                error_log( '[NWWS] format_category failed for ' . $t->term_id . ': ' . $e->getMessage() );
                return [
                    'id'     => $t->term_id,
                    'name'   => $t->name ?? '(format failed)',
                    'slug'   => $t->slug ?? '',
                    '_error' => 'format_failed',
                ];
            }
        }, is_array( $terms ) ? $terms : [] );

        return new WP_REST_Response( [
            'items'    => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => $has_pagination && $per > 0 ? (int) ceil( $total / $per ) : 1,
            'meta'     => self::meta_block(),
        ] );
    }

    // ─── WooCommerce Orders ───────────────────────────────────────────────────

    public static function get_orders( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $page = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per  = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );

        $orders = wc_get_orders( [
            'limit'  => $per,
            'page'   => $page,
            'status' => [ 'completed', 'processing', 'on-hold', 'pending' ],
            'type'   => 'shop_order',
        ] );
        $total = wc_orders_count( 'all' );

        $data = array_map( function( WC_Order $o ) {
            return [
                'id'          => $o->get_id(),
                'number'      => $o->get_order_number(),
                'status'      => $o->get_status(),
                'currency'    => $o->get_currency(),
                'total'       => (float) $o->get_total(),
                'subtotal'    => (float) $o->get_subtotal(),
                'shipping'    => (float) $o->get_shipping_total(),
                'tax'         => (float) $o->get_total_tax(),
                'customer'    => [
                    'name'  => $o->get_formatted_billing_full_name(),
                    'email' => $o->get_billing_email(),
                    'phone' => $o->get_billing_phone(),
                ],
                'billing'     => $o->get_address( 'billing' ),
                'shipping'    => $o->get_address( 'shipping' ),
                'items'       => array_map( function( WC_Order_Item_Product $item ) {
                    return [
                        'name'      => $item->get_name(),
                        'sku'       => $item->get_product() ? $item->get_product()->get_sku() : null,
                        'qty'       => $item->get_quantity(),
                        'unitPrice' => $item->get_subtotal() / max( 1, $item->get_quantity() ),
                        'total'     => (float) $item->get_total(),
                    ];
                }, array_values( $o->get_items() ) ),
                'createdAt'   => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
            ];
        }, $orders );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── WooCommerce Customers ────────────────────────────────────────────────

    public static function get_customers( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );
        $offset = ( $page - 1 ) * $per;

        $users = get_users( [
            'role__in' => [ 'customer', 'subscriber' ],
            'number'   => $per,
            'offset'   => $offset,
            'orderby'  => 'registered',
            'order'    => 'DESC',
        ] );
        $total = count_users()['avail_roles']['customer'] ?? 0;

        $data = array_map( function( WP_User $u ) {
            return [
                'id'        => $u->ID,
                'email'     => $u->user_email,
                'firstName' => get_user_meta( $u->ID, 'first_name', true ),
                'lastName'  => get_user_meta( $u->ID, 'last_name', true ),
                'phone'     => get_user_meta( $u->ID, 'billing_phone', true ),
                'address'   => [
                    'street'  => get_user_meta( $u->ID, 'billing_address_1', true ),
                    'city'    => get_user_meta( $u->ID, 'billing_city', true ),
                    'zip'     => get_user_meta( $u->ID, 'billing_postcode', true ),
                    'country' => get_user_meta( $u->ID, 'billing_country', true ),
                ],
                'createdAt' => $u->user_registered,
            ];
        }, $users );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── WooCommerce Reviews ──────────────────────────────────────────────────

    public static function get_reviews( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 50 ) );
        $offset = ( $page - 1 ) * $per;

        $comments = get_comments( [
            'type'     => 'review',
            'status'   => 'approve',
            'number'   => $per,
            'offset'   => $offset,
            'orderby'  => 'comment_date',
            'order'    => 'DESC',
        ] );
        $total = get_comments( [ 'type' => 'review', 'status' => 'approve', 'count' => true ] );

        $data = array_map( function( WP_Comment $c ) {
            $rating = (int) get_comment_meta( $c->comment_ID, 'rating', true );
            $product = wc_get_product( $c->comment_post_ID );
            return [
                'id'          => $c->comment_ID,
                'productId'   => $c->comment_post_ID,
                'productName' => $product ? $product->get_name() : '',
                'productSku'  => $product ? $product->get_sku() : null,
                'author'      => $c->comment_author,
                'email'       => $c->comment_author_email,
                'rating'      => $rating,
                'title'       => '',
                'body'        => wp_strip_all_tags( $c->comment_content ),
                'verified'    => (bool) get_comment_meta( $c->comment_ID, 'verified', true ),
                'createdAt'   => $c->comment_date,
            ];
        }, $comments );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── Product Brands ───────────────────────────────────────────────────────

    public static function get_brands(): WP_REST_Response {
        if ( ! taxonomy_exists( 'product_brand' ) ) {
            return new WP_REST_Response( [ 'items' => [], 'total' => 0 ] );
        }
        $terms = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] );
        $data  = array_map( function( $t ) {
            $thumb_id = get_term_meta( $t->term_id, 'thumbnail_id', true );
            return [
                'id'    => $t->term_id,
                'name'  => $t->name,
                'slug'  => $t->slug,
                'count' => $t->count,
                'image' => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : null,
                'seo'   => [
                    'title'       => get_term_meta( $t->term_id, 'rank_math_title', true ) ?: null,
                    'description' => get_term_meta( $t->term_id, 'rank_math_description', true ) ?: null,
                ],
            ];
        }, $terms ?: [] );
        return new WP_REST_Response( [ 'items' => $data, 'total' => count( $data ) ] );
    }

    // ─── Product Attribute Definitions ────────────────────────────────────────

    public static function get_product_attributes(): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }
        $data = [];
        foreach ( wc_get_attribute_taxonomies() as $attr ) {
            $taxonomy = 'pa_' . $attr->attribute_name;
            $terms    = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
            $data[]   = [
                'id'     => $attr->attribute_id,
                'name'   => $attr->attribute_name,
                'label'  => $attr->attribute_label,
                'type'   => $attr->attribute_type, // select | text | color | image
                'orderBy'=> $attr->attribute_orderby,
                'terms'  => array_map( fn( $t ) => [
                    'id'          => $t->term_id,
                    'name'        => $t->name,
                    'slug'        => $t->slug,
                    'description' => $t->description ?: null,
                    'color'       => get_term_meta( $t->term_id, 'product_attribute_color', true ) ?: null,
                ], $terms ?: [] ),
            ];
        }
        return new WP_REST_Response( [ 'items' => $data, 'total' => count( $data ) ] );
    }

    // ─── Coupons ──────────────────────────────────────────────────────────────

    public static function get_coupons(): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }
        $posts = get_posts( [ 'post_type' => 'shop_coupon', 'posts_per_page' => -1, 'post_status' => 'publish' ] );
        $data  = array_map( function( $post ) {
            $c = new WC_Coupon( $post->ID );
            return [
                'id'                 => $post->ID,
                'code'               => $post->post_title,
                'description'        => $post->post_excerpt,
                'type'               => $c->get_discount_type(), // percent | fixed_cart | fixed_product
                'amount'             => (float) $c->get_amount(),
                'minSpend'           => (float) $c->get_minimum_amount() ?: null,
                'maxSpend'           => (float) $c->get_maximum_amount() ?: null,
                'usageLimit'         => $c->get_usage_limit() ?: null,
                'usageLimitPerUser'  => $c->get_usage_limit_per_user() ?: null,
                'usageCount'         => $c->get_usage_count(),
                'freeShipping'       => $c->get_free_shipping(),
                'excludeSaleItems'   => $c->get_exclude_sale_items(),
                'productIds'         => $c->get_product_ids(),
                'excludeProductIds'  => $c->get_excluded_product_ids(),
                'categoryIds'        => $c->get_product_categories(),
                'expiryDate'         => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : null,
            ];
        }, $posts );
        return new WP_REST_Response( [ 'items' => $data, 'total' => count( $data ) ] );
    }

    // ─── Shipping ─────────────────────────────────────────────────────────────

    public static function get_shipping(): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        // Shipping zones
        $zones_data = [];
        $raw_zones  = WC_Shipping_Zones::get_zones();

        foreach ( $raw_zones as $zone_data ) {
            $zone    = new WC_Shipping_Zone( $zone_data['zone_id'] );
            $methods = [];
            foreach ( $zone->get_shipping_methods() as $method ) {
                if ( $method->enabled !== 'yes' ) continue;
                $methods[] = [
                    'id'       => $method->get_instance_id(),
                    'type'     => $method->id,
                    'title'    => $method->get_method_title(),
                    'cost'     => $method->get_option( 'cost' ) ?: null,
                    'settings' => array_map( fn( $s ) => is_array( $s ) ? ( $s['value'] ?? null ) : $s, $method->instance_settings ),
                ];
            }
            $zones_data[] = [
                'id'        => $zone_data['zone_id'],
                'name'      => $zone_data['zone_name'],
                'regions'   => array_map( fn( $loc ) => [
                    'code' => $loc->code,
                    'type' => $loc->type,
                ], $zone_data['zone_locations'] ),
                'methods'   => $methods,
            ];
        }

        // Shipping classes
        $classes = array_map( fn( $t ) => [
            'id'          => $t->term_id,
            'name'        => $t->name,
            'slug'        => $t->slug,
            'description' => $t->description ?: null,
        ], WC()->shipping()->get_shipping_classes() );

        return new WP_REST_Response( [
            'zones'   => $zones_data,
            'classes' => $classes,
        ] );
    }

    // ─── Redirects ────────────────────────────────────────────────────────────

    public static function get_redirects( WP_REST_Request $req ): WP_REST_Response {
        global $wpdb;
        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 200, max( 1, (int) $req->get_param( 'per_page' ) ?: 100 ) );
        $offset = ( $page - 1 ) * $per;

        $table = $wpdb->prefix . 'rank_math_redirections';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            return new WP_REST_Response( [ 'error' => 'Rank Math redirections niet gevonden' ], 404 );
        }

        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rank_math_redirections WHERE status='active' ORDER BY id LIMIT %d OFFSET %d",
            $per, $offset
        ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_redirections WHERE status='active'" );

        $data = array_map( function( $row ) {
            $sources = maybe_unserialize( $row->sources );
            $froms   = array_map( fn( $s ) => $s['pattern'] ?? '', (array) $sources );
            return [
                'id'         => $row->id,
                'from'       => $froms,        // one redirect can have multiple source patterns
                'to'         => $row->url_to,
                'code'       => (int) $row->header_code,
                'hits'       => (int) $row->hits,
                'lastAccess' => $row->last_accessed,
            ];
        }, $rows );

        return new WP_REST_Response( [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── Custom Code Snippets (Elementor) ─────────────────────────────────────

    public static function get_snippets(): WP_REST_Response {
        $posts = get_posts( [
            'post_type'      => 'elementor_snippet',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ] );
        $data = array_map( function( $post ) {
            $meta = get_post_meta( $post->ID );
            return [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'type'     => $meta['_elementor_snippet_type'][0] ?? null, // css | js | html
                'location' => $meta['_elementor_snippet_location'][0] ?? null,
                'content'  => $post->post_content,
                'conditions'=> maybe_unserialize( $meta['_elementor_conditions'][0] ?? '' ) ?: [],
            ];
        }, $posts );
        return new WP_REST_Response( [ 'items' => $data, 'total' => count( $data ) ] );
    }

    // ─── Recipes (WPZOOM Recipe Card) ─────────────────────────────────────────

    public static function get_recipes( WP_REST_Request $req ): WP_REST_Response {
        if ( ! post_type_exists( 'wpzoom_rcb' ) ) {
            return new WP_REST_Response( [ 'items' => [], 'total' => 0 ] );
        }

        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        $offset = ( $page - 1 ) * $per;

        $prev_lang = self::wpml_switch_to_nl();

        $query = new WP_Query( [
            'post_type'           => 'wpzoom_rcb',
            'post_status'         => 'publish',
            'posts_per_page'      => $per,
            'offset'              => $offset,
            'suppress_filters'    => false,
            'ignore_sticky_posts' => true,
        ] );

        $total = $query->found_posts;

        $data = array_map( function( $post ) {
            $recipe = self::parse_wpzoom_recipe( $post->post_content );

            return [
                'id'          => $post->ID,
                'slug'        => $post->post_name,
                'title'       => $recipe['title']   ?? $post->post_title,
                'summary'     => $recipe['summary']  ?? null,
                'course'      => $recipe['course']   ?? [],
                'cuisine'     => $recipe['cuisine']  ?? [],
                'difficulty'  => $recipe['difficulty'] ?? null,
                'keywords'    => $recipe['keywords'] ?? [],
                'servings'    => $recipe['servings'] ?? null,
                'prepTime'    => $recipe['prepTime'] ?? null,
                'cookTime'    => $recipe['cookTime'] ?? null,
                'ingredients' => $recipe['ingredients'] ?? [],
                'steps'       => $recipe['steps']    ?? [],
                'featuredImg' => $recipe['image']    ?? get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
                'categories'  => wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'names' ] ),
                'tags'        => wp_get_post_terms( $post->ID, 'post_tag', [ 'fields' => 'names' ] ),
                'seo'         => self::get_seo_meta( $post->ID ),
                'publishedAt' => $post->post_date,
                'updatedAt'   => $post->post_modified,
            ];
        }, $query->posts );

        self::wpml_restore_language( $prev_lang );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── Tutor LMS Courses ────────────────────────────────────────────────────

    public static function get_courses( WP_REST_Request $req ): WP_REST_Response {
        if ( ! post_type_exists( 'courses' ) ) {
            return new WP_REST_Response( [ 'items' => [], 'total' => 0 ] );
        }

        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 20, max( 1, (int) $req->get_param( 'per_page' ) ?: 10 ) );
        $offset = ( $page - 1 ) * $per;

        $prev_lang = self::wpml_switch_to_nl();

        $query = new WP_Query( [
            'post_type'           => 'courses',
            'post_status'         => 'publish',
            'posts_per_page'      => $per,
            'offset'              => $offset,
            'suppress_filters'    => false,
            'ignore_sticky_posts' => true,
        ] );

        $total = $query->found_posts;

        $data = array_map( function( $course ) {
            // Get lessons for this course
            $lessons = get_posts( [
                'post_type'      => 'lesson',
                'post_parent'    => $course->ID,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
            ] );

            // Get topics (lesson groupings)
            $topics = get_posts( [
                'post_type'      => 'topics',
                'post_parent'    => $course->ID,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
            ] );

            $meta = get_post_meta( $course->ID );

            return [
                'id'          => $course->ID,
                'slug'        => $course->post_name,
                'title'       => $course->post_title,
                'excerpt'     => $course->post_excerpt,
                'content'     => NWWS_Content_Cleaner::clean( $course->post_content ),
                'featuredImg' => get_the_post_thumbnail_url( $course->ID, 'full' ) ?: null,
                'price'       => (float) ( $meta['_tutor_course_price'][0] ?? 0 ),
                'isFree'      => empty( $meta['_tutor_course_price'][0] ),
                'level'       => $meta['_tutor_course_level'][0] ?? null,
                'duration'    => $meta['_tutor_course_duration'][0] ?? null,
                'categories'  => wp_get_post_terms( $course->ID, 'course-category', [ 'fields' => 'names' ] ),
                'tags'        => wp_get_post_terms( $course->ID, 'course-tag', [ 'fields' => 'names' ] ),
                'topics'      => array_map( fn( $t ) => [
                    'id'    => $t->ID,
                    'title' => $t->post_title,
                    'order' => $t->menu_order,
                ], $topics ),
                'lessons'     => array_map( fn( $l ) => [
                    'id'       => $l->ID,
                    'slug'     => $l->post_name,
                    'title'    => $l->post_title,
                    'content'  => NWWS_Content_Cleaner::clean( $l->post_content ),
                    'topicId'  => $l->post_parent !== $course->ID ? $l->post_parent : null,
                    'order'    => $l->menu_order,
                    'duration' => get_post_meta( $l->ID, '_tutor_lesson_duration', true ) ?: null,
                ], $lessons ),
                'seo'         => self::get_seo_meta( $course->ID ),
                'publishedAt' => $course->post_date,
            ];
        }, $query->posts );

        self::wpml_restore_language( $prev_lang );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── Navigatiemenu's ──────────────────────────────────────────────────────

    public static function get_menus(): WP_REST_Response {
        $menus = wp_get_nav_menus();
        $data  = [];

        // Detect registered menu locations
        $locations     = get_nav_menu_locations();
        $loc_by_menu   = array_flip( $locations ); // term_id => location_slug

        // WPML_LS_Render hooks into wp_get_nav_menu_items to inject language-switcher items.
        // On multilingual sites this filter calls wp_get_nav_menu_items() recursively,
        // causing an infinite loop. Remove it for this migration-only read and restore after.
        global $wp_filter;
        $saved_nav_filters = $wp_filter['wp_get_nav_menu_items'] ?? null;
        remove_all_filters( 'wp_get_nav_menu_items' );

        foreach ( $menus as $menu ) {
            // suppress_filters=true: prevents WPML/WCML from intercepting the WP_Query
            // (posts_where / posts_join filters) that loads nav_menu_item posts.
            $flat_items = wp_get_nav_menu_items( $menu->term_id, [ 'suppress_filters' => true ] );
            if ( ! $flat_items ) $flat_items = [];

            // Build rich item map
            $item_map = [];
            foreach ( $flat_items as $item ) {
                $classes    = array_filter( (array) $item->classes );
                $is_mega    = self::detect_mega_menu( $item );
                $item_map[ $item->ID ] = [
                    'id'          => $item->ID,
                    'title'       => $item->title,
                    'url'         => $item->url,
                    'target'      => $item->target ?: null,
                    'description' => $item->description ?: null,
                    'classes'     => array_values( $classes ),
                    'isMega'      => $is_mega,
                    'parentId'    => (int) $item->menu_item_parent ?: null,
                    'depth'       => 0,
                    'order'       => $item->menu_order,
                    'children'    => [],
                ];
            }

            // Assign depths and build tree
            $tree = self::build_menu_tree( $item_map );

            $data[] = [
                'id'       => $menu->term_id,
                'name'     => $menu->name,
                'slug'     => $menu->slug,
                'location' => $loc_by_menu[ $menu->term_id ] ?? null,
                'items'    => $tree,
            ];
        }

        // Restore wp_get_nav_menu_items filters removed above.
        if ( $saved_nav_filters !== null ) {
            $wp_filter['wp_get_nav_menu_items'] = $saved_nav_filters;
        }

        return new WP_REST_Response( [ 'items' => $data ] );
    }

    /**
     * Turn flat item map (keyed by ID) into a nested tree.
     * Returns only root-level items; children are nested recursively.
     */
    private static function build_menu_tree( array &$map, int $parent_id = 0, int $depth = 0 ): array {
        $branch = [];
        foreach ( $map as $id => $item ) {
            if ( (int) $item['parentId'] !== $parent_id ) continue;
            $map[ $id ]['depth']    = $depth;
            $map[ $id ]['children'] = self::build_menu_tree( $map, $id, $depth + 1 );
            $branch[]               = $map[ $id ];
        }
        usort( $branch, fn( $a, $b ) => $a['order'] <=> $b['order'] );
        return $branch;
    }

    /**
     * Detect if a menu item is a mega menu trigger.
     * Supports: Max Mega Menu plugin, common class conventions, theme data.
     */
    private static function detect_mega_menu( WP_Post $item ): bool {
        // Max Mega Menu plugin stores settings in meta
        $mmm = get_post_meta( $item->ID, '_menu_item_mega_menu', true );
        if ( ! empty( $mmm ) ) return true;

        // Check common CSS class names
        $classes = implode( ' ', (array) $item->classes );
        $mega_indicators = [ 'mega-menu', 'mega_menu', 'megamenu', 'has-mega-menu', 'menu-mega' ];
        foreach ( $mega_indicators as $indicator ) {
            if ( str_contains( $classes, $indicator ) ) return true;
        }

        return false;
    }

    // ─── Elementor Theme Builder templates ───────────────────────────────────

    public static function get_elementor_templates( WP_REST_Request $req ): WP_REST_Response {
        if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
            return new WP_REST_Response( [ 'error' => 'Elementor niet actief' ], 400 );
        }

        $type   = $req->get_param( 'type' ) ?: null; // filter by template type
        $page   = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
        $per    = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
        $offset = ( $page - 1 ) * $per;

        $args = [
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => $per,
            'offset'         => $offset,
        ];

        if ( $type ) {
            $args['meta_query'] = [ [
                'key'   => '_elementor_template_type',
                'value' => $type,
            ] ];
        }

        $query = new WP_Query( $args );
        $total = $query->found_posts;

        // Site-wide conditions from Elementor's option (maps template IDs to conditions)
        $all_conditions = get_option( 'elementor_pro_conditions', [] );

        $data = array_map( function( WP_Post $tpl ) use ( $all_conditions ) {
            $tpl_type   = get_post_meta( $tpl->ID, '_elementor_template_type', true );
            $conditions = $all_conditions[ $tpl->ID ] ?? [];

            // Parse Elementor content
            $parsed   = NWWS_Elementor_Parser::parse( $tpl->ID );
            $sections = $parsed['sections'] ?? [];

            return [
                'id'         => $tpl->ID,
                'name'       => $tpl->post_title,
                'slug'       => $tpl->post_name,
                'type'       => $tpl_type,  // product | header | footer | section | popup | page | loop-item | …
                'conditions' => $conditions, // e.g. [["include","product","all"], ["include","product","id/196020"]]
                'sections'   => $sections,
                'updatedAt'  => $tpl->post_modified,
            ];
        }, $query->posts );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    // ─── Global design (Elementor Kit) ───────────────────────────────────────

    public static function get_global_design(): WP_REST_Response {
        $kit    = NWWS_Elementor_Parser::get_global_design();
        $theme  = self::get_theme_design();
        return new WP_REST_Response( array_merge( $theme, $kit ) );
    }

    // ─── Stock check (voor live chat widget) ─────────────────────────────────

    public static function get_stock( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $search = sanitize_text_field( (string) $req->get_param( 'search' ) );
        if ( ! $search ) {
            return new WP_REST_Response( [ 'error' => 'search parameter vereist' ], 400 );
        }

        $query = new WC_Product_Query( [
            'status'  => 'publish',
            'limit'   => 5,
            's'       => $search,
            'return'  => 'objects',
        ] );

        $results = [];
        foreach ( $query->get_products() as $product ) {
            $results[] = [
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'sku'         => $product->get_sku(),
                'stock'       => $product->get_stock_quantity(),
                'stockStatus' => $product->get_stock_status(), // instock | outofstock | onbackorder
                'manageStock' => $product->get_manage_stock(),
            ];
        }

        if ( empty( $results ) ) {
            return new WP_REST_Response( [ 'error' => 'Geen producten gevonden' ], 404 );
        }

        return new WP_REST_Response( [ 'products' => $results ] );
    }

    // ─── Order Status (voor live chat widget) ────────────────────────────────

    public static function get_order_status( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $order_id = (int) $req->get_param( 'order_id' );
        $email    = sanitize_email( (string) $req->get_param( 'email' ) );

        if ( ! $order_id || ! $email ) {
            return new WP_REST_Response( [ 'error' => 'order_id en email zijn verplicht' ], 400 );
        }

        // First try by internal order ID; fall back to searching by displayed order number.
        // This handles plugins (e.g. Sequential Order Numbers) where displayed # ≠ post ID.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $found = wc_get_orders( [
                'limit'        => 1,
                'order_number' => (string) $order_id,
                'status'       => 'any',
            ] );
            $order = ! empty( $found ) ? $found[0] : false;
        }
        if ( ! $order ) {
            return new WP_REST_Response( [ 'error' => 'Bestelling niet gevonden' ], 404 );
        }

        // Verify email matches billing email — voorkomt dat klanten elkaars orders opvragen
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return new WP_REST_Response( [ 'error' => 'Bestelling niet gevonden' ], 404 );
        }

        $status_labels = [
            'pending'    => 'Wacht op betaling',
            'processing' => 'In behandeling',
            'on-hold'    => 'In de wacht',
            'completed'  => 'Verzonden / voltooid',
            'cancelled'  => 'Geannuleerd',
            'refunded'   => 'Terugbetaald',
            'failed'     => 'Mislukt',
        ];
        $status = $order->get_status();

        // Tracking — WooCommerce Shipment Tracking plugin, met fallback op losse meta
        $tracking = null;
        $tracking_items = $order->get_meta( '_wc_shipment_tracking_items', true );
        if ( is_array( $tracking_items ) && ! empty( $tracking_items ) ) {
            $t = $tracking_items[0];
            $tracking = [
                'number'  => $t['tracking_number'] ?? null,
                'carrier' => $t['tracking_provider'] ?? null,
                'url'     => $t['tracking_link'] ?? null,
            ];
        } else {
            $simple = $order->get_meta( '_tracking_number', true );
            if ( $simple ) {
                $tracking = [ 'number' => $simple, 'carrier' => null, 'url' => null ];
            }
        }

        $items = array_map( function ( WC_Order_Item_Product $item ) {
            return [
                'name' => $item->get_name(),
                'qty'  => $item->get_quantity(),
            ];
        }, array_values( $order->get_items() ) );

        return new WP_REST_Response( [
            'orderId'     => $order->get_id(),
            'status'      => $status,
            'statusLabel' => $status_labels[ $status ] ?? ucfirst( $status ),
            'total'       => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            'items'       => $items,
            'createdAt'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
            'tracking'    => $tracking,
        ] );
    }

    // ─── Media binary stream (hotlink/WAF bypass) ─────────────────────────────

    /**
     * Streamt een /wp-content/uploads/ bestand binair via PHP.
     *
     * Waarom:
     *   Cloudflare/Wordfence hotlink-protection blokkeert externe origins die
     *   afbeeldingen rechtstreeks ophalen. De Neuramerce migrator moet wel bij
     *   de bron-bytes om te kunnen rehosten — dit endpoint doet dat met onze
     *   eigen API-key in plaats van publieke hotlinks.
     *
     * Beveiliging:
     *   - Host moet exact matchen met home_url() — voorkomt SSRF naar willekeurige
     *     domeinen via een opgegeven URL.
     *   - Pad moet onder de WordPress uploads-directory liggen, geverifieerd via
     *     realpath() vergelijking — voorkomt path traversal (../) aanvallen.
     *   - Bestand mag max 20 MB zijn — voorkomt geheugen-uitputting en misbruik.
     *   - Auth via bestaande X-Neuramerce-Key / Bearer token check.
     */
    public static function get_media_binary( WP_REST_Request $req ) {
        $raw = sanitize_text_field( (string) $req->get_param( 'url' ) );
        if ( ! filter_var( $raw, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'Ongeldige URL', [ 'status' => 400 ] );
        }

        // Host match check — alleen onze eigen site mag worden uitgelezen
        $req_parts  = parse_url( $raw );
        $site_parts = parse_url( home_url() );
        $req_host   = strtolower( $req_parts['host']  ?? '' );
        $site_host  = strtolower( $site_parts['host'] ?? '' );
        if ( ! $req_host || $req_host !== $site_host ) {
            return new WP_Error( 'host_mismatch', 'URL hoort niet bij deze site', [ 'status' => 403 ] );
        }

        $path = $req_parts['path'] ?? '';
        if ( ! str_contains( $path, '/wp-content/uploads/' ) ) {
            return new WP_Error( 'not_uploads', 'URL valt niet binnen /wp-content/uploads/', [ 'status' => 403 ] );
        }

        // Bouw lokaal bestandspad via wp_upload_dir() — veiliger dan ABSPATH concat
        $upload  = wp_upload_dir();
        $basedir = $upload['basedir'] ?? '';
        $baseurl = $upload['baseurl'] ?? '';

        // Vervang baseurl door basedir om naar het lokale pad te mappen.
        // Strip query/fragment van de input voordat we vergelijken.
        $clean_url = ( $req_parts['scheme'] ?? 'https' ) . '://' . $req_host . $path;
        if ( ! str_starts_with( $clean_url, $baseurl ) ) {
            // Fallback: misschien staat baseurl op http vs https mismatch — vergelijk pad-segment
            $base_path = parse_url( $baseurl, PHP_URL_PATH ) ?? '/wp-content/uploads';
            if ( ! str_starts_with( $path, $base_path ) ) {
                return new WP_Error( 'not_uploads', 'Pad valt buiten uploads dir', [ 'status' => 403 ] );
            }
            $relative = substr( $path, strlen( $base_path ) );
            $file     = $basedir . $relative;
        } else {
            $file = $basedir . substr( $clean_url, strlen( $baseurl ) );
        }

        // Bestaat het bestand?
        if ( ! file_exists( $file ) || ! is_file( $file ) ) {
            return new WP_Error( 'not_found', 'Bestand niet gevonden', [ 'status' => 404 ] );
        }

        // Path traversal verdediging — realpath van zowel bestand als basedir,
        // controleer dat het opgeloste pad onder de uploads-root ligt.
        $real_file = realpath( $file );
        $real_base = realpath( $basedir );
        if ( ! $real_file || ! $real_base || ! str_starts_with( $real_file, $real_base . DIRECTORY_SEPARATOR ) ) {
            return new WP_Error( 'forbidden', 'Pad valt buiten uploads dir', [ 'status' => 403 ] );
        }

        // Filesize check — bescherm tegen memory blow-ups
        $size = filesize( $real_file );
        if ( $size === false ) {
            return new WP_Error( 'stat_failed', 'Kan bestandsgrootte niet bepalen', [ 'status' => 500 ] );
        }
        $max = 20 * 1024 * 1024; // 20 MB
        if ( $size > $max ) {
            return new WP_Error( 'too_large', 'Bestand te groot (>20MB)', [ 'status' => 413 ] );
        }

        // MIME-type bepalen
        $type_info = wp_check_filetype( $real_file );
        $mime      = $type_info['type'] ?? null;
        if ( ! $mime && function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $real_file ) ?: null;
                finfo_close( $finfo );
            }
        }
        $mime = $mime ?: 'application/octet-stream';

        // Stream het bestand naar de client en stop WP voordat het iets toevoegt
        status_header( 200 );
        nocache_headers(); // Verwijder WP's no-cache; we zetten zelf cache-control hieronder
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . $size );
        header( 'Cache-Control: public, max-age=3600' );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $real_file );
        exit;
    }

    /**
     * Diagnose endpoint — geeft uploads dir + eerste paar .jpg bestanden terug
     * zodat developers kunnen verifiëren dat de media-binary streamer werkt
     * zonder een specifieke URL nodig te hebben.
     */
    public static function get_media_binary_test(): WP_REST_Response {
        $upload  = wp_upload_dir();
        $basedir = $upload['basedir'] ?? '';

        $samples = [];
        if ( $basedir && is_dir( $basedir ) ) {
            $files = glob( $basedir . '/*.jpg' ) ?: [];
            foreach ( array_slice( $files, 0, 3 ) as $f ) {
                $samples[] = basename( $f );
            }
        }

        return new WP_REST_Response( [
            'ok'           => true,
            'version'      => defined( 'NWWS_VERSION' ) ? NWWS_VERSION : null,
            'uploads_dir'  => $basedir,
            'sample_files' => $samples,
        ] );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * SEO meta: ondersteunt Yoast SEO, RankMath en All in One SEO.
     */
    private static function get_seo_meta( int $post_id ): array {
        // Yoast SEO
        $yoast_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
        $yoast_desc  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        if ( $yoast_title || $yoast_desc ) {
            return [ 'title' => $yoast_title, 'description' => $yoast_desc, 'source' => 'yoast' ];
        }

        // RankMath
        $rm_title = get_post_meta( $post_id, 'rank_math_title', true );
        $rm_desc  = get_post_meta( $post_id, 'rank_math_description', true );
        if ( $rm_title || $rm_desc ) {
            return [ 'title' => $rm_title, 'description' => $rm_desc, 'source' => 'rankmath' ];
        }

        // All in One SEO
        $aio_title = get_post_meta( $post_id, '_aioseop_title', true );
        $aio_desc  = get_post_meta( $post_id, '_aioseop_description', true );
        if ( $aio_title || $aio_desc ) {
            return [ 'title' => $aio_title, 'description' => $aio_desc, 'source' => 'aioseo' ];
        }

        return [ 'title' => null, 'description' => null, 'source' => null ];
    }

    /**
     * Probeer design tokens uit het actieve theme te halen (theme.json).
     */
    private static function get_theme_design(): array {
        $theme_json_path = get_template_directory() . '/theme.json';
        if ( ! file_exists( $theme_json_path ) ) return [];

        $json = json_decode( file_get_contents( $theme_json_path ), true );
        if ( ! $json ) return [];

        $settings = $json['settings'] ?? [];
        $design   = [];

        if ( ! empty( $settings['color']['palette'] ) ) {
            $design['colors'] = array_map( fn( $c ) => [
                'title' => $c['name']  ?? '',
                'color' => $c['color'] ?? '',
            ], $settings['color']['palette'] );
        }

        if ( ! empty( $settings['typography']['fontFamilies'] ) ) {
            $design['fonts'] = array_map( fn( $f ) => [
                'title'  => $f['name']       ?? '',
                'family' => $f['fontFamily'] ?? '',
            ], $settings['typography']['fontFamilies'] );
        }

        return $design;
    }

    private static function count_wc_orders(): int {
        return array_sum( array_map( 'wc_orders_count', [ 'pending', 'processing', 'on-hold', 'completed' ] ) );
    }

    private static function count_wc_reviews(): int {
        return (int) get_comments( [ 'type' => 'review', 'status' => 'approve', 'count' => true ] );
    }

    private static function count_wc_customers(): int {
        return (int) ( count_users()['avail_roles']['customer'] ?? 0 );
    }

    // ─── WPML helpers ─────────────────────────────────────────────────────────

    // ─── Current customer (publiek, via WP sessie) ────────────────────────────

    public static function get_current_customer( WP_REST_Request $req ): WP_REST_Response {
        if ( ! is_user_logged_in() ) {
            return new WP_REST_Response( [ 'loggedIn' => false ], 200 );
        }

        $user = wp_get_current_user();
        // Prefer first + last name (personal), fall back to display_name (often company), then login
        $name = trim( ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) );
        if ( ! $name ) {
            $name = trim( $user->display_name ?? '' );
        }

        $response = new WP_REST_Response( [
            'loggedIn' => true,
            'email'    => sanitize_email( $user->user_email ?? '' ),
            'name'     => esc_html( $name ?: $user->user_login ),
        ], 200 );

        // Sta cross-origin toe (widget draait als iframe van Neuramerce domein)
        $response->header( 'Access-Control-Allow-Origin',  '*' );
        $response->header( 'Access-Control-Allow-Methods', 'GET' );

        return $response;
    }

    /**
     * Parse a WPZOOM Recipe Card Blocks post_content and extract structured recipe data.
     * The plugin stores all data in the Gutenberg block attributes as JSON.
     * Returns an empty array if no WPZOOM recipe block is found.
     */
    private static function parse_wpzoom_recipe( string $post_content ): array {
        if ( ! has_blocks( $post_content ) ) return [];

        foreach ( parse_blocks( $post_content ) as $block ) {
            if ( $block['blockName'] !== 'wpzoom-recipe-card/block-recipe-card' ) continue;

            $a = $block['attrs'];

            // ── Timing & servings from the "details" array ──────────────────
            $servings = null;
            $prep_time = null;
            $cook_time = null;

            foreach ( $a['details'] ?? [] as $d ) {
                $label = strtolower( $d['jsonLabel'] ?? $d['label'] ?? '' );
                $val   = $d['value'] ?? null;
                if ( ! $val ) continue;

                if ( str_contains( $label, 'person' ) || str_contains( $label, 'portie' ) ||
                     str_contains( $label, 'serving' ) || str_contains( $label, 'personen' ) ) {
                    $servings = (string) $val;
                } elseif ( str_contains( $label, 'voorbereid' ) || str_contains( $label, 'prep' ) ||
                           $label === 'voorbereiding' ) {
                    $prep_time = (int) $val;
                } elseif ( str_contains( $label, 'bbq' ) || str_contains( $label, 'cook' ) ||
                           str_contains( $label, 'bereiding' ) || $label === 'tijd op bbq' ) {
                    $cook_time = (int) $val;
                }
            }

            // ── Ingredients ─────────────────────────────────────────────────
            $ingredients = [];
            foreach ( $a['ingredients'] ?? [] as $ing ) {
                if ( $ing['isGroup'] ?? false ) {
                    $ingredients[] = [ 'group' => $ing['jsonName'] ?? ( is_array( $ing['name'] ?? null ) ? implode( '', $ing['name'] ) : ( $ing['name'] ?? '' ) ) ];
                    continue;
                }
                $p = $ing['parse'] ?? [];
                $ingredients[] = [
                    'amount'     => $p['amount'] ?? null,
                    'unit'       => $p['unit']   ?? null,
                    'ingredient' => $ing['jsonName'] ?? ( is_array( $ing['name'] ?? null ) ? implode( '', $ing['name'] ) : ( $ing['name'] ?? '' ) ),
                ];
            }

            // ── Steps ────────────────────────────────────────────────────────
            $steps = [];
            foreach ( $a['steps'] ?? [] as $step ) {
                if ( $step['isGroup'] ?? false ) {
                    $steps[] = [ 'group' => $step['jsonGroupTitle'] ?? ( $step['groupTitle'] ?? '' ) ];
                    continue;
                }
                // step text may be a string or an inline-rich-text array
                $raw_text = $step['jsonText'] ?? null;
                if ( $raw_text === null ) {
                    $raw_text = is_array( $step['text'] ?? null )
                        ? implode( '', $step['text'] )
                        : ( $step['text'] ?? '' );
                }
                $steps[] = [
                    'text'  => NWWS_Content_Cleaner::clean( $raw_text ),
                    'image' => $step['image']['url'] ?? null,
                ];
            }

            return [
                'title'       => $a['recipeTitle'] ?? null,
                'summary'     => $a['jsonSummary'] ?? $a['summary'] ?? null,
                'course'      => $a['course']     ?? [],
                'cuisine'     => $a['cuisine']    ?? [],
                'difficulty'  => $a['difficulty'][0] ?? null,
                'keywords'    => $a['keywords']   ?? [],
                'servings'    => $servings,
                'prepTime'    => $prep_time,
                'cookTime'    => $cook_time,
                'ingredients' => $ingredients,
                'steps'       => $steps,
                'image'       => $a['image']['url'] ?? null,
            ];
        }

        return [];
    }

    /**
     * Switch WPML to Dutch (nl). Returns the previous language so it can be restored.
     * Returns null if WPML is not active.
     */
    private static function wpml_switch_to_nl(): ?string {
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) return null;
        $prev = apply_filters( 'wpml_current_language', null );
        do_action( 'wpml_switch_language', 'nl' );
        return $prev;
    }

    /**
     * Restore WPML to a previously saved language.
     */
    private static function wpml_restore_language( ?string $lang ): void {
        if ( $lang === null ) return;
        do_action( 'wpml_switch_language', $lang );
    }

    // ─── Order statussen + stock-teksten — push vanuit Neuramerce ────────────

    /**
     * Dispatch /order-statuses naar GET (live-fetch) of POST (sync).
     *
     * Review-fix v1.11.0: één register_rest_route call met dual-method-mask
     * voorkomt edge-case op multisite waar twee aparte route-registraties
     * elkaar kunnen overriden bij dubbele plugin-init.
     */
    public static function order_statuses_dispatch( WP_REST_Request $req ) {
        return $req->get_method() === 'GET'
            ? self::get_order_statuses_live( $req )
            : self::sync_order_statuses( $req );
    }

    /**
     * Vervang de volledige lijst custom orderstatussen met de payload uit Neura.
     * Volgens dit patroon ondersteunen we ook deletes (Neura-snapshot is leidend).
     *
     * Statussen worden opgeslagen in WP option 'nwws_custom_order_statuses' en
     * geregistreerd via register_post_status() + wc_order_statuses filter (zie
     * neura-wp-woo-sync-server.php → nwws_register_custom_statuses).
     */
    public static function sync_order_statuses( WP_REST_Request $req ) {
        $body = $req->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'invalid_body', 'Verwacht een JSON array', [ 'status' => 400 ] );
        }

        $clean = [];
        foreach ( $body as $row ) {
            if ( ! is_array( $row ) || empty( $row['key'] ) ) continue;
            $clean[] = [
                'key'             => sanitize_key( (string) $row['key'] ),
                'label'           => sanitize_text_field( (string) ( $row['label'] ?? $row['key'] ) ),
                'color'           => sanitize_text_field( (string) ( $row['color'] ?? '#a78bfa' ) ),
                'wc_maps_to'      => isset( $row['wc_maps_to'] ) ? sanitize_key( (string) $row['wc_maps_to'] ) : '',
                'counts_as_paid'  => ! empty( $row['counts_as_paid'] ),
                'is_active'       => isset( $row['is_active'] ) ? (bool) $row['is_active'] : true,
            ];
        }

        update_option( 'nwws_custom_order_statuses', $clean, false );

        return new WP_REST_Response( [ 'ok' => true, 'count' => count( $clean ) ] );
    }

    /**
     * GET /order-statuses — v1.11.0 live-fetch (S5).
     *
     * Returnt actieve wc-<key> post_statuses zoals WC ze daadwerkelijk kent
     * via get_post_stati(). Bron-van-waarheid voor Neura's
     * safeguard_status_mapping_consistency — als WP-option `nwws_custom_order_statuses`
     * de status bevat maar register_post_status-hook is om de een of andere
     * reden niet gelopen, valt dat hier op (status staat in option maar niet
     * in registry).
     *
     * Response shape:
     *   {
     *     "registered_post_statuses": [{ key: "wc-naar-warehouse", label: "Naar warehouse" }, ...],
     *     "wc_order_statuses":        [{ key: "wc-processing", label: "Processing" }, ...],
     *     "stored_in_option":         [{ key, label, color, wc_maps_to, counts_as_paid, is_active }, ...],
     *     "plugin_version":           "1.11.0"
     *   }
     */
    public static function get_order_statuses_live( WP_REST_Request $req ) {
        // 1. Alle geregistreerde post_status met `wc-`-prefix uit registry.
        //    get_post_stati() returnt ALLE post_status objects — we filteren op
        //    naam en mappen naar key+label.
        $all_post_statuses = get_post_stati( [], 'objects' );
        $registered = [];
        foreach ( $all_post_statuses as $key => $obj ) {
            if ( strpos( $key, 'wc-' ) !== 0 ) continue;
            $registered[] = [
                'key'   => $key,
                'label' => isset( $obj->label ) ? (string) $obj->label : $key,
            ];
        }

        // 2. WC's eigen wc_get_order_statuses() — deze respecteert de
        //    `wc_order_statuses`-filter en is wat de admin-dropdown gebruikt.
        $wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
        $wc_list = [];
        foreach ( $wc_statuses as $key => $label ) {
            $wc_list[] = [
                'key'   => sanitize_key( $key ),
                'label' => sanitize_text_field( (string) $label ),
            ];
        }

        // 3. Wat in WP-option staat — voor diff-detectie ("option zegt X maar
        //    registry kent X niet" = drift, register_post_status hook gemist).
        $stored = get_option( 'nwws_custom_order_statuses', [] );
        if ( ! is_array( $stored ) ) $stored = [];

        return new WP_REST_Response( [
            'registered_post_statuses' => $registered,
            'wc_order_statuses'        => $wc_list,
            'stored_in_option'         => $stored,
            'plugin_version'           => defined( 'NWWS_VERSION' ) ? NWWS_VERSION : 'unknown',
            'fetched_at'               => current_time( 'c' ),
        ] );
    }

    /**
     * Bulk-update voorraadstatus-tekst op WC-producten en -categorieën.
     * Slaat op als post_meta '_nwws_stock_status_text' resp. term_meta met
     * dezelfde key. Wordt gerenderd door de woocommerce_get_availability filter
     * in neura-wp-woo-sync-server.php (vervangt Woo Custom Stock Status Pro).
     *
     * Body: { products: [{ wp_id: 123, text: "..." }], categories: [...] }
     * `text: null` (of lege string) → meta verwijderen → val terug op WC-default.
     */
    public static function sync_stock_texts( WP_REST_Request $req ) {
        $body = $req->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'invalid_body', 'Verwacht een JSON object', [ 'status' => 400 ] );
        }

        $products   = is_array( $body['products']   ?? null ) ? $body['products']   : [];
        $categories = is_array( $body['categories'] ?? null ) ? $body['categories'] : [];

        $product_count  = 0;
        $category_count = 0;

        foreach ( $products as $row ) {
            if ( ! is_array( $row ) || empty( $row['wp_id'] ) ) continue;
            $pid  = absint( $row['wp_id'] );
            $text = isset( $row['text'] ) && $row['text'] !== null ? sanitize_text_field( (string) $row['text'] ) : '';

            if ( $text === '' ) {
                delete_post_meta( $pid, '_nwws_stock_status_text' );
            } else {
                update_post_meta( $pid, '_nwws_stock_status_text', $text );
            }
            $product_count++;
        }

        foreach ( $categories as $row ) {
            if ( ! is_array( $row ) || empty( $row['wp_id'] ) ) continue;
            $tid  = absint( $row['wp_id'] );
            $text = isset( $row['text'] ) && $row['text'] !== null ? sanitize_text_field( (string) $row['text'] ) : '';

            if ( $text === '' ) {
                delete_term_meta( $tid, '_nwws_stock_status_text' );
            } else {
                update_term_meta( $tid, '_nwws_stock_status_text', $text );
            }
            $category_count++;
        }

        return new WP_REST_Response( [
            'ok'         => true,
            'products'   => $product_count,
            'categories' => $category_count,
        ] );
    }

    /**
     * Zet custom status (wc-<key>) op een specifieke WC-order plus optionele
     * leverdatum als order-meta. Gebruikt $order->set_status() (HPOS-compat,
     * werkt op zowel post-status- als COT-mode).
     *
     * Body: { status_key: "delivery-planned", planned_delivery_at?: "2026-05-10T12:00:00.000Z" }
     */
    public static function set_order_status( WP_REST_Request $req ) {
        $order_id = absint( $req->get_param( 'id' ) );
        $body     = $req->get_json_params();

        if ( ! is_array( $body ) || empty( $body['status_key'] ) ) {
            return new WP_Error( 'invalid_body', 'status_key vereist', [ 'status' => 400 ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order niet gevonden', [ 'status' => 404 ] );
        }

        $status_key = sanitize_key( (string) $body['status_key'] );

        // Verifieer dat deze status bestaat in onze lijst — voorkomt arbitrary status injecten
        $known = get_option( 'nwws_custom_order_statuses', [] );
        $valid_keys = array_column( is_array( $known ) ? $known : [], 'key' );
        if ( ! in_array( $status_key, $valid_keys, true ) ) {
            return new WP_Error( 'unknown_status', 'Onbekende status — sync eerst /order-statuses', [ 'status' => 400 ] );
        }

        $order->set_status( 'wc-' . $status_key, 'Status gezet via Neura' );

        if ( ! empty( $body['planned_delivery_at'] ) ) {
            $order->update_meta_data( '_nwws_planned_delivery_at', sanitize_text_field( (string) $body['planned_delivery_at'] ) );
        } else {
            $order->delete_meta_data( '_nwws_planned_delivery_at' );
        }

        $order->save();

        return new WP_REST_Response( [
            'ok'         => true,
            'order_id'   => $order_id,
            'status_key' => $status_key,
        ] );
    }

    /**
     * POST /shipping-schedules — slaat de volledige shipping-config op als 3
     * WP-options. nwws_render_shipping_info (in main plugin) leest deze om
     * "Vóór 16:00 besteld? Dinsdag verzonden." te tonen op productpagina's.
     */
    public static function sync_shipping_schedules( WP_REST_Request $req ) {
        $body = $req->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'invalid_body', 'Body moet JSON-object zijn', [ 'status' => 400 ] );
        }

        // Default schedule (workspace-breed). Vorm: { week_config, message_tpl }
        if ( isset( $body['default'] ) && is_array( $body['default'] ) ) {
            update_option( 'nwws_shipping_default', $body['default'], false );
        } elseif ( array_key_exists( 'default', $body ) && $body['default'] === null ) {
            delete_option( 'nwws_shipping_default' );
        }

        // Per-categorie overrides — array van { category_slug, week_config, message_tpl }
        $overrides = isset( $body['overrides'] ) && is_array( $body['overrides'] )
            ? array_values( $body['overrides'] )
            : [];
        update_option( 'nwws_shipping_overrides', $overrides, false );

        // Datum-excepties — array van { date, type, label }
        $exceptions = isset( $body['exceptions'] ) && is_array( $body['exceptions'] )
            ? array_values( $body['exceptions'] )
            : [];
        update_option( 'nwws_shipping_exceptions', $exceptions, false );

        return new WP_REST_Response( [
            'ok'                => true,
            'overrides_count'   => count( $overrides ),
            'exceptions_count'  => count( $exceptions ),
            'has_default'       => !empty( $body['default'] ),
        ] );
    }
}

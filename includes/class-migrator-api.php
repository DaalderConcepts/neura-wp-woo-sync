<?php
/**
 * REST API endpoints voor Neuramerce Migrator.
 * Base: /wp-json/neuramerce/v1/
 *
 * Authenticatie: X-Neuramerce-Key header
 */
class NWWS_Migrator_API {

    const NAMESPACE = 'neuramerce/v1';

    public static function register_routes(): void {
        $auth = fn( $r ) => NWWS_Migrator_Auth::validate( $r )
            ? true
            : new WP_Error( 'unauthorized', 'Ongeldige API key', [ 'status' => 401 ] );

        $routes = [
            'status'              => 'get_status',
            'site'                => 'get_site',
            'pages'               => 'get_pages',
            'posts'               => 'get_posts',
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

        foreach ( $routes as $route => $method ) {
            register_rest_route( self::NAMESPACE, '/' . $route, [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, $method ],
                'permission_callback' => $auth,
            ] );
        }

        // order-status is publiek — beveiliging zit in ordernummer + e-mail combinatie.
        // Geen Neuramerce API key nodig zodat het altijd werkt vanuit de chat-widget.
        register_rest_route( self::NAMESPACE, '/order-status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_order_status' ],
            'permission_callback' => '__return_true',
        ] );

        // Auth verify (POST)
        register_rest_route( self::NAMESPACE, '/auth/verify', [
            'methods'             => 'POST',
            'callback'            => fn() => new WP_REST_Response( [ 'ok' => true ] ),
            'permission_callback' => $auth,
        ] );

        // Current customer — publiek (gebruikt WP sessie-cookie van bezoeker)
        // Geeft ingelogde WP/WooCommerce gebruiker terug zodat widget naam+email weet
        register_rest_route( self::NAMESPACE, '/current-customer', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_current_customer' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ─── Status ───────────────────────────────────────────────────────────────

    public static function get_status(): WP_REST_Response {
        $wc_active   = class_exists( 'WooCommerce' );
        $wpml_active = defined( 'ICL_SITEPRESS_VERSION' );
        $wpml_langs  = $wpml_active
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

        $total = $query->found_posts;
        $data  = array_map( fn( $p ) => self::format_page( $p ), $query->posts );

        self::wpml_restore_language( $prev_lang );

        return new WP_REST_Response( [
            'total'    => (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    private static function format_page( WP_Post $post ): array {
        $is_elementor = ! empty( get_post_meta( $post->ID, '_elementor_data', true ) );
        $is_gutenberg = ! $is_elementor && has_blocks( $post->post_content );

        // Parse naar secties
        if ( $is_elementor ) {
            $parsed   = NWWS_Elementor_Parser::parse( $post->ID );
            $sections = $parsed['sections'];
            $builder  = 'elementor';
        } elseif ( $is_gutenberg ) {
            $sections = NWWS_Gutenberg_Parser::parse( $post->post_content );
            $builder  = 'gutenberg';
        } else {
            // Classic editor of plain HTML
            $sections = [ [
                'type'  => 'html',
                'props' => [ 'html' => NWWS_Content_Cleaner::clean( $post->post_content ) ],
            ] ];
            $builder = 'classic';
        }

        $lang = defined( 'ICL_SITEPRESS_VERSION' )
            ? apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $post->ID, 'element_type' => 'post_page' ] )
            : null;

        return [
            'id'          => $post->ID,
            'slug'        => $post->post_name,
            'title'       => $post->post_title,
            'status'      => $post->post_status,
            'parentId'    => $post->post_parent ?: null,
            'menuOrder'   => $post->menu_order,
            'lang'        => $lang ?? 'nl',
            'builder'     => $builder,
            'sections'    => $sections,
            'seo'         => self::get_seo_meta( $post->ID ),
            'featuredImg' => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null,
            'createdAt'   => $post->post_date,
            'updatedAt'   => $post->post_modified,
        ];
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

        $total = $query->found_posts;
        $posts = $query->posts;

        self::wpml_restore_language( $prev_lang );

        $data = array_map( function( $p ) {
            $is_elementor = ! empty( get_post_meta( $p->ID, '_elementor_data', true ) );
            if ( $is_elementor ) {
                $parsed  = NWWS_Elementor_Parser::parse( $p->ID );
                $content = $parsed['sections'];
                $builder = 'elementor';
            } else {
                $content = NWWS_Gutenberg_Parser::parse( $p->post_content );
                $builder = has_blocks( $p->post_content ) ? 'gutenberg' : 'classic';
            }

            // Excerpt: gebruik ingesteld excerpt of eerste 200 tekens van clean content
            $excerpt = $p->post_excerpt ?: wp_trim_words(
                wp_strip_all_tags( $p->post_content ), 30, '…'
            );

            $lang = defined( 'ICL_SITEPRESS_VERSION' )
                ? apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $p->ID, 'element_type' => 'post_post' ] )
                : null;

            return [
                'id'          => $p->ID,
                'slug'        => $p->post_name,
                'title'       => $p->post_title,
                'excerpt'     => $excerpt,
                'lang'        => $lang ?? 'nl',
                'builder'     => $builder,
                'sections'    => $content,
                'seo'         => self::get_seo_meta( $p->ID ),
                'featuredImg' => get_the_post_thumbnail_url( $p->ID, 'full' ) ?: null,
                'categories'  => wp_get_post_terms( $p->ID, 'category', [ 'fields' => 'names' ] ),
                'tags'        => wp_get_post_terms( $p->ID, 'post_tag', [ 'fields' => 'names' ] ),
                'publishedAt' => $p->post_date,
                'updatedAt'   => $p->post_modified,
            ];
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
        $total    = (int) wc_get_products( [ 'return' => 'ids', 'limit' => -1, 'status' => 'publish', 'count_only' => true ] );

        self::wpml_restore_language( $prev_lang );

        $data = array_map( [ __CLASS__, 'format_product' ], $products );

        return new WP_REST_Response( [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) ceil( $total / $per ),
            'items'    => $data,
        ] );
    }

    private static function format_product( WC_Product $p ): array {
        $images = array_map( fn( $id ) => [
            'url' => wp_get_attachment_image_url( $id, 'full' ),
            'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
        ], array_filter( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) ) );

        $variations = [];
        if ( $p->is_type( 'variable' ) ) {
            foreach ( $p->get_children() as $var_id ) {
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
            }
        }

        // Haal merk/kleur/maat op uit product attributes
        $attrs = [];
        foreach ( $p->get_attributes() as $attr ) {
            $name = wc_attribute_label( $attr->get_name() );
            $vals = $attr->is_taxonomy()
                ? wc_get_product_terms( $p->get_id(), $attr->get_name(), [ 'fields' => 'names' ] )
                : $attr->get_options();
            $attrs[ strtolower( $name ) ] = implode( ', ', $vals );
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
    }

    /**
     * Minimal product stub for cross-sells, upsells, bought-together lists.
     * Avoids full format_product recursion.
     */
    private static function format_related_products( array $ids ): array {
        $out = [];
        foreach ( array_slice( array_filter( $ids ), 0, 20 ) as $id ) {
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
        }
        return $out;
    }

    /**
     * Extract product add-on / extra options configuration.
     * Supports: TM Extra Product Options, WooSB bundles.
     */
    private static function get_product_addons( int $pid ): array {
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

        return $result ?: null;
    }

    // ─── WooCommerce Categories ───────────────────────────────────────────────

    public static function get_categories(): WP_REST_Response {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_REST_Response( [ 'error' => 'WooCommerce niet actief' ], 400 );
        }

        $terms = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        $data = array_map( function( $t ) {
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
        }, $terms ?: [] );

        return new WP_REST_Response( [ 'items' => $data, 'total' => count( $data ) ] );
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
                    'settings' => array_map( fn( $s ) => $s['value'], $method->instance_settings ),
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
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            return new WP_REST_Response( [ 'error' => 'Rank Math redirections niet gevonden' ], 404 );
        }

        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status='active' ORDER BY id LIMIT %d OFFSET %d",
            $per, $offset
        ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status='active'" );

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

        foreach ( $menus as $menu ) {
            $flat_items = wp_get_nav_menu_items( $menu->term_id );
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
}

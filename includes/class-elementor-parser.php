<?php
declare(strict_types=1);
/**
 * Parseert _elementor_data JSON naar Neuramerce PageSection-structuur.
 *
 * Elke widget wordt gemapped naar een of meer secties.
 * Widgets die niet direct gemapped worden, komen terecht als 'html' blok.
 */
defined('ABSPATH') || exit;
class NWWS_Elementor_Parser {

    /**
     * Parse Elementor page data naar een array van Neuramerce secties.
     *
     * @param  int $post_id
     * @return array  [ 'sections' => [...], 'design' => [...] ]
     */
    public static function parse( int $post_id ): array {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( empty( $raw ) ) return [ 'sections' => [], 'design' => [] ];

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return [ 'sections' => [], 'design' => [] ];

        $widgets   = self::flatten_widgets( $data );
        $sections  = self::group_into_sections( $widgets );
        $design    = self::extract_design( $post_id );

        return [
            'sections' => $sections,
            'design'   => $design,
        ];
    }

    /**
     * Haal de Elementor Kit (globale stijlen) op als design tokens.
     */
    public static function get_global_design(): array {
        // Elementor Kit is een speciale post van type 'elementor_library'
        $kit_posts = get_posts( [
            'post_type'   => 'elementor_library',
            'meta_key'    => '_elementor_template_type',
            'meta_value'  => 'kit',
            'numberposts' => 1,
        ] );

        if ( empty( $kit_posts ) ) return [];

        $kit_id   = $kit_posts[0]->ID;
        $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( empty( $settings ) ) return [];

        $design = [];

        // Kleuren
        if ( ! empty( $settings['system_colors'] ) ) {
            $design['colors'] = array_map( function( $c ) {
                return [
                    'title' => $c['title'] ?? '',
                    'color' => $c['color'] ?? '',
                ];
            }, $settings['system_colors'] );
        }

        // Typografie
        if ( ! empty( $settings['system_typography'] ) ) {
            $design['fonts'] = array_map( function( $t ) {
                return [
                    'title'  => $t['title'] ?? '',
                    'family' => $t['typography_font_family'] ?? '',
                    'weight' => $t['typography_font_weight'] ?? '',
                ];
            }, $settings['system_typography'] );
        }

        return $design;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Recursief alle widgets uit de boom halen (platte lijst).
     */
    private static function flatten_widgets( array $elements ): array {
        $widgets = [];
        foreach ( $elements as $el ) {
            if ( isset( $el['elType'] ) && $el['elType'] === 'widget' ) {
                $widgets[] = $el;
            }
            if ( ! empty( $el['elements'] ) ) {
                $widgets = array_merge( $widgets, self::flatten_widgets( $el['elements'] ) );
            }
        }
        return $widgets;
    }

    /**
     * Map individuele widgets naar Neuramerce section-objecten.
     * Groepeer opeenvolgende icon-boxes/image-boxes/testimonials als één sectie.
     */
    private static function group_into_sections( array $widgets ): array {
        $sections = [];
        $i = 0;

        while ( $i < count( $widgets ) ) {
            $w    = $widgets[ $i ];
            $type = $w['widgetType'] ?? 'unknown';
            $s    = $w['settings'] ?? [];

            // -- Groepeerbare widget types --

            // Features: icon-box of image-box series
            if ( in_array( $type, [ 'icon-box', 'image-box' ] ) ) {
                $items = [];
                while ( $i < count( $widgets ) && in_array( $widgets[$i]['widgetType'] ?? '', [ 'icon-box', 'image-box' ] ) ) {
                    $ws = $widgets[$i]['settings'] ?? [];
                    $items[] = [
                        'icon'  => $ws['selected_icon']['value'] ?? $ws['icon'] ?? null,
                        'image' => isset( $ws['image']['url'] ) ? $ws['image']['url'] : null,
                        'title' => wp_strip_all_tags( $ws['title'] ?? '' ),
                        'text'  => NWWS_Content_Cleaner::clean( $ws['description'] ?? $ws['description_text'] ?? '' ),
                    ];
                    $i++;
                }
                $sections[] = [ 'type' => 'features', 'props' => [ 'items' => $items ] ];
                continue;
            }

            // Testimonials
            if ( in_array( $type, [ 'testimonial', 'testimonial-carousel' ] ) ) {
                $items = [];
                while ( $i < count( $widgets ) && in_array( $widgets[$i]['widgetType'] ?? '', [ 'testimonial', 'testimonial-carousel' ] ) ) {
                    $ws = $widgets[$i]['settings'] ?? [];
                    $items[] = [
                        'quote'  => NWWS_Content_Cleaner::clean( $ws['testimonial_content'] ?? '' ),
                        'name'   => wp_strip_all_tags( $ws['testimonial_name'] ?? '' ),
                        'title'  => wp_strip_all_tags( $ws['testimonial_job'] ?? '' ),
                        'avatar' => $ws['testimonial_image']['url'] ?? null,
                    ];
                    $i++;
                }
                $sections[] = [ 'type' => 'testimonials', 'props' => [ 'items' => $items ] ];
                continue;
            }

            // Accordion / FAQ
            if ( in_array( $type, [ 'accordion', 'toggle' ] ) ) {
                $items = [];
                foreach ( $s['tabs'] ?? [] as $tab ) {
                    $items[] = [
                        'question' => wp_strip_all_tags( $tab['tab_title'] ?? '' ),
                        'answer'   => NWWS_Content_Cleaner::clean( $tab['tab_content'] ?? '' ),
                    ];
                }
                $sections[] = [ 'type' => 'faq', 'props' => [ 'items' => $items ] ];
                $i++;
                continue;
            }

            // -- Enkelvoudige widget types --

            switch ( $type ) {
                case 'heading':
                    $sections[] = [
                        'type'  => 'heading',
                        'props' => [
                            'text'  => wp_strip_all_tags( $s['title'] ?? '' ),
                            'tag'   => $s['header_size'] ?? 'h2',
                            'align' => $s['align'] ?? 'left',
                        ],
                    ];
                    break;

                case 'text-editor':
                    $sections[] = [
                        'type'  => 'html',
                        'props' => [ 'html' => NWWS_Content_Cleaner::clean( $s['editor'] ?? '' ) ],
                    ];
                    break;

                case 'image':
                    $sections[] = [
                        'type'  => 'image',
                        'props' => [
                            'src'     => $s['image']['url'] ?? '',
                            'alt'     => $s['image']['alt'] ?? '',
                            'caption' => wp_strip_all_tags( $s['caption'] ?? '' ),
                            'link'    => $s['link']['url'] ?? null,
                        ],
                    ];
                    break;

                case 'button':
                    $sections[] = [
                        'type'  => 'cta',
                        'props' => [
                            'label' => wp_strip_all_tags( $s['text'] ?? 'Meer informatie' ),
                            'url'   => $s['link']['url'] ?? '#',
                            'style' => $s['button_type'] ?? 'primary',
                        ],
                    ];
                    break;

                case 'video':
                    $url = $s['youtube_url'] ?? $s['vimeo_url'] ?? $s['external_url']['url'] ?? '';
                    $sections[] = [
                        'type'  => 'video',
                        'props' => [ 'url' => $url ],
                    ];
                    break;

                case 'counter':
                    $sections[] = [
                        'type'  => 'stat',
                        'props' => [
                            'number' => $s['starting_number'] ?? 0,
                            'target' => $s['ending_number'] ?? 0,
                            'label'  => wp_strip_all_tags( $s['suffix'] ?? $s['title'] ?? '' ),
                            'prefix' => $s['prefix'] ?? '',
                        ],
                    ];
                    break;

                case 'divider':
                    $sections[] = [ 'type' => 'divider', 'props' => [] ];
                    break;

                case 'spacer':
                    // Sla spacers over — Neuramerce regelt spacing zelf
                    break;

                // WooCommerce product widgets → overslaan (productpagina template)
                case 'woocommerce-product-title':
                case 'woocommerce-product-images':
                case 'woocommerce-product-price':
                case 'woocommerce-product-add-to-cart':
                case 'woocommerce-product-meta':
                case 'woocommerce-product-short-description':
                    break;

                // HTML / shortcode → clean en opnemen als html blok
                case 'html':
                    $sections[] = [
                        'type'  => 'html',
                        'props' => [ 'html' => NWWS_Content_Cleaner::clean( $s['html'] ?? '' ) ],
                    ];
                    break;

                case 'shortcode':
                    // Shortcodes kunnen we niet uitvoeren — noteer als commentaar
                    $sections[] = [
                        'type'  => 'html',
                        'props' => [ 'html' => '<!-- shortcode: ' . esc_html( $s['shortcode'] ?? '' ) . ' -->' ],
                    ];
                    break;

                default:
                    // Onbekende widget: probeer tekst eruit te persen
                    $fallback = self::extract_text_fallback( $s );
                    if ( ! empty( $fallback ) ) {
                        $sections[] = [
                            'type'  => 'html',
                            'props' => [ 'html' => NWWS_Content_Cleaner::clean( $fallback ) ],
                        ];
                    }
                    break;
            }

            $i++;
        }

        return array_values( array_filter( $sections, fn( $s ) => ! empty( $s ) ) );
    }

    /**
     * Probeer tekstinhoud uit willekeurige widget settings te halen.
     */
    private static function extract_text_fallback( array $settings ): string {
        $text_keys = [ 'text', 'title', 'content', 'description', 'editor', 'html' ];
        foreach ( $text_keys as $key ) {
            if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
                return $settings[ $key ];
            }
        }
        return '';
    }

    /**
     * Haal pagina-specifieke Elementor design settings op.
     */
    private static function extract_design( int $post_id ): array {
        $page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
        if ( empty( $page_settings ) ) return [];

        $design = [];

        if ( ! empty( $page_settings['background_color'] ) ) {
            $design['bgColor'] = $page_settings['background_color'];
        }
        if ( ! empty( $page_settings['background_image']['url'] ) ) {
            $design['bgImage'] = $page_settings['background_image']['url'];
        }

        return $design;
    }
}

<?php
/**
 * Parseert Gutenberg blocks (post_content) naar Neuramerce PageSection-structuur.
 *
 * Gutenberg slaat blocks op als HTML-commentaar: <!-- wp:heading -->..<!-- /wp:heading -->
 * We parsen de block-structuur en mappen naar dezelfde Neuramerce sectie-types als Elementor.
 */
class NWWS_Gutenberg_Parser {

    /**
     * Parse post_content (Gutenberg) naar Neuramerce secties.
     *
     * @param  string $content  post_content van het WP post object
     * @return array
     */
    public static function parse( string $content ): array {
        if ( empty( $content ) ) return [];

        // Gebruik WP's eigen block parser als beschikbaar
        if ( function_exists( 'parse_blocks' ) ) {
            $blocks = parse_blocks( $content );
        } else {
            // Fallback: geef als één html blok terug
            return [ [
                'type'  => 'html',
                'props' => [ 'html' => NWWS_Content_Cleaner::clean( $content ) ],
            ] ];
        }

        $sections = [];
        $i = 0;

        while ( $i < count( $blocks ) ) {
            $block     = $blocks[ $i ];
            $block_name = $block['blockName'] ?? '';
            $attrs     = $block['attrs'] ?? [];
            $inner     = $block['innerHTML'] ?? '';

            // Lege blocks overslaan
            if ( empty( $block_name ) && empty( trim( $inner ) ) ) {
                $i++;
                continue;
            }

            switch ( $block_name ) {

                case 'core/heading':
                    $level = $attrs['level'] ?? 2;
                    $text  = wp_strip_all_tags( $inner );
                    $sections[] = [
                        'type'  => 'heading',
                        'props' => [
                            'text'  => $text,
                            'tag'   => 'h' . $level,
                            'align' => $attrs['textAlign'] ?? 'left',
                        ],
                    ];
                    break;

                case 'core/paragraph':
                    $sections[] = [
                        'type'  => 'html',
                        'props' => [ 'html' => NWWS_Content_Cleaner::clean( $inner ) ],
                    ];
                    break;

                case 'core/image':
                    $sections[] = [
                        'type'  => 'image',
                        'props' => [
                            'src'     => $attrs['url']  ?? wp_get_attachment_url( $attrs['id'] ?? 0 ) ?: '',
                            'alt'     => $attrs['alt']  ?? '',
                            'caption' => wp_strip_all_tags( $attrs['caption'] ?? '' ),
                            'link'    => $attrs['href'] ?? null,
                        ],
                    ];
                    break;

                case 'core/buttons':
                    // Buttons block bevat core/button children
                    foreach ( $block['innerBlocks'] ?? [] as $btn ) {
                        $ba = $btn['attrs'] ?? [];
                        $sections[] = [
                            'type'  => 'cta',
                            'props' => [
                                'label' => wp_strip_all_tags( $btn['innerHTML'] ?? 'Meer informatie' ),
                                'url'   => $ba['url'] ?? '#',
                                'style' => ( $ba['className'] ?? '' ) === 'is-style-outline' ? 'secondary' : 'primary',
                            ],
                        ];
                    }
                    break;

                case 'core/button':
                    $sections[] = [
                        'type'  => 'cta',
                        'props' => [
                            'label' => wp_strip_all_tags( $inner ),
                            'url'   => $attrs['url'] ?? '#',
                            'style' => 'primary',
                        ],
                    ];
                    break;

                case 'core/list':
                    $sections[] = [
                        'type'  => 'html',
                        'props' => [ 'html' => NWWS_Content_Cleaner::clean( $inner ) ],
                    ];
                    break;

                case 'core/quote':
                case 'core/pullquote':
                    $sections[] = [
                        'type'  => 'html',
                        'props' => [ 'html' => '<blockquote>' . NWWS_Content_Cleaner::clean( $inner ) . '</blockquote>' ],
                    ];
                    break;

                case 'core/video':
                case 'core-embed/youtube':
                case 'core/embed':
                    $url = $attrs['url'] ?? '';
                    $sections[] = [
                        'type'  => 'video',
                        'props' => [ 'url' => $url ],
                    ];
                    break;

                case 'core/separator':
                    $sections[] = [ 'type' => 'divider', 'props' => [] ];
                    break;

                case 'core/spacer':
                    // Overslaan — spacing regelt Neuramerce zelf
                    break;

                case 'core/columns':
                    // Columns → probeer als features-grid als ze icon/title/text bevatten
                    $col_items = [];
                    foreach ( $block['innerBlocks'] ?? [] as $col ) {
                        $col_html = self::collect_inner_html( $col );
                        if ( ! empty( trim( $col_html ) ) ) {
                            $col_items[] = [ 'html' => NWWS_Content_Cleaner::clean( $col_html ) ];
                        }
                    }
                    if ( count( $col_items ) >= 2 ) {
                        $sections[] = [ 'type' => 'columns', 'props' => [ 'columns' => $col_items ] ];
                    }
                    break;

                // ── RankMath FAQ block → gestructureerd faq sectie type ──────────
                case 'rank-math/faq-block':
                    $questions = [];
                    foreach ( $attrs['questions'] ?? [] as $q ) {
                        if ( ! ( $q['visible'] ?? true ) ) continue;
                        $question = wp_strip_all_tags( $q['title'] ?? '' );
                        $answer   = NWWS_Content_Cleaner::clean( $q['content'] ?? '' );
                        if ( $question ) {
                            $questions[] = [ 'question' => $question, 'answer' => $answer ];
                        }
                    }
                    if ( ! empty( $questions ) ) {
                        $sections[] = [
                            'type'  => 'faq',
                            'props' => [ 'questions' => $questions ],
                        ];
                    }
                    break;

                // ── RankMath TOC → overslaan (auto-gegenereerd uit headings) ──────
                case 'rank-math/toc-block':
                    break;

                // ── Custom HTML block → bewaar raw HTML ───────────────────────────
                case 'core/html':
                    $clean = NWWS_Content_Cleaner::clean( $inner );
                    if ( ! empty( trim( $clean ) ) ) {
                        $sections[] = [
                            'type'  => 'html',
                            'props' => [ 'html' => $clean ],
                        ];
                    }
                    break;

                // ── Table block → bewaar als HTML tabel ───────────────────────────
                case 'core/table':
                    $clean = NWWS_Content_Cleaner::clean( $inner );
                    if ( ! empty( trim( $clean ) ) ) {
                        $sections[] = [
                            'type'  => 'table',
                            'props' => [ 'html' => $clean ],
                        ];
                    }
                    break;

                // ── Gallery → individuele afbeeldingen ────────────────────────────
                case 'core/gallery':
                    foreach ( $block['innerBlocks'] ?? [] as $img_block ) {
                        $ia = $img_block['attrs'] ?? [];
                        $src = $ia['url'] ?? wp_get_attachment_url( $ia['id'] ?? 0 ) ?: '';
                        if ( $src ) {
                            $sections[] = [
                                'type'  => 'image',
                                'props' => [
                                    'src'     => $src,
                                    'alt'     => $ia['alt'] ?? '',
                                    'caption' => wp_strip_all_tags( $ia['caption'] ?? '' ),
                                ],
                            ];
                        }
                    }
                    break;

                // ── WPZOOM recipe embed in blog post → overslaan ─────────────────
                case 'wpzoom-recipe-card/recipe-block-from-posts':
                    break;

                // WooCommerce blocks → overslaan
                case 'woocommerce/product-title':
                case 'woocommerce/product-image':
                case 'woocommerce/product-price':
                case 'woocommerce/add-to-cart':
                case 'woocommerce/product-details':
                case 'woocommerce/all-products':
                case 'woocommerce/featured-product':
                    break;

                default:
                    // Onbekend of null block (raw HTML tussen blocks)
                    $clean = NWWS_Content_Cleaner::clean( $inner );
                    if ( ! empty( trim( $clean ) ) ) {
                        $sections[] = [
                            'type'  => 'html',
                            'props' => [ 'html' => $clean ],
                        ];
                    }
                    break;
            }

            $i++;
        }

        return array_values( array_filter( $sections ) );
    }

    /**
     * Recursief alle innerHTML van een block en zijn innerBlocks samenvoegen.
     */
    private static function collect_inner_html( array $block ): string {
        $html = $block['innerHTML'] ?? '';
        foreach ( $block['innerBlocks'] ?? [] as $inner ) {
            $html .= self::collect_inner_html( $inner );
        }
        return $html;
    }
}

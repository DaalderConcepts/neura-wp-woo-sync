<?php
declare(strict_types=1);
/**
 * Stripped HTML van alle pagebuilder rommel.
 * Resultaat is semantische HTML met alleen inhoudelijke tags.
 */
defined('ABSPATH') || exit;
class NWWS_Content_Cleaner {

    // Tags die we willen bewaren
    const ALLOWED_TAGS = '<p><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><b><em><i><a><img><table><thead><tbody><tr><td><th><blockquote><br><hr><figure><figcaption><code><pre>';

    /**
     * Maak HTML schoon van Elementor / Divi / WPBakery markup.
     */
    public static function clean( string $html ): string {
        if ( empty( $html ) ) return '';

        // 1. Verwijder script + style blokken volledig
        $html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
        $html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is',   '', $html );

        // 2. Verwijder Elementor wrapper-divs maar bewaar hun content
        //    (elementor-section, elementor-container, elementor-row, elementor-column,
        //     elementor-widget-wrap, elementor-widget-container, e-con, e-con-inner)
        $html = self::unwrap_divs( $html, [
            'elementor-section',
            'elementor-container',
            'elementor-row',
            'elementor-column',
            'elementor-widget-wrap',
            'elementor-widget-container',
            'elementor-element',
            'e-con',
            'e-con-inner',
            'e-flex',
        ] );

        // 3. Verwijder Divi wrapper-divs
        $html = self::unwrap_divs( $html, [ 'et_pb_section', 'et_pb_row', 'et_pb_column', 'et_pb_module' ] );

        // 4. Strip overblijvende divs + spans maar bewaar content
        $html = preg_replace( '/<div[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/div>/i',    '', $html );
        $html = preg_replace( '/<span[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/span>/i',   '', $html );
        $html = preg_replace( '/<section[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/section>/i',    '', $html );

        // 5. Laat alleen toegestane tags over
        $html = strip_tags( $html, self::ALLOWED_TAGS );

        // 6. Verwijder alle attributes behalve href (a), src/alt (img)
        $html = preg_replace_callback( '/<(a|img)\b([^>]*)>/i', function( $m ) {
            $tag   = strtolower( $m[1] );
            $attrs = $m[2];
            $keep  = [];

            if ( $tag === 'a' ) {
                if ( preg_match( '/href=["\']([^"\']*)["\']/', $attrs, $hm ) ) {
                    $keep[] = 'href="' . esc_attr( $hm[1] ) . '"';
                }
            }
            if ( $tag === 'img' ) {
                if ( preg_match( '/src=["\']([^"\']*)["\']/',  $attrs, $sm ) ) $keep[] = 'src="' . esc_attr( $sm[1] ) . '"';
                if ( preg_match( '/alt=["\']([^"\']*)["\']/',  $attrs, $am ) ) $keep[] = 'alt="' . esc_attr( $am[1] ) . '"';
                if ( preg_match( '/width=["\']([^"\']*)["\']/', $attrs, $wm ) ) $keep[] = 'width="' . esc_attr( $wm[1] ) . '"';
                if ( preg_match( '/height=["\']([^"\']*)["\']/', $attrs, $hm ) ) $keep[] = 'height="' . esc_attr( $hm[1] ) . '"';
            }

            $attr_str = empty( $keep ) ? '' : ' ' . implode( ' ', $keep );
            return '<' . $tag . $attr_str . '>';
        }, $html );

        // 7. Verwijder class/id/style/data-* van overige tags
        $html = preg_replace( '/\s+(class|id|style|data-[a-z_-]+|aria-[a-z_-]+|role)=["\'][^"\']*["\']/i', '', $html );

        // 8. Opruimen: meerdere lege regels → één
        $html = preg_replace( '/(\s*\n){3,}/', "\n\n", $html );
        $html = trim( $html );

        return $html;
    }

    /**
     * Verwijder wrapper-divs met bepaalde classes maar behoud hun binnenste HTML.
     */
    private static function unwrap_divs( string $html, array $class_fragments ): string {
        foreach ( $class_fragments as $frag ) {
            // Verwijder openende div/section tags met deze class
            $html = preg_replace( '/<(?:div|section)[^>]*class=["\'][^"\']*' . preg_quote( $frag, '/' ) . '[^"\']*["\'][^>]*>/i', '', $html );
        }
        return $html;
    }
}

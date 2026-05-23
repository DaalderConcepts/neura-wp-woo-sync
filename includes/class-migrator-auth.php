<?php
declare(strict_types=1);
/**
 * API key authenticatie voor Neuramerce Migrator.
 */
defined('ABSPATH') || exit;
class NWWS_Migrator_Auth {

    const OPTION_KEY = 'neuramerce_api_key';

    /**
     * Valideer de API key.
     * Ondersteunt Authorization: Bearer <key> (primair) en X-Neuramerce-Key (legacy).
     */
    public static function validate( WP_REST_Request $request ): bool {
        $stored = get_option( self::OPTION_KEY, '' );
        if ( empty( $stored ) ) return false;

        // Primary: Authorization: Bearer <key>
        $auth = $request->get_header( 'Authorization' );
        if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
            return hash_equals( $stored, substr( $auth, 7 ) );
        }

        // Legacy fallback: X-Neuramerce-Key header
        $provided = $request->get_header( 'X-Neuramerce-Key' );
        return ! empty( $provided ) && hash_equals( $stored, $provided );
    }

    /**
     * Genereer een nieuwe API key en sla op.
     */
    public static function generate(): string {
        $key = bin2hex( random_bytes( 32 ) );
        update_option( self::OPTION_KEY, $key );
        return $key;
    }

    /**
     * Haal huidige API key op.
     */
    public static function get(): string {
        return get_option( self::OPTION_KEY, '' );
    }
}

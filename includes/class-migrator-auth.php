<?php
/**
 * API key authenticatie voor Neuramerce Migrator.
 */
class NWWS_Migrator_Auth {

    const OPTION_KEY = 'neuramerce_api_key';

    /**
     * Valideer de X-Neuramerce-Key header.
     */
    public static function validate( WP_REST_Request $request ): bool {
        $stored = get_option( self::OPTION_KEY, '' );
        if ( empty( $stored ) ) return false;

        $provided = $request->get_header( 'X-Neuramerce-Key' );
        return hash_equals( $stored, (string) $provided );
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

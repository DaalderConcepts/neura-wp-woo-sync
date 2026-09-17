<?php
declare(strict_types=1);
/**
 * Controleert dat de plugin exact dezelfde chat-identiteitstoken tekent als de app
 * verwacht. De verwachte waarde is de testvector uit de app
 * (src/lib/modules/inbox/__tests__/chat-identity-token-wp.test.ts), daar onafhankelijk
 * berekend met node:crypto. Klopt dit niet, dan accepteert Neura geen enkele token
 * van deze plugin en blijven ingelogde klanten in de chat onbevestigd.
 *
 * Draaien (zonder WordPress): php tests/chat-identity-token-vector.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

// Minimale stubs voor wat chat_identity_token() uit WordPress gebruikt.
function get_option( string $name, $default = false ) { return $default; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function is_email( string $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }

class NWWS_Migrator_Auth { const OPTION_KEY = 'neuramerce_api_key'; }

require __DIR__ . '/../includes/class-migrator-api.php';

$api_key  = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$expected = 'wp1.eyJrIjoibmYtd2lkZ2V0LWtleSIsImUiOiJrbGFudEBub21hZGZpcmUuc2hvcCIsIngiOjE3ODk2NTAwMDB9.dy6UYDA626PLRAORnIBeWI8njVlx-G40yAUvlFhPmn8';

$failures = 0;
$check = static function ( string $label, bool $ok ) use ( &$failures ): void {
	echo ( $ok ? 'ok   ' : 'FOUT ' ) . $label . PHP_EOL;
	if ( ! $ok ) $failures++;
};

$token = NWWS_Migrator_API::chat_identity_token( 'nf-widget-key', 'Klant@NomadFire.shop', 1789650000, $api_key );
$check( 'testvector gelijk aan de app', $token === $expected );
$check( 'korte API-key tekent niet', null === NWWS_Migrator_API::chat_identity_token( 'nf-widget-key', 'klant@nomadfire.shop', 1789650000, 'kort' ) );
$check( 'lege widget-sleutel tekent niet', null === NWWS_Migrator_API::chat_identity_token( '', 'klant@nomadfire.shop', 1789650000, $api_key ) );
$check( 'ongeldig adres tekent niet', null === NWWS_Migrator_API::chat_identity_token( 'nf-widget-key', 'geen-adres', 1789650000, $api_key ) );

if ( $failures > 0 ) {
	echo "Mis: {$failures}" . PHP_EOL;
	exit( 1 );
}
echo 'Alles klopt.' . PHP_EOL;

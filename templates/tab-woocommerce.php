<?php
declare(strict_types=1);
/**
 * Tab: WordPress & WooCommerce — synchronisatie-opties, velden, chat widget, tracking.
 *
 * Wordt geïncludeerd vanuit Neura_WC_Sync::render_settings_page().
 * Slaat enkel de sync-velden op via hidden veld nwws_save_section=woocommerce,
 * zodat opslaan hier de Connect-tab-koppeling niet overschrijft.
 */
defined('ABSPATH') || exit;

$wc_active = class_exists('WooCommerce');

$sync_enabled       = get_option('nwws_sync_enabled', '0') === '1';
$sync_products      = get_option('nwws_sync_products', '1') === '1';
$sync_orders        = get_option('nwws_sync_orders', '1') === '1';
$sync_customers     = get_option('nwws_sync_customers', '1') === '1';
$track_conversions  = get_option('nwws_track_conversions', '1') === '1';
$no_conn            = empty(get_option('nwws_api_url', '')) || empty(get_option('nwws_api_key', ''));

$sync_fields_prices     = get_option('nwws_sync_fields_prices',     '1') === '1';
$sync_fields_cogs       = get_option('nwws_sync_fields_cogs',       '1') === '1';
$sync_fields_stock      = get_option('nwws_sync_fields_stock',      '1') === '1';
$sync_fields_ean        = get_option('nwws_sync_fields_ean',        '1') === '1';
$sync_fields_categories = get_option('nwws_sync_fields_categories', '1') === '1';

$chat_enabled   = get_option('nwws_chat_enabled',   '0') === '1';
$chat_inbox_key = get_option('nwws_chat_inbox_key', '');
$tracking_token = get_option('nwws_tracking_token', '');

$ai_order_status  = get_option('nwws_ai_expose_order_status',  '0') === '1';
$ai_customer_data = get_option('nwws_ai_expose_customer_data', '0') === '1';
$ai_cart_contents = get_option('nwws_ai_expose_cart_contents', '0') === '1';

$sync_attrs_raw  = get_option('nwws_sync_attrs', 'product_brand');
$sync_attrs_list = array_filter(array_map('trim', explode(',', $sync_attrs_raw)));

$core_taxonomies = ['product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class'];
$attr_taxonomies = [];
if ($wc_active) {
    foreach (get_object_taxonomies('product', 'objects') as $slug => $obj) {
        if (!in_array($slug, $core_taxonomies, true)) {
            $attr_taxonomies[$slug] = $obj->label . ' <code>(' . $slug . ')</code>';
        }
    }
    asort($attr_taxonomies);
}
?>

<?php if (!$wc_active): ?>
    <div class="notice notice-warning inline" style="margin:0 0 16px;">
        <p>WooCommerce is niet actief op deze site. Productsynchronisatie is uitgeschakeld tot WooCommerce geïnstalleerd is.</p>
    </div>
<?php endif; ?>

<div class="wcmac-grid">
    <div class="wcmac-card wcmac-main-settings">
        <h2>Synchronisatie</h2>

        <form method="post" action="">
            <?php wp_nonce_field('nwws_settings'); ?>
            <input type="hidden" name="nwws_save_section" value="woocommerce">

            <table class="form-table">
                <tr>
                    <th scope="row">Automatische sync</th>
                    <td>
                        <label>
                            <input type="checkbox" name="nwws_sync_enabled" value="1" <?php checked($sync_enabled); ?>>
                            <strong>Automatische synchronisatie inschakelen</strong>
                        </label>
                        <p class="description">Synchroniseer data automatisch bij wijzigingen in WooCommerce.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Wat synchroniseren?</th>
                    <td>
                        <fieldset>
                            <label><input type="checkbox" name="nwws_sync_products"     value="1" <?php checked($sync_products); ?>>     Producten (inclusief COGS, prijzen, voorraad)</label><br>
                            <label><input type="checkbox" name="nwws_sync_orders"       value="1" <?php checked($sync_orders); ?>>       Orders (inclusief winstmarges en UTM tracking)</label><br>
                            <label><input type="checkbox" name="nwws_sync_customers"    value="1" <?php checked($sync_customers); ?>>    Klantgegevens (voor audience building)</label><br>
                            <label><input type="checkbox" name="nwws_track_conversions" value="1" <?php checked($track_conversions); ?>> Conversie tracking (Meta Pixel events)</label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <hr>

            <h3>Veld Synchronisatie</h3>
            <p class="description">Bepaal welke productvelden naar Neuramerce worden gestuurd bij een update.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Te synchroniseren velden</th>
                    <td>
                        <fieldset>
                            <label><input type="checkbox" name="nwws_sync_fields_prices"     value="1" <?php checked($sync_fields_prices); ?>>     <strong>Prijzen</strong> &mdash; reguliere prijs en actieprijs</label><br>
                            <label><input type="checkbox" name="nwws_sync_fields_cogs"       value="1" <?php checked($sync_fields_cogs); ?>>       <strong>COGS</strong> &mdash; inkoopprijs en valuta</label><br>
                            <label><input type="checkbox" name="nwws_sync_fields_stock"      value="1" <?php checked($sync_fields_stock); ?>>      <strong>Voorraad</strong> &mdash; stockstatus en -hoeveelheid</label><br>
                            <label><input type="checkbox" name="nwws_sync_fields_ean"        value="1" <?php checked($sync_fields_ean); ?>>        <strong>EAN / GTIN / Barcode</strong></label><br>
                            <label><input type="checkbox" name="nwws_sync_fields_categories" value="1" <?php checked($sync_fields_categories); ?>> <strong>Categorie&euml;n</strong> &mdash; product categorienamen</label>
                        </fieldset>
                        <p class="description">SKU, naam en permalink worden altijd gesynchroniseerd.</p>
                    </td>
                </tr>
                <?php if (!empty($attr_taxonomies)) : ?>
                <tr>
                    <th scope="row">Attribuut taxonomie&euml;n</th>
                    <td>
                        <fieldset>
                            <?php foreach ($attr_taxonomies as $slug => $label) : ?>
                                <label>
                                    <input type="checkbox" name="nwws_sync_attr[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $sync_attrs_list, true)); ?>>
                                    <?php echo wp_kses($label, ['code' => []]); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">Geselecteerde attributen worden gesynchroniseerd als Merk, Kleur of Maat op basis van de attribuutnaam.</p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <hr>

            <h3>
                <span class="dashicons dashicons-format-chat" style="vertical-align:middle;margin-right:4px;"></span>
                Chat widget &amp; tracking
            </h3>
            <p class="description">
                Activeer de live chat widget en server-side tracking op je webshop. De widget verbindt bezoekers met
                jouw <strong>Omnichannel Inbox</strong>; de tracking-token laadt <code>track.js</code> voor multi-touch attributie.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">Chat widget</th>
                    <td>
                        <label>
                            <input type="checkbox" name="nwws_chat_enabled" value="1" <?php checked($chat_enabled); ?>>
                            <strong>Chat widget inschakelen op de webshop</strong>
                        </label>
                        <p class="description">Laadt automatisch de Neuramerce chat widget op elke pagina van je shop.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nwws_chat_inbox_key">Inbox Key</label></th>
                    <td>
                        <input type="text" id="nwws_chat_inbox_key" name="nwws_chat_inbox_key"
                               value="<?php echo esc_attr($chat_inbox_key); ?>"
                               class="regular-text" placeholder="clk_xxxxxxxxxxxxxxxx">
                        <p class="description">
                            Uit Neuramerce via <em>Inbox &rarr; Instellingen &rarr; Widget</em>.
                            <?php if (!empty($chat_inbox_key)): ?>
                                <br><span style="color:#46b450;">&#10003; Geconfigureerd</span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nwws_tracking_token">Tracking Token</label></th>
                    <td>
                        <input type="text" id="nwws_tracking_token" name="nwws_tracking_token"
                               value="<?php echo esc_attr($tracking_token); ?>"
                               class="regular-text" placeholder="cxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        <p class="description">
                            Uit Neuramerce via <em>Adverteren &rarr; Tracking &rarr; Tokens</em>.
                            Laadt <code>track.js</code> automatisch op de webshop voor attributie.
                            <?php if (!empty($tracking_token)): ?>
                                <br><span style="color:#46b450;">&#10003; Geconfigureerd &mdash; track.js actief op alle pagina's</span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>

            <h3>AI-gegevens voor de chat-assistent</h3>
            <p class="description">Bepaal welke gegevens de Neuramerce AI-assistent live mag opvragen bij een chatgesprek.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Toegestane data</th>
                    <td>
                        <fieldset>
                            <label><input type="checkbox" name="nwws_ai_expose_order_status"  value="1" <?php checked($ai_order_status); ?>>  Orderstatus opvragen (op order-ID of e-mail)</label><br>
                            <label><input type="checkbox" name="nwws_ai_expose_customer_data" value="1" <?php checked($ai_customer_data); ?>> Klantgegevens (eerdere orders, adres)</label><br>
                            <label><input type="checkbox" name="nwws_ai_expose_cart_contents" value="1" <?php checked($ai_cart_contents); ?>> Actuele winkelmandje-inhoud</label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <?php submit_button('Instellingen Opslaan', 'primary', 'nwws_save_settings'); ?>
        </form>
    </div>

    <div class="wcmac-sidebar">
        <div class="wcmac-card">
            <h3>Synchronisatie Statistieken</h3>
            <div id="nwws-sync-stats">
                <div class="wcmac-stat"><span class="wcmac-stat-label">Producten:</span>      <span class="wcmac-stat-value" id="stat-products">-</span></div>
                <div class="wcmac-stat"><span class="wcmac-stat-label">Met COGS:</span>        <span class="wcmac-stat-value" id="stat-cogs">-</span></div>
                <div class="wcmac-stat"><span class="wcmac-stat-label">Orders:</span>          <span class="wcmac-stat-value" id="stat-orders">-</span></div>
                <div class="wcmac-stat"><span class="wcmac-stat-label">Gesynchroniseerd:</span><span class="wcmac-stat-value" id="stat-synced">-</span></div>
            </div>
            <button type="button" class="button button-secondary" id="nwws-refresh-stats">
                <span class="dashicons dashicons-update"></span> Ververs Statistieken
            </button>
        </div>

        <div class="wcmac-card">
            <h3>Handmatige Synchronisatie</h3>
            <p class="description">Synchroniseer alle data in één keer (kan even duren).</p>
            <button type="button" class="button button-secondary wcmac-sync-btn" id="nwws-sync-products" <?php disabled($no_conn || !$wc_active); ?>>
                <span class="dashicons dashicons-products"></span> Sync Alle Producten
            </button>
            <button type="button" class="button button-secondary wcmac-sync-btn" id="nwws-sync-orders" <?php disabled($no_conn || !$wc_active); ?>>
                <span class="dashicons dashicons-cart"></span> Sync Alle Orders
            </button>
        </div>
    </div>
</div>

<div class="wcmac-card wcmac-api-info">
    <h3>REST API Endpoints</h3>
    <p class="description">Beschikbaar voor externe toegang (vereist API Key in <code>X-API-Key</code> header):</p>
    <table class="widefat">
        <thead>
            <tr><th>Endpoint</th><th>Method</th><th>Beschrijving</th></tr>
        </thead>
        <tbody>
            <tr><td><code><?php echo esc_html(rest_url('nwws/v1/products')); ?></code></td><td><span class="wcmac-method">GET</span></td><td>Haal alle producten op (met COGS)</td></tr>
            <tr><td><code><?php echo esc_html(rest_url('nwws/v1/orders')); ?></code></td>  <td><span class="wcmac-method">GET</span></td><td>Haal alle orders op (met winstmarges)</td></tr>
            <tr><td><code><?php echo esc_html(rest_url('nwws/v1/stats')); ?></code></td>   <td><span class="wcmac-method">GET</span></td><td>Haal synchronisatie statistieken op</td></tr>
        </tbody>
    </table>
</div>

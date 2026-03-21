<?php
/**
 * Admin Settings Page Template
 */
defined('ABSPATH') || exit;

$sync_enabled           = get_option('nwws_sync_enabled', '0') === '1';
$sync_products          = get_option('nwws_sync_products', '1') === '1';
$sync_orders            = get_option('nwws_sync_orders', '1') === '1';
$sync_customers         = get_option('nwws_sync_customers', '1') === '1';
$track_conversions      = get_option('nwws_track_conversions', '1') === '1';
$api_url                = get_option('nwws_api_url', '');
$api_key                = get_option('nwws_api_key', '');
$push_url               = get_option('nwws_push_url', '');
$push_key               = get_option('nwws_push_key', '');
$no_conn                = empty($api_url) || empty($api_key);
$no_push                = empty($push_url) || empty($push_key);
$sync_fields_prices     = get_option('nwws_sync_fields_prices',     '1') === '1';
$sync_fields_cogs       = get_option('nwws_sync_fields_cogs',       '1') === '1';
$sync_fields_stock      = get_option('nwws_sync_fields_stock',      '1') === '1';
$sync_fields_ean        = get_option('nwws_sync_fields_ean',        '1') === '1';
$sync_fields_brand      = get_option('nwws_sync_fields_brand',      '1') === '1';
$sync_fields_color      = get_option('nwws_sync_fields_color',      '1') === '1';
$sync_fields_size       = get_option('nwws_sync_fields_size',       '1') === '1';
$sync_fields_categories = get_option('nwws_sync_fields_categories', '1') === '1';

// Enabled attribute taxonomies (comma-separated slugs)
$chat_enabled   = get_option('nwws_chat_enabled',   '0') === '1';
$chat_inbox_key = get_option('nwws_chat_inbox_key', '');

$sync_attrs_raw  = get_option('nwws_sync_attrs', 'product_brand');
$sync_attrs_list = array_filter(array_map('trim', explode(',', $sync_attrs_raw)));

// All attribute taxonomies for the checklist (exclude core taxonomies)
$core_taxonomies = ['product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class'];
$attr_taxonomies = [];
foreach (get_object_taxonomies('product', 'objects') as $slug => $obj) {
    if (!in_array($slug, $core_taxonomies, true)) {
        $attr_taxonomies[$slug] = $obj->label . ' <code>(' . $slug . ')</code>';
    }
}
asort($attr_taxonomies);
?>

<div class="wrap nwws-settings">
    <h1>
        <span class="dashicons dashicons-update" style="font-size:32px;width:32px;height:32px;"></span>
        Neura WooCommerce Sync
        <span style="font-size:13px;font-weight:normal;color:#646970;margin-left:8px;">v<?php echo esc_html(NWWS_VERSION); ?></span>
    </h1>

    <p class="description">
        Synchroniseer automatisch je WooCommerce data met Neuramerce voor accurate ROAS tracking en conversie-optimalisatie.
    </p>

    <div class="wcmac-grid">
        <!-- Settings Form -->
        <div class="wcmac-card wcmac-main-settings">
            <h2>Instellingen</h2>

            <form method="post" action="">
                <?php wp_nonce_field('nwws_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="nwws_api_url">API URL</label></th>
                        <td>
                            <input type="url" id="nwws_api_url" name="nwws_api_url"
                                   value="<?php echo esc_attr($api_url); ?>"
                                   class="regular-text" placeholder="https://app.neuramerce.com/api">
                            <p class="description">De URL van je Neuramerce platform API</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="nwws_api_key">API Key</label></th>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <input type="password" id="nwws_api_key" name="nwws_api_key"
                                       value="<?php echo esc_attr($api_key); ?>"
                                       class="regular-text" placeholder="Jouw API sleutel">
                                <button type="button" class="button button-secondary"
                                        onclick="(function(b){var i=document.getElementById('nwws_api_key');i.type=i.type==='password'?'text':'password';b.textContent=i.type==='password'?'&#128065; Toon':'&#128584; Verberg';})(this)">
                                    &#128065; Toon
                                </button>
                                <button type="button" class="button button-secondary"
                                        onclick="(function(b){var i=document.getElementById('nwws_api_key');navigator.clipboard.writeText(i.value).then(function(){b.textContent='&#10003; Gekopieerd';setTimeout(function(){b.textContent='Kopieer';},2000);});})(this)">
                                    Kopieer
                                </button>
                            </div>
                            <p class="description">Veilige authenticatiesleutel voor API communicatie</p>
                        </td>
                    </tr>
                </table>

                <hr>

                <h3>Synchronisatie Opties</h3>

                <table class="form-table">
                    <tr>
                        <th scope="row">Synchronisatie</th>
                        <td>
                            <label>
                                <input type="checkbox" name="nwws_sync_enabled" value="1" <?php checked($sync_enabled); ?>>
                                <strong>Automatische synchronisatie inschakelen</strong>
                            </label>
                            <p class="description">Schakel dit in om data automatisch te synchroniseren bij wijzigingen</p>
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

                <h3>Neuramerce Webhook Instellingen</h3>
                <p class="description">
                    Kopieer de <strong>Webhook URL</strong> en <strong>API Key</strong> uit Neuramerce
                    (<em>Voorraad → WooCommerce → Plugin Push Sync</em>) en plak ze hieronder.
                    Zodra geconfigureerd worden producten automatisch naar Neuramerce gepusht bij elke wijziging.
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="nwws_push_url">Webhook URL</label></th>
                        <td>
                            <input type="url" id="nwws_push_url" name="nwws_push_url"
                                   value="<?php echo esc_attr($push_url); ?>"
                                   class="large-text" placeholder="https://app.neuramerce.com/api/v1/inventory/woocommerce/webhook?workspace=...">
                            <p class="description">De volledige webhook URL inclusief <code>?workspace=...</code> parameter</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="nwws_push_key">API Key</label></th>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <input type="password" id="nwws_push_key" name="nwws_push_key"
                                       value="<?php echo esc_attr($push_key); ?>"
                                       class="regular-text" placeholder="32-karakter hex sleutel">
                                <button type="button" class="button button-secondary"
                                        onclick="(function(b){var i=document.getElementById('nwws_push_key');i.type=i.type==='password'?'text':'password';b.textContent=i.type==='password'?'&#128065; Toon':'&#128584; Verberg';})(this)">
                                    &#128065; Toon
                                </button>
                                <button type="button" class="button button-secondary"
                                        onclick="(function(b){var i=document.getElementById('nwws_push_key');navigator.clipboard.writeText(i.value).then(function(){b.textContent='&#10003; Gekopieerd';setTimeout(function(){b.textContent='Kopieer';},2000);});})(this)">
                                    Kopieer
                                </button>
                            </div>
                            <p class="description">De API sleutel uit Neuramerce (32 karakters)</p>
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
                                <label><input type="checkbox" name="nwws_sync_fields_prices"     value="1" <?php checked($sync_fields_prices); ?>>     <strong>Prijzen</strong> — reguliere prijs en actieprijs</label><br>
                                <label><input type="checkbox" name="nwws_sync_fields_cogs"       value="1" <?php checked($sync_fields_cogs); ?>>       <strong>COGS</strong> — inkoopprijs en valuta</label><br>
                                <label><input type="checkbox" name="nwws_sync_fields_stock"      value="1" <?php checked($sync_fields_stock); ?>>      <strong>Voorraad</strong> — stockstatus en -hoeveelheid</label><br>
                                <label><input type="checkbox" name="nwws_sync_fields_ean"        value="1" <?php checked($sync_fields_ean); ?>>        <strong>EAN / GTIN / Barcode</strong> — global_unique_id of meta veld</label><br>
                                <label><input type="checkbox" name="nwws_sync_fields_categories" value="1" <?php checked($sync_fields_categories); ?>> <strong>Categorieën</strong> — product categorienamen</label>
                            </fieldset>
                            <p class="description">SKU, naam en permalink worden altijd gesynchroniseerd.</p>
                        </td>
                    </tr>
                    <?php if (!empty($attr_taxonomies)) : ?>
                    <tr>
                        <th scope="row">Attribuut taxonomieën</th>
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
                    Neuramerce Chat Widget
                </h3>
                <p class="description">
                    Activeer de live chat widget op je webshop. De widget verbindt bezoekers direct met
                    jouw <strong>Omnichannel Inbox</strong> in Neuramerce en stuurt automatisch winkelmandje-data door.
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
                                Kopieer de <strong>Inbox Key</strong> uit Neuramerce via
                                <em>Inbox → Instellingen → Widget → Embed code</em>.
                                <?php if (!empty($chat_inbox_key)): ?>
                                    <br><span style="color:#46b450;">✓ Geconfigureerd</span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <?php if (!empty($chat_inbox_key)): ?>
                    <tr>
                        <th scope="row">Embed code</th>
                        <td>
                            <p class="description" style="margin-bottom:6px;">
                                De widget wordt automatisch geladen als de checkbox hierboven aangevinkt is.
                                Wil je de widget handmatig insluiten? Gebruik dan onderstaande code:
                            </p>
                            <div style="position:relative;">
                                <code id="nwws-embed-code" style="display:block;padding:10px 12px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;font-size:12px;word-break:break-all;line-height:1.6;">
                                    &lt;script src="https://app.neuramerce.com/widget-loader.js?key=<?php echo esc_attr($chat_inbox_key); ?>" defer&gt;&lt;/script&gt;
                                </code>
                                <button type="button" id="nwws-copy-embed"
                                        onclick="(function(){var t=document.getElementById('nwws-copy-embed');navigator.clipboard.writeText('&lt;script src=&quot;https://app.neuramerce.com/widget-loader.js?key=<?php echo esc_js($chat_inbox_key); ?>&quot; defer&gt;&lt;\/script&gt;').then(function(){t.textContent='✓ Gekopieerd';setTimeout(function(){t.textContent='Kopieer';},2000);})})()"
                                        class="button button-secondary" style="margin-top:6px;">
                                    Kopieer
                                </button>
                            </div>
                            <p class="description" style="margin-top:6px;">Plak dit script voor de afsluitende <code>&lt;/body&gt;</code> tag op je website.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button('Instellingen Opslaan', 'primary', 'nwws_save_settings'); ?>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="wcmac-sidebar">
            <!-- Connection Status -->
            <div class="wcmac-card">
                <h3>Verbindingsstatus</h3>
                <div id="nwws-connection-status">
                    <?php if ($no_conn): ?>
                        <p class="wcmac-status wcmac-status-warning">
                            <span class="dashicons dashicons-warning"></span>
                            Configureer eerst je API instellingen
                        </p>
                    <?php else: ?>
                        <p class="wcmac-status wcmac-status-unknown">
                            <span class="dashicons dashicons-info"></span>
                            Klik op "Test Verbinding" om te controleren
                        </p>
                    <?php endif; ?>
                </div>
                <button type="button" class="button button-secondary" id="nwws-test-connection" <?php disabled($no_conn); ?>>
                    <span class="dashicons dashicons-admin-plugins"></span> Test Verbinding
                </button>
            </div>

            <!-- Webhook Status -->
            <div class="wcmac-card">
                <h3>Webhook Status</h3>
                <div id="nwws-push-status">
                    <?php if ($no_push): ?>
                        <p class="wcmac-status wcmac-status-warning">
                            <span class="dashicons dashicons-warning"></span>
                            Vul Webhook URL en API Key in
                        </p>
                    <?php else: ?>
                        <p class="wcmac-status wcmac-status-unknown">
                            <span class="dashicons dashicons-update"></span>
                            Klik op "Test Webhook" om te controleren
                        </p>
                    <?php endif; ?>
                </div>
                <button type="button" class="button button-secondary" id="nwws-test-push" <?php disabled($no_push); ?>>
                    <span class="dashicons dashicons-update"></span> Test Webhook
                </button>
            </div>

            <!-- Sync Stats -->
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

            <!-- Manual Sync -->
            <div class="wcmac-card">
                <h3>Handmatige Synchronisatie</h3>
                <p class="description">Synchroniseer alle data in één keer (kan even duren)</p>
                <button type="button" class="button button-secondary wcmac-sync-btn" id="nwws-sync-products" <?php disabled($no_conn); ?>>
                    <span class="dashicons dashicons-products"></span> Sync Alle Producten
                </button>
                <button type="button" class="button button-secondary wcmac-sync-btn" id="nwws-sync-orders" <?php disabled($no_conn); ?>>
                    <span class="dashicons dashicons-cart"></span> Sync Alle Orders
                </button>
            </div>

            <!-- Plugin Info -->
            <div class="wcmac-card">
                <h3>Plugin Info</h3>
                <ul class="wcmac-docs-list">
                    <li>
                        <span class="dashicons dashicons-tag"></span>
                        Versie: <strong><?php echo esc_html(NWWS_VERSION); ?></strong>
                    </li>
                    <li>
                        <span class="dashicons dashicons-book"></span>
                        <a href="https://github.com/<?php echo esc_attr(NWWS_GITHUB_OWNER . '/' . NWWS_GITHUB_REPO); ?>" target="_blank">GitHub Repository</a>
                    </li>
                    <li>
                        <span class="dashicons dashicons-sos"></span>
                        <a href="https://github.com/<?php echo esc_attr(NWWS_GITHUB_OWNER . '/' . NWWS_GITHUB_REPO); ?>/issues" target="_blank">Support & FAQ</a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- REST API Info -->
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
</div>

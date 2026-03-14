<?php
/**
 * Admin Settings Page Template
 */
defined('ABSPATH') || exit;

$sync_enabled      = get_option('nwws_sync_enabled', '0') === '1';
$sync_products     = get_option('nwws_sync_products', '1') === '1';
$sync_orders       = get_option('nwws_sync_orders', '1') === '1';
$sync_customers    = get_option('nwws_sync_customers', '1') === '1';
$track_conversions = get_option('nwws_track_conversions', '1') === '1';
$api_url           = get_option('nwws_api_url', '');
$api_key           = get_option('nwws_api_key', '');
$no_conn           = empty($api_url) || empty($api_key);
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
                            <input type="password" id="nwws_api_key" name="nwws_api_key"
                                   value="<?php echo esc_attr($api_key); ?>"
                                   class="regular-text" placeholder="Jouw API sleutel">
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

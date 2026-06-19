<?php
declare(strict_types=1);
/**
 * Tab: Neura Connect — koppeling met Neuramerce (API + webhook push).
 *
 * Wordt geïncludeerd vanuit Neura_WC_Sync::render_settings_page().
 * Slaat enkel de connectie-velden op via hidden veld nwws_save_section=connect,
 * zodat opslaan hier de WooCommerce-tab-instellingen niet overschrijft.
 */
defined('ABSPATH') || exit;

$api_url       = get_option('nwws_api_url', '');
$api_key       = get_option('nwws_api_key', '');
$connection_id = get_option('nwws_connection_id', '');
$push_url      = get_option('nwws_push_url', '');
$push_key      = get_option('nwws_push_key', '');
$workspace_id  = get_option('nwws_workspace_id', '');
$no_conn       = empty($api_url) || empty($api_key);
$no_push       = empty($push_url) || empty($push_key);
$fetch_status  = get_option('nwws_config_fetch_status', '');
?>

<div class="wcmac-grid">
    <div class="wcmac-card wcmac-main-settings">
        <h2>Neuramerce koppeling</h2>
        <p class="description" style="margin-bottom:12px;">
            Koppel je WooCommerce shop eenmalig via
            <strong><a href="https://app.neuramerce.com/settings?tab=connectors" target="_blank">Neuramerce &rarr; Instellingen &rarr; Koppelingen</a></strong>.
            Na het koppelen vind je alle onderstaande waarden op die pagina.
        </p>

        <form method="post" action="">
            <?php wp_nonce_field('nwws_settings'); ?>
            <input type="hidden" name="nwws_save_section" value="connect">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nwws_api_url">API URL</label></th>
                    <td>
                        <input type="url" id="nwws_api_url" name="nwws_api_url"
                               value="<?php echo esc_attr($api_url); ?>"
                               class="regular-text" placeholder="https://app.neuramerce.com/api">
                        <p class="description">Te vinden in Neuramerce via <em>Instellingen &rarr; Koppelingen &rarr; WooCommerce</em></p>
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
                        </div>
                        <p class="description">Te vinden in Neuramerce via <em>Instellingen &rarr; Koppelingen &rarr; WooCommerce</em></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nwws_connection_id">Connection ID</label></th>
                    <td>
                        <input type="text" id="nwws_connection_id" name="nwws_connection_id"
                               value="<?php echo esc_attr($connection_id); ?>"
                               class="regular-text" placeholder="conn_xxxxxxxxxxxx">
                        <p class="description">
                            Identificeert deze shop bij Neuramerce. Te vinden op dezelfde koppelingen-pagina.
                            <?php if ($fetch_status === 'ok' || get_transient('nwws_config_synced')): ?>
                                <br><span style="color:#46b450;">&#10003; Verbinding geverifieerd</span>
                            <?php elseif (in_array($fetch_status, ['error_401', 'error_404', 'error_network'], true)): ?>
                                <br><span style="color:#dc3232;">&#9888; Verbinding kon niet worden geverifieerd (<?php echo esc_html($fetch_status); ?>) &mdash;
                                <a href="<?php echo esc_url(admin_url('admin.php?page=neura-wp-woo-sync&tab=connect&nwws_force_refresh=1')); ?>">opnieuw proberen</a></span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>

            <h3>Webhook push (voorraad &amp; orders)</h3>
            <p class="description" style="margin-bottom:8px;">
                Stuurt voorraad- en order-updates realtime naar Neuramerce.
                Te vinden via <em>Voorraad &rarr; WooCommerce &rarr; Plugin Push Sync</em>.
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nwws_push_url">Webhook URL</label></th>
                    <td>
                        <input type="url" id="nwws_push_url" name="nwws_push_url"
                               value="<?php echo esc_attr($push_url); ?>"
                               class="large-text" placeholder="https://app.neuramerce.com/api/v1/inventory/woocommerce/webhook?workspace=...">
                        <?php if (!empty($workspace_id)): ?>
                            <p class="description">Workspace: <code><?php echo esc_html($workspace_id); ?></code></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nwws_push_key">Webhook Key</label></th>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="password" id="nwws_push_key" name="nwws_push_key"
                                   value="<?php echo esc_attr($push_key); ?>"
                                   class="regular-text" placeholder="32-karakter hex sleutel">
                            <button type="button" class="button button-secondary"
                                    onclick="(function(b){var i=document.getElementById('nwws_push_key');i.type=i.type==='password'?'text':'password';b.textContent=i.type==='password'?'&#128065; Toon':'&#128584; Verberg';})(this)">
                                &#128065; Toon
                            </button>
                        </div>
                    </td>
                </tr>
            </table>

            <?php submit_button('Koppeling Opslaan', 'primary', 'nwws_save_settings'); ?>
        </form>
    </div>

    <div class="wcmac-sidebar">
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

        <div class="wcmac-card">
            <h3>Webhook-status</h3>
            <div id="nwws-push-status">
                <?php if ($no_push): ?>
                    <p class="wcmac-status wcmac-status-warning">
                        <span class="dashicons dashicons-warning"></span>
                        Webhook nog niet ingesteld
                    </p>
                <?php else: ?>
                    <p class="wcmac-status wcmac-status-unknown">
                        <span class="dashicons dashicons-info"></span>
                        Klik op "Test Webhook" om te controleren
                    </p>
                <?php endif; ?>
            </div>
            <button type="button" class="button button-secondary" id="nwws-test-push" <?php disabled($no_push); ?>>
                <span class="dashicons dashicons-update"></span> Test Webhook
            </button>
        </div>

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
                    <a href="https://github.com/<?php echo esc_attr(NWWS_GITHUB_OWNER . '/' . NWWS_GITHUB_REPO); ?>/issues" target="_blank">Support &amp; FAQ</a>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php
declare(strict_types=1);
/**
 * Tab: Logs — synchronisatie-logboek (laatste 50 acties).
 *
 * Wordt geïncludeerd vanuit Neura_WC_Sync::render_settings_page().
 * Leest de FIFO-log uit option nwws_sync_log (zie append_sync_log()).
 */
defined('ABSPATH') || exit;

$raw     = get_option('nwws_sync_log', '[]');
$entries = json_decode((string) $raw, true);
if (!is_array($entries)) {
    $entries = [];
}
// Nieuwste eerst
$entries = array_reverse($entries);
?>

<div class="wcmac-card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Synchronisatie-logboek</h2>
        <?php if (!empty($entries)): ?>
        <form method="post" action="" onsubmit="return confirm('Weet je zeker dat je het logboek wilt wissen?');">
            <?php wp_nonce_field('nwws_clear_log'); ?>
            <button type="submit" name="nwws_clear_log" value="1" class="button button-secondary">
                <span class="dashicons dashicons-trash" style="vertical-align:text-bottom;"></span> Logboek wissen
            </button>
        </form>
        <?php endif; ?>
    </div>
    <p class="description">De laatste 50 synchronisatie-acties. Nieuwste bovenaan.</p>

    <?php if (empty($entries)): ?>
        <p style="padding:24px 0;color:#646970;text-align:center;">
            <span class="dashicons dashicons-info" style="font-size:28px;width:28px;height:28px;display:block;margin:0 auto 8px;"></span>
            Nog geen synchronisatie-acties gelogd. Zodra producten of orders gesynchroniseerd worden, verschijnen ze hier.
        </p>
    <?php else: ?>
        <table class="widefat striped" style="margin-top:8px;">
            <thead>
                <tr>
                    <th style="width:170px;">Tijdstip</th>
                    <th style="width:120px;">Type</th>
                    <th style="width:90px;">Aantal</th>
                    <th>Resultaat</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $ts     = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                    $type   = isset($entry['type']) ? (string) $entry['type'] : '-';
                    $count  = isset($entry['count']) ? (int) $entry['count'] : 0;
                    $errors = isset($entry['errors']) && is_array($entry['errors']) ? $entry['errors'] : [];
                    ?>
                    <tr>
                        <td><?php echo $ts ? esc_html(wp_date('j M Y H:i', $ts)) : '&mdash;'; ?></td>
                        <td><?php echo esc_html($type); ?></td>
                        <td><?php echo esc_html((string) $count); ?></td>
                        <td>
                            <?php if (empty($errors)): ?>
                                <span style="color:#46b450;">&#10003; Geslaagd</span>
                            <?php else: ?>
                                <span style="color:#dc3232;">&#9888; <?php echo esc_html((string) count($errors)); ?> fout(en)</span>
                                <ul style="margin:4px 0 0 16px;color:#646970;font-size:12px;">
                                    <?php foreach (array_slice($errors, 0, 5) as $err): ?>
                                        <li><?php echo esc_html((string) $err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

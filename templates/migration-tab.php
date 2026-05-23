<?php
declare(strict_types=1);
/**
 * Migratie naar Neuramerce — tab in Neuramerce plugin
 */
defined('ABSPATH') || exit;

$api_key  = NWWS_Migrator_Auth::get();
$has_key  = !empty($api_key);
$endpoint = get_rest_url(null, 'neuramerce/v1/');
$site_url = get_site_url();

$wc_active = class_exists('WooCommerce');
$el_active = defined('ELEMENTOR_VERSION');

// Aantallen
$count_pages    = (int) wp_count_posts('page')->publish;
$count_posts    = (int) wp_count_posts('post')->publish;
$count_media    = (int) wp_count_posts('attachment')->inherit;
$count_products = $wc_active ? (int) wp_count_posts('product')->publish : 0;
$count_reviews  = $wc_active ? (int) get_comments(['type' => 'review', 'status' => 'approve', 'count' => true]) : 0;
?>

<style>
.nm-migrate-grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; margin-top: 8px; }
.nm-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px 24px; margin-bottom: 16px; }
.nm-card h3 { margin: 0 0 12px; font-size: 15px; }
.nm-hero { background: linear-gradient(135deg, #6d28d9 0%, #4c1d95 100%); color: #fff; border-radius: 12px; padding: 28px 32px; margin-bottom: 20px; }
.nm-hero h2 { margin: 0 0 8px; font-size: 22px; color: #fff; }
.nm-hero p  { margin: 0; opacity: .85; }
.nm-stat-row { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0; }
.nm-stat { background: rgba(255,255,255,.15); border-radius: 8px; padding: 10px 16px; min-width: 100px; text-align: center; }
.nm-stat strong { display: block; font-size: 22px; }
.nm-stat span { font-size: 12px; opacity: .8; }
.nm-key-box { font-family: monospace; font-size: 13px; background: #f6f7f8; border: 1px solid #ddd; border-radius: 4px; padding: 8px 12px; width: 100%; box-sizing: border-box; cursor: pointer; }
.nm-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
.nm-badge-green  { background: #d1fae5; color: #065f46; }
.nm-badge-purple { background: #ede9fe; color: #5b21b6; }
.nm-badge-gray   { background: #f3f4f6; color: #6b7280; }
.nm-steps { counter-reset: steps; list-style: none; margin: 0; padding: 0; }
.nm-steps li { counter-increment: steps; display: flex; gap: 12px; margin-bottom: 12px; line-height: 1.5; }
.nm-steps li::before { content: counter(steps); background: #6d28d9; color: #fff; border-radius: 50%; width: 22px; height: 22px; min-width: 22px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; margin-top: 1px; }
</style>

<div class="wcmac-settings">

    <!-- Hero -->
    <div class="nm-hero">
        <h2>🚀 Migreer naar Neuramerce</h2>
        <p>Jouw website, producten, reviews en bestellingen — volledig overgezet naar Neuramerce. Inclusief Gutenberg en Elementor content.</p>
        <div class="nm-stat-row">
            <div class="nm-stat"><strong><?php echo (int) $count_pages; ?></strong><span>Pagina's</span></div>
            <div class="nm-stat"><strong><?php echo (int) $count_posts; ?></strong><span>Blog posts</span></div>
            <?php if ($wc_active): ?>
            <div class="nm-stat"><strong><?php echo (int) $count_products; ?></strong><span>Producten</span></div>
            <div class="nm-stat"><strong><?php echo (int) $count_reviews; ?></strong><span>Reviews</span></div>
            <?php endif; ?>
            <div class="nm-stat"><strong><?php echo (int) $count_media; ?></strong><span>Media</span></div>
        </div>
    </div>

    <div class="nm-migrate-grid">

        <!-- Links: API key + instructies -->
        <div>
            <!-- Detectie -->
            <div class="nm-card">
                <h3>Gedetecteerde technologieën</h3>
                <p style="margin:0">
                    <?php echo $wc_active
                        ? '<span class="nm-badge nm-badge-green">&#10003; WooCommerce</span> '
                        : '<span class="nm-badge nm-badge-gray">WooCommerce niet actief</span> '; ?>
                    <?php echo $el_active
                        ? '<span class="nm-badge nm-badge-purple">&#10003; Elementor</span> '
                        : '<span class="nm-badge nm-badge-gray">Gutenberg / Classic</span> '; ?>
                </p>
                <p style="margin:8px 0 0;font-size:13px;color:#666">
                    <?php if ($el_active): ?>
                        Elementor pagina's worden automatisch geparsed — content wordt schoongemaakt en omgezet naar Neuramerce secties.
                    <?php else: ?>
                        Gutenberg blocks worden herkend en omgezet. Niet-herkenbare blocks komen als schone HTML terecht.
                    <?php endif; ?>
                </p>
            </div>

            <!-- API Key -->
            <div class="nm-card">
                <h3>Migrator API key</h3>
                <?php if ($has_key): ?>
                    <p style="margin:0 0 8px;font-size:13px">Kopieer deze key naar de Neuramerce import-wizard:</p>
                    <input type="text" class="nm-key-box" value="<?php echo esc_attr($api_key); ?>"
                           readonly onclick="this.select(); document.execCommand('copy'); this.style.background='#d1fae5'; setTimeout(function(){this.style.background='#f6f7f8';}.bind(this),1200)">
                    <p style="margin:6px 0 12px;font-size:12px;color:#888">⚠ Behandel dit als een wachtwoord — deel alleen met Neuramerce.</p>
                    <form method="post" style="display:inline">
                        <?php wp_nonce_field('wcmac_migrator'); ?>
                        <button name="wcmac_migrator_action" value="generate" class="button button-secondary" style="margin-right:8px">Nieuwe key genereren</button>
                        <button name="wcmac_migrator_action" value="revoke" class="button"
                                onclick="return confirm('Weet je het zeker? De Neuramerce koppeling stopt.')">Key intrekken</button>
                    </form>
                <?php else: ?>
                    <p style="margin:0 0 12px;font-size:13px;color:#666">
                        Genereer een API key om Neuramerce toegang te geven tot je site.
                    </p>
                    <form method="post">
                        <?php wp_nonce_field('wcmac_migrator'); ?>
                        <button name="wcmac_migrator_action" value="generate" class="button button-primary">
                            🔑 API key genereren
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Instructies -->
            <?php if ($has_key): ?>
            <div class="nm-card">
                <h3>Hoe te koppelen</h3>
                <ol class="nm-steps">
                    <li>Log in op <strong>app.neuramerce.com</strong></li>
                    <li>Ga naar <strong>Import → WordPress</strong></li>
                    <li>Vul je URL in: <code><?php echo esc_html($site_url); ?></code></li>
                    <li>Plak de API key hierboven</li>
                    <li>Klik <strong>Verbinding testen</strong> — Neuramerce haalt je site op</li>
                    <li>Kies wat je wilt importeren en klik <strong>Starten</strong></li>
                </ol>
            </div>
            <?php endif; ?>
        </div>

        <!-- Rechts: API endpoint info + wat wordt gemigreerd -->
        <div>
            <div class="nm-card">
                <h3>Wat wordt gemigreerd?</h3>
                <table style="width:100%;font-size:13px;border-collapse:collapse">
                    <tr><td style="padding:4px 0">📄 Pagina's + content</td><td style="text-align:right"><span class="nm-badge nm-badge-green">✓</span></td></tr>
                    <tr><td style="padding:4px 0">📝 Blog posts</td><td style="text-align:right"><span class="nm-badge nm-badge-green">✓</span></td></tr>
                    <tr><td style="padding:4px 0">🖼 Media bibliotheek</td><td style="text-align:right"><span class="nm-badge nm-badge-green">✓</span></td></tr>
                    <?php $wc_badge_class = $wc_active ? 'nm-badge-green' : 'nm-badge-gray'; $wc_badge_icon = $wc_active ? '&#10003;' : '&mdash;'; ?>
                    <tr><td style="padding:4px 0">WC Producten</td><td style="text-align:right"><span class="nm-badge <?php echo esc_attr($wc_badge_class); ?>"><?php echo $wc_badge_icon; ?></span></td></tr>
                    <tr><td style="padding:4px 0">Categorie&euml;n</td><td style="text-align:right"><span class="nm-badge <?php echo esc_attr($wc_badge_class); ?>"><?php echo $wc_badge_icon; ?></span></td></tr>
                    <tr><td style="padding:4px 0">Reviews</td><td style="text-align:right"><span class="nm-badge <?php echo esc_attr($wc_badge_class); ?>"><?php echo $wc_badge_icon; ?></span></td></tr>
                    <tr><td style="padding:4px 0">Klanten</td><td style="text-align:right"><span class="nm-badge <?php echo esc_attr($wc_badge_class); ?>"><?php echo $wc_badge_icon; ?></span></td></tr>
                    <tr><td style="padding:4px 0">🗂 Navigatiemenu's</td><td style="text-align:right"><span class="nm-badge nm-badge-green">✓</span></td></tr>
                    <tr><td style="padding:4px 0">🎨 Design tokens</td><td style="text-align:right"><span class="nm-badge nm-badge-purple">~</span></td></tr>
                    <tr><td style="padding:4px 0">🔍 SEO meta (Yoast/RM)</td><td style="text-align:right"><span class="nm-badge nm-badge-green">✓</span></td></tr>
                </table>
            </div>

            <div class="nm-card">
                <h3>API endpoint</h3>
                <code style="font-size:11px;word-break:break-all"><?php echo esc_html($endpoint); ?></code>
                <p style="margin:8px 0 0;font-size:12px;color:#888">
                    Beschikbaar zodra je een API key hebt gegenereerd.
                    Stuur de <code>X-Neuramerce-Key</code> header mee bij elke request.
                </p>
            </div>
        </div>

    </div>
</div>

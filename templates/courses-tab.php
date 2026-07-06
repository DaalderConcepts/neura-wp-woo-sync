<?php
/**
 * Cursussen (Tutor LMS) — tab in Neura WooCommerce Sync plugin
 */
defined('ABSPATH') || exit;

global $wpdb;

$tutor_active = post_type_exists('courses');

$api_key  = NWWS_Migrator_Auth::get();
$has_key  = !empty($api_key);
$endpoint = get_rest_url(null, 'neuramerce/v1/courses');

$count_courses = $tutor_active ? (int) wp_count_posts('courses')->publish : 0;

$count_lessons = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
    'lesson',
    'publish'
));
$count_topics = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
    'topics',
    'publish'
));
$count_quizzes = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
    'tutor_quiz',
    'publish'
));
?>

<style>
.nm-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px 24px; margin-bottom: 16px; }
.nm-card h3 { margin: 0 0 12px; font-size: 15px; }
.nm-hero { background: linear-gradient(135deg, #6d28d9 0%, #4c1d95 100%); color: #fff; border-radius: 12px; padding: 28px 32px; margin-bottom: 20px; }
.nm-hero h2 { margin: 0 0 8px; font-size: 22px; color: #fff; }
.nm-hero p  { margin: 0; opacity: .85; }
.nm-stat-row { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0 0; }
.nm-stat { background: rgba(255,255,255,.15); border-radius: 8px; padding: 10px 16px; min-width: 100px; text-align: center; }
.nm-stat strong { display: block; font-size: 22px; }
.nm-stat span { font-size: 12px; opacity: .8; }
.nm-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px; }
.nm-badge-green { background: #d1fae5; color: #065f46; }
.nm-badge-gray  { background: #f3f4f6; color: #6b7280; }
</style>

<div class="wcmac-settings">

    <div class="nm-hero">
        <h2>🎓 Tutor LMS cursussen</h2>
        <p>Jouw cursussen, lessen, quizzen en inschrijvingen — klaar om te importeren in Neuramerce.</p>
        <?php if ($tutor_active): ?>
        <div class="nm-stat-row">
            <div class="nm-stat"><strong><?php echo esc_html($count_courses); ?></strong><span>Cursussen</span></div>
            <div class="nm-stat"><strong><?php echo esc_html($count_topics); ?></strong><span>Onderwerpen</span></div>
            <div class="nm-stat"><strong><?php echo esc_html($count_lessons); ?></strong><span>Lessen</span></div>
            <div class="nm-stat"><strong><?php echo esc_html($count_quizzes); ?></strong><span>Quizzen</span></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$tutor_active): ?>
        <div class="notice notice-warning" style="margin:0 0 16px"><p>Tutor LMS niet gevonden op deze site.</p></div>
    <?php endif; ?>

    <div class="nm-card">
        <h3>REST endpoint</h3>
        <code style="font-size:11px;word-break:break-all"><?php echo esc_html($endpoint); ?></code>
        <p style="margin:8px 0 0;font-size:12px;color:#888">
            Stuur de <code>X-Neuramerce-Key</code> header mee bij elke request.
        </p>
    </div>

    <div class="nm-card">
        <h3>Status</h3>
        <table style="width:100%;font-size:13px;border-collapse:collapse">
            <tr>
                <td style="padding:4px 0">Tutor LMS actief</td>
                <td style="text-align:right">
                    <span class="nm-badge <?php echo $tutor_active ? 'nm-badge-green' : 'nm-badge-gray'; ?>"><?php echo $tutor_active ? '✓' : '✗'; ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding:4px 0">API key ingesteld</td>
                <td style="text-align:right">
                    <span class="nm-badge <?php echo $has_key ? 'nm-badge-green' : 'nm-badge-gray'; ?>"><?php echo $has_key ? '✓' : '✗'; ?></span>
                </td>
            </tr>
        </table>
        <?php if (!$has_key): ?>
        <p style="margin:8px 0 0;font-size:12px;color:#888">
            Genereer een API key op het <strong>Migratie</strong>-tabblad om het endpoint te activeren.
        </p>
        <?php endif; ?>
    </div>

</div>

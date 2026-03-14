/**
 * Neura WooCommerce Sync — Admin JavaScript
 */

(function ($) {
    'use strict';

    const NWWS = {
        init: function () {
            this.bindEvents();
            this.loadStats();
        },

        bindEvents: function () {
            $('#nwws-test-connection').on('click', this.testConnection);
            $('#nwws-test-push').on('click', this.testPush);
            $('#nwws-refresh-stats').on('click', this.loadStats);
            $('#nwws-sync-products').on('click', this.syncProducts);
            $('#nwws-sync-orders').on('click', this.syncOrders);
        },

        testConnection: function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $status = $('#nwws-connection-status');

            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: nwwsData.ajaxUrl,
                type: 'POST',
                data: { action: 'nwws_test_connection', nonce: nwwsData.nonce },
                success: function (response) {
                    const cls = response.success ? 'nwws-status-success' : 'nwws-status-error';
                    const ico = response.success ? 'yes-alt' : 'dismiss';
                    $status.html(
                        `<p class="nwws-status ${cls}"><span class="dashicons dashicons-${ico}"></span>${response.data}</p>`
                    );
                },
                error: function () {
                    $status.html(
                        '<p class="nwws-status nwws-status-error"><span class="dashicons dashicons-dismiss"></span>Er is een fout opgetreden.</p>'
                    );
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },

        testPush: function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $status = $('#nwws-push-status');

            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: nwwsData.ajaxUrl,
                type: 'POST',
                data: { action: 'nwws_test_push', nonce: nwwsData.nonce },
                success: function (response) {
                    const cls = response.success ? 'nwws-status-success' : 'nwws-status-error';
                    const ico = response.success ? 'yes-alt' : 'dismiss';
                    $status.html(
                        `<p class="nwws-status ${cls}"><span class="dashicons dashicons-${ico}"></span>${response.data}</p>`
                    );
                },
                error: function () {
                    $status.html(
                        '<p class="nwws-status nwws-status-error"><span class="dashicons dashicons-dismiss"></span>Er is een fout opgetreden.</p>'
                    );
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },

        loadStats: function (e) {
            if (e) e.preventDefault();
            const $btn = $('#nwws-refresh-stats').addClass('loading').prop('disabled', true);

            $.ajax({
                url: nwwsData.ajaxUrl,
                type: 'POST',
                data: { action: 'nwws_get_sync_stats', nonce: nwwsData.nonce },
                success: function (response) {
                    if (!response.success) return;
                    const s = response.data;
                    $('#stat-products').text(s.total_products  || '0');
                    $('#stat-cogs').text(s.products_with_cogs  || '0');
                    $('#stat-orders').text(s.total_orders      || '0');
                    $('#stat-synced').text((parseInt(s.products_synced) || 0) + (parseInt(s.orders_synced) || 0));
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },

        syncProducts: function (e) {
            e.preventDefault();
            if (!confirm('Weet je zeker dat je alle producten wilt synchroniseren? Dit kan even duren.')) return;

            const $btn = $(this);
            NWWS.showOverlay('Producten synchroniseren...');
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: nwwsData.ajaxUrl,
                type: 'POST',
                data: { action: 'nwws_sync_all_products', nonce: nwwsData.nonce },
                timeout: 300000,
                success: function (response) {
                    NWWS.hideOverlay();
                    NWWS.showNotice(response.success ? 'success' : 'error', response.data || 'Er is een fout opgetreden.');
                    if (response.success) NWWS.loadStats();
                },
                error: function () {
                    NWWS.hideOverlay();
                    NWWS.showNotice('error', 'Er is een fout opgetreden bij het synchroniseren van producten.');
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },

        syncOrders: function (e) {
            e.preventDefault();
            if (!confirm('Weet je zeker dat je alle orders wilt synchroniseren? Dit kan even duren.')) return;

            const $btn = $(this);
            NWWS.showOverlay('Orders synchroniseren...');
            $btn.addClass('loading').prop('disabled', true);

            $.ajax({
                url: nwwsData.ajaxUrl,
                type: 'POST',
                data: { action: 'nwws_sync_all_orders', nonce: nwwsData.nonce },
                timeout: 300000,
                success: function (response) {
                    NWWS.hideOverlay();
                    NWWS.showNotice(response.success ? 'success' : 'error', response.data || 'Er is een fout opgetreden.');
                    if (response.success) NWWS.loadStats();
                },
                error: function () {
                    NWWS.hideOverlay();
                    NWWS.showNotice('error', 'Er is een fout opgetreden bij het synchroniseren van orders.');
                },
                complete: function () {
                    $btn.removeClass('loading').prop('disabled', false);
                },
            });
        },

        showOverlay: function (message) {
            $('body').append(
                `<div class="nwws-loading-overlay"><div class="nwws-loading-spinner"><span class="spinner is-active"></span><p>${message}</p></div></div>`
            );
        },

        hideOverlay: function () {
            $('.nwws-loading-overlay').remove();
        },

        showNotice: function (type, message) {
            const cls = type === 'success' ? 'notice-success' : 'notice-error';
            const $notice = $(
                `<div class="notice ${cls} is-dismissible"><p>${message}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>`
            );
            $('.nwws-settings h1').after($notice);

            setTimeout(() => $notice.fadeOut(() => $notice.remove()), 5000);
            $notice.find('.notice-dismiss').on('click', () => $notice.fadeOut(() => $notice.remove()));
        },
    };

    $(document).ready(() => NWWS.init());
})(jQuery);

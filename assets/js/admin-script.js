(function ($) {
    'use strict';

    function showToast(message, isSuccess) {
        var $toast = $('#bk-wc-toast');
        if (!$toast.length) {
            return;
        }
        $toast
            .removeClass('bk-toast-success bk-toast-error')
            .addClass(isSuccess ? 'bk-toast-success' : 'bk-toast-error')
            .text(message)
            .stop(true, true)
            .fadeIn(200);
    }

    function updateApiStatusUI(status, isActive) {
        $('#bk-header-api-text, #bk-settings-status-text, #bk-dash-api-status').text(status);

        var $pills = $('#bk-header-api-badge, #bk-settings-status-pill');
        $pills.removeClass('is-active is-error').addClass(isActive ? 'is-active' : 'is-error');

        $('#bk-dash-api-status')
            .removeClass('text-success text-danger')
            .addClass(isActive ? 'text-success' : 'text-danger');
    }

    $(document).on('click', '#bk-toggle-key-visibility', function () {
        var $input = $('#bk-api-key');
        var type = $input.attr('type') === 'password' ? 'text' : 'password';
        $input.attr('type', type);
    });

    $(document).on('submit', '#bk-settings-form', function (e) {
        e.preventDefault();
        var $btn = $('#bk-save-settings-btn');
        var origHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="bk-spinner"></span><span>Saving...</span>');

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_save_settings',
            nonce: bkWcData.nonce,
            api_key: $('#bk-api-key').val(),
            domain: $('#bk-domain').val(),
            delivery_paid_customer: $('#bk-delivery-paid-customer').val(),
            delivery_mode: $('#bk-delivery-mode').val(),
            price_markup_type: $('#bk-price-markup-type').val(),
            price_markup_value: $('#bk-price-markup-value').val()
        })
            .done(function (res) {
                if (res && res.success) {
                    updateApiStatusUI(res.data.api_status, res.data.is_active);
                    showToast(res.data.message, res.data.is_active);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Failed to save settings.';
                    showToast(msg, false);
                }
            })
            .fail(function () {
                showToast('Network error while saving settings.', false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(origHtml);
            });
    });

    $(document).on('click', '#bk-test-api-btn', function () {
        var $btn = $(this);
        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="bk-spinner"></span><span>Checking...</span>');

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_check_status',
            nonce: bkWcData.nonce
        })
            .done(function (res) {
                if (res && res.success) {
                    updateApiStatusUI(res.data.api_status, res.data.is_active);
                    showToast('API Status: ' + res.data.api_status, res.data.is_active);
                } else {
                    showToast('Unable to verify API status.', false);
                }
            })
            .fail(function () {
                showToast('Network error while checking API status.', false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(origHtml);
            });
    });

    $(document).on('click', '#bk-start-import-btn', function () {
        var $btn = $(this);
        var $card = $('#bk-import-progress-card');
        var $bar = $('#bk-import-progress-bar');
        var $pct = $('#bk-import-percent');
        var $statusText = $('#bk-import-status-text');
        var $summary = $('#bk-import-summary');

        $btn.prop('disabled', true);
        $summary.hide().empty();
        $card.stop(true, true).slideDown(260);
        $bar.css('width', '28%');
        $pct.text('28%');
        $statusText.text('Fetching products & variants from Business koro...');

        var stepTimer = setTimeout(function () {
            $bar.css('width', '65%');
            $pct.text('65%');
            $statusText.text('Syncing WooCommerce products, color/size & images...');
        }, 550);

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_import_products',
            nonce: bkWcData.nonce
        })
            .done(function (res) {
                clearTimeout(stepTimer);
                if (res && res.success) {
                    $bar.css('width', '100%');
                    $pct.text('100%');
                    $statusText.text('Import Completed Successfully!');
                    updateApiStatusUI('Active', true);
                    $summary
                        .html(
                            '<div>' + $('<div>').text(res.data.message).html() + '</div>' +
                            '<div class="bk-subtext">Last Imported: ' + $('<div>').text(res.data.last_imported_date || '').html() + '</div>'
                        )
                        .fadeIn(220);
                    showToast(res.data.message, true);
                } else {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Import failed.';
                    if (res && res.data && res.data.api_status) {
                        updateApiStatusUI(res.data.api_status, false);
                    }
                    $bar.css('width', '100%');
                    $pct.text('Error');
                    $statusText.text(errMsg);
                    showToast(errMsg, false);
                }
            })
            .fail(function () {
                clearTimeout(stepTimer);
                $statusText.text('Server timeout or network error during import.');
                showToast('Server timeout or network error during import.', false);
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '.bk-remove-product-btn', function (e) {
        e.stopPropagation();
        if (!window.confirm(bkWcData.strings.confirmDel)) {
            return;
        }

        var $btn = $(this);
        var productId = $btn.data('product-id');
        var $row = $('#bk-product-row-' + productId);

        $btn.prop('disabled', true).text('Removing...');

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_remove_product',
            nonce: bkWcData.nonce,
            product_id: productId
        })
            .done(function (res) {
                if (res && res.success) {
                    $row.css({ opacity: 0, transform: 'translateX(18px)' });
                    setTimeout(function () {
                        $row.remove();
                        $('#bk-manage-count-badge').text(res.data.total_imported + ' Products');
                    }, 280);
                    showToast(res.data.message, true);
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Could not remove product.';
                    showToast(msg, false);
                    $btn.prop('disabled', false).text('Remove');
                }
            })
            .fail(function () {
                showToast('Network error while removing product.', false);
                $btn.prop('disabled', false).text('Remove');
            });
    });

    $(document).on('click', '.bk-order-row', function () {
        var orderId = $(this).data('order-id');
        var $modal = $('#bk-order-modal');
        var $body = $('#bk-modal-body');
        var $sendBtn = $('#bk-send-order-btn');
        var $statusBtn = $('#bk-check-order-status-btn');
        var $feedback = $('#bk-modal-send-feedback');

        $feedback.text('').removeClass('text-success text-danger');
        $sendBtn.data('order-id', orderId).prop('disabled', true);
        $statusBtn.data('order-id', orderId).hide().prop('disabled', false);
        $('#bk-modal-title').text('Order #' + orderId + ' Details');
        $body.html('<div class="bk-spinner-row"><span class="bk-spinner"></span><span>Loading order details...</span></div>');
        $modal.css('display', 'flex');

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_get_order_details',
            nonce: bkWcData.nonce,
            order_id: orderId
        })
            .done(function (res) {
                if (res && res.success) {
                    var d = res.data;
                    var itemsHtml = '';
                    if (d.items && d.items.length) {
                        itemsHtml = '<ul style="margin:6px 0 0 18px;">';
                        $.each(d.items, function (_, it) {
                            var varParts = [];
                            if (it.color) { varParts.push('Color: ' + it.color); }
                            if (it.size) { varParts.push('Size: ' + it.size); }
                            var varText = varParts.length ? ' [' + varParts.join(', ') + ']' : '';

                            itemsHtml += '<li>' +
                                $('<div>').text(it.name).html() +
                                ' &times; ' + it.quantity +
                                $('<div>').text(varText).html() +
                                ' (BK ID: <code>' + $('<div>').text(it.bk_productId || 'None').html() + '</code>)' +
                                '</li>';
                        });
                        itemsHtml += '</ul>';
                    }

                    var html =
                        '<div class="bk-detail-grid">' +
                            '<div class="bk-detail-box"><span class="bk-detail-label">Customer Name</span><span class="bk-detail-val">' + $('<div>').text(d.customer_name || '-').html() + '</span></div>' +
                            '<div class="bk-detail-box"><span class="bk-detail-label">Phone Number</span><span class="bk-detail-val">' + $('<div>').text(d.customer_phone || '-').html() + '</span></div>' +
                            '<div class="bk-detail-box"><span class="bk-detail-label">Division (বিভাগ)</span><span class="bk-detail-val">' + $('<div>').text(d.division || '-').html() + '</span></div>' +
                            '<div class="bk-detail-box"><span class="bk-detail-label">District (জেলা)</span><span class="bk-detail-val">' + $('<div>').text(d.district || '-').html() + '</span></div>' +
                            '<div class="bk-detail-box"><span class="bk-detail-label">Area / Upazila</span><span class="bk-detail-val">' + $('<div>').text(d.area || '-').html() + '</span></div>' +
                            '<div class="bk-detail-box"><span class="bk-detail-label">Total Selling Price</span><span class="bk-detail-val">' + $('<div>').text(d.total_amount + ' ' + d.currency).html() + '</span></div>' +
                            '<div class="bk-detail-box full"><span class="bk-detail-label">Full Address (ঠিকানা)</span><span class="bk-detail-val">' + $('<div>').text(d.address || '-').html() + '</span></div>' +
                            '<div class="bk-detail-box full"><span class="bk-detail-label">Items &amp; Variants</span>' + itemsHtml + '</div>' +
                        '</div>';

                    if (d.sent_info) {
                        var stText = d.sent_info.remote_status ? ' — Status: ' + d.sent_info.remote_status : '';
                        html += '<div id="bk-modal-sent-banner" class="bk-inline-alert bk-toast-success">Sent to Business Koro (ID: ' + $('<div>').text(d.sent_info.bk_order_id + stText).html() + ')</div>';
                        $statusBtn.show();
                    }

                    $body.html(html);
                    $sendBtn.prop('disabled', false);
                } else {
                    $body.html('<div class="bk-inline-alert bk-toast-error">Failed to load order details.</div>');
                }
            })
            .fail(function () {
                $body.html('<div class="bk-inline-alert bk-toast-error">Network error loading order.</div>');
            });
    });

    $(document).on('click', '#bk-modal-close', function () {
        $('#bk-order-modal').fadeOut(180);
    });

    $(document).on('click', '#bk-order-modal', function (e) {
        if ($(e.target).is('#bk-order-modal')) {
            $('#bk-order-modal').fadeOut(180);
        }
    });

    $(document).on('click', '#bk-send-order-btn', function () {
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var $feedback = $('#bk-modal-send-feedback');
        var origHtml = $btn.html();

        if (!orderId) {
            return;
        }

        $btn.prop('disabled', true).html('<span class="bk-spinner"></span><span>Sending...</span>');
        $feedback.text('').removeClass('text-success text-danger');

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_send_order',
            nonce: bkWcData.nonce,
            order_id: orderId
        })
            .done(function (res) {
                if (res && res.success) {
                    $feedback.addClass('text-success').text(res.data.message + ' (ID: ' + res.data.bk_order_id + ')');
                    var stSuffix = res.data.remote_status ? ' &bull; ' + res.data.remote_status : '';
                    $('#bk-order-badge-' + orderId).html(
                        '<span class="bk-badge bk-badge-success">Sent (#' + $('<div>').text(res.data.bk_order_id).html() + stSuffix + ')</span>'
                    );
                    $('#bk-check-order-status-btn').show();
                    showToast(res.data.message, true);
                } else {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Failed to send order.';
                    $feedback.addClass('text-danger').text(errMsg);
                    showToast(errMsg, false);
                }
            })
            .fail(function () {
                $feedback.addClass('text-danger').text('Network error while sending order.');
                showToast('Network error while sending order.', false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(origHtml);
            });
    });

    $(document).on('click', '#bk-check-order-status-btn', function () {
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var $feedback = $('#bk-modal-send-feedback');
        var origHtml = $btn.html();

        if (!orderId) {
            return;
        }

        $btn.prop('disabled', true).html('<span class="bk-spinner"></span><span>Checking...</span>');
        $feedback.text('').removeClass('text-success text-danger');

        $.post(bkWcData.ajaxUrl, {
            action: 'bk_wc_check_order_status',
            nonce: bkWcData.nonce,
            order_id: orderId
        })
            .done(function (res) {
                if (res && res.success) {
                    $feedback.addClass('text-success').text(res.data.message);
                    $('#bk-order-badge-' + orderId).html(
                        '<span class="bk-badge bk-badge-success">Sent (#' + $('<div>').text(res.data.bk_order_id + ' • ' + res.data.remote_status).html() + ')</span>'
                    );
                    if ($('#bk-modal-sent-banner').length) {
                        $('#bk-modal-sent-banner').text('Sent to Business Koro (ID: ' + res.data.bk_order_id + ' — Status: ' + res.data.remote_status + ')');
                    }
                    showToast(res.data.message, true);
                } else {
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Failed to check order status.';
                    $feedback.addClass('text-danger').text(errMsg);
                    showToast(errMsg, false);
                }
            })
            .fail(function () {
                $feedback.addClass('text-danger').text('Network error while checking status.');
                showToast('Network error while checking status.', false);
            })
            .always(function () {
                $btn.prop('disabled', false).html(origHtml);
            });
    });
})(jQuery);

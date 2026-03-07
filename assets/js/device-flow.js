/* global jQuery, telexDeviceFlow */
(function ($) {
    'use strict';

    var pollTimer = null;
    var container = $('#telex-device-flow-container');

    $('#telex-start-device-flow').on('click', function () {
        startDeviceFlow();
    });

    function startDeviceFlow() {
        container.html('<p><span class="spinner is-active" style="float:none;"></span> Starting...</p>');

        $.post(telexDeviceFlow.ajaxUrl, {
            action: 'telex_start_device_flow',
            _nonce: telexDeviceFlow.nonce,
        }, function (response) {
            if (!response.success) {
                showError(response.data || 'Failed to start device flow');
                return;
            }

            showDeviceCode(response.data);
            startPolling();
        }).fail(function () {
            showError('Network error. Please try again.');
        });
    }

    function showDeviceCode(data) {
        var html = '';
        html += '<div style="text-align:center; padding:20px 0;">';
        html += '<p style="margin-bottom:8px;">' + 'Enter this code in the Telex app:' + '</p>';
        html += '<div style="font-size:32px; font-family:monospace; font-weight:bold; letter-spacing:4px; padding:16px; background:#f0f0f1; border-radius:4px; display:inline-block;">';
        html += escapeHtml(data.user_code);
        html += '</div>';
        html += '<p style="margin-top:16px;">';
        html += '<a href="' + escapeHtml(data.verification_uri_complete) + '" target="_blank" class="button button-secondary">Open Telex &rarr;</a>';
        html += '</p>';
        html += '<p style="margin-top:16px; color:#666;">';
        html += '<span class="spinner is-active" style="float:none; margin-right:4px;"></span>';
        html += '<span id="telex-device-status">Waiting for authorization...</span>';
        html += '</p>';
        html += '<p style="margin-top:8px;">';
        html += '<button id="telex-cancel-device-flow" class="button button-link-delete">Cancel</button>';
        html += '</p>';
        html += '</div>';

        container.html(html);

        $('#telex-cancel-device-flow').on('click', function () {
            cancelDeviceFlow();
        });
    }

    function startPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
        }

        pollTimer = setInterval(function () {
            $.post(telexDeviceFlow.ajaxUrl, {
                action: 'telex_poll_device_token',
                _nonce: telexDeviceFlow.nonce,
            }, function (response) {
                if (!response.success) {
                    // Error — code expired or invalid
                    stopPolling();
                    showExpired(response.data || 'Device code expired.');
                    return;
                }

                if (response.data.authorized) {
                    stopPolling();
                    window.location.href = window.location.pathname + '?page=telex&connected=1';
                }
                // else: still pending, keep polling
            }).fail(function () {
                // Network error — keep trying
            });
        }, telexDeviceFlow.pollInterval);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function cancelDeviceFlow() {
        stopPolling();

        $.post(telexDeviceFlow.ajaxUrl, {
            action: 'telex_cancel_device_flow',
            _nonce: telexDeviceFlow.nonce,
        });

        showConnectButton();
    }

    function showExpired(message) {
        var html = '';
        html += '<div style="text-align:center; padding:20px 0;">';
        html += '<p style="color:#d63638;">' + escapeHtml(message) + '</p>';
        html += '<button id="telex-retry-device-flow" class="button button-primary">Try Again</button>';
        html += '</div>';

        container.html(html);

        $('#telex-retry-device-flow').on('click', function () {
            startDeviceFlow();
        });
    }

    function showError(message) {
        var html = '';
        html += '<div class="notice notice-error inline"><p>' + escapeHtml(message) + '</p></div>';
        html += '<p style="margin-top:12px;">';
        html += '<button id="telex-retry-device-flow" class="button button-primary">Try Again</button>';
        html += '</p>';

        container.html(html);

        $('#telex-retry-device-flow').on('click', function () {
            startDeviceFlow();
        });
    }

    function showConnectButton() {
        container.html(
            '<button id="telex-start-device-flow" class="button button-primary button-hero">Connect</button>'
        );
        $('#telex-start-device-flow').on('click', function () {
            startDeviceFlow();
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
})(jQuery);

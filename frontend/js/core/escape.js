/* Escapado HTML centralizado para datos de API (mitigación XSS). */
(function (global) {
    'use strict';

    function risEscapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    global.risEscapeHtml = risEscapeHtml;
})(typeof window !== 'undefined' ? window : globalThis);

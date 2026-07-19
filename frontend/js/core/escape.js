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

    /** Quita emojis decorativos al inicio de mensajes de toast (tono clínico). */
    function risStripLeadingEmoji(text) {
        const raw = String(text ?? '');
        try {
            return raw
                .replace(/^(?:\p{Extended_Pictographic}|\p{Emoji_Presentation}|[\uFE0F\u200D\u2139])+\s*/gu, '')
                .trim();
        } catch (_) {
            return raw
                .replace(/^(?:[\u2190-\u21FF\u2300-\u23FF\u2600-\u27BF\u2B00-\u2BFF\uFE0F\u200D\u2139]|[\uD83C][\uDC00-\uDFFF]|[\uD83D][\uDC00-\uDFFF]|[\uD83E][\uDC00-\uDFFF])+\s*/g, '')
                .trim();
        }
    }

    function risSanitizeToastMessage(text) {
        return risEscapeHtml(risStripLeadingEmoji(text));
    }

    global.risEscapeHtml = risEscapeHtml;
    global.risStripLeadingEmoji = risStripLeadingEmoji;
    global.risSanitizeToastMessage = risSanitizeToastMessage;
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Micrófono, dictado web y WebHID requieren contexto seguro (HTTPS),
 * salvo en localhost / 127.0.0.1.
 */
function risIsLocalhostHost() {
    const host = window.location.hostname;
    return host === 'localhost' || host === '127.0.0.1';
}

function risNeedsHttpsForMedia() {
    return !window.isSecureContext && !risIsLocalhostHost();
}

function risSuggestedHttpsUrl() {
    return window.location.href.replace(/^http:/i, 'https:');
}

function risLanCertUrl() {
    return `https://${window.location.hostname}/ris-lan-ca.pem`;
}

function risHttpsRequiredForMediaMessage() {
    const host = window.location.hostname;
    const url = risSuggestedHttpsUrl();
    const isIp = /^[0-9.]+$/.test(host) || /^100\./.test(host);
    if (isIp) {
        return `Micrófono y dictado requieren HTTPS. Abra ${url} → «Avanzado» → «Continuar al sitio». Si no puede continuar, instale el certificado desde ${risLanCertUrl()}`;
    }
    return `El micrófono y el dictado web requieren HTTPS. Abra ${url} y acepte el certificado la primera vez (autofirmado del laboratorio).`;
}

window.risIsLocalhostHost = risIsLocalhostHost;
window.risNeedsHttpsForMedia = risNeedsHttpsForMedia;
window.risSuggestedHttpsUrl = risSuggestedHttpsUrl;
window.risLanCertUrl = risLanCertUrl;
window.risHttpsRequiredForMediaMessage = risHttpsRequiredForMediaMessage;

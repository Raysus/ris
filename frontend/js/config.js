/**
 * URL base de la API RIS (sin barra final).
 * Debe ser `var API_URL` (global) para scripts en modo estricto (p. ej. callbacks de jQuery).
 */
function isPrivateLanHost(host) {
    return /^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);
}

function resolveRisApiUrl() {
    if (typeof window.__RIS_API_URL__ === 'string' && window.__RIS_API_URL__) {
        return window.__RIS_API_URL__.replace(/\/$/, '');
    }

    const host = window.location.hostname;
    const port = window.location.port;
    const isHttps = window.location.protocol === 'https:';
    const proto = isHttps ? 'https' : 'http';

    // Mismo hostname que la página (localhost ≠ 127.0.0.1 para CORS).
    if (host === 'localhost' || host === '127.0.0.1') {
        return `${proto}://${host}:8000/api`;
    }
    if (host === '') {
        return 'http://127.0.0.1:8000/api';
    }

    if (host === 'ris.healthticloud.cl' || host.endsWith('.healthticloud.cl')) {
        return 'https://api.healthticloud.cl/api';
    }

    // Laboratorio LAN: si entra por http://192.168.x.x/ (puerto 80), la API va por /api en nginx.
    if (isPrivateLanHost(host) && (!port || port === '80' || port === '443')) {
        return `${proto}://${host}/api`;
    }

    return `${proto}://${host}:8000/api`;
}

var API_URL = resolveRisApiUrl();
window.API_URL = API_URL;

/** Incrementar al desplegar frontend para evitar HTML/JS en caché del navegador */
window.RIS_BUILD = '20260612';

/**
 * URL base de la API RIS (sin barra final).
 * Debe ser `var API_URL` (global) para scripts en modo estricto (p. ej. callbacks de jQuery).
 */
function resolveRisApiUrl() {
    if (typeof window.__RIS_API_URL__ === 'string' && window.__RIS_API_URL__) {
        return window.__RIS_API_URL__.replace(/\/$/, '');
    }

    const host = window.location.hostname;
    const isHttps = window.location.protocol === 'https:';

    if (host === 'localhost' || host === '127.0.0.1' || host === '') {
        return 'http://127.0.0.1:8000/api';
    }

    if (host === 'ris.healthticloud.cl' || host.endsWith('.healthticloud.cl')) {
        return 'https://api.healthticloud.cl/api';
    }

    const proto = isHttps ? 'https' : 'http';
    return `${proto}://${host}:8000/api`;
}

var API_URL = resolveRisApiUrl();
window.API_URL = API_URL;

/** Incrementar al desplegar frontend para evitar HTML/JS en caché del navegador */
window.RIS_BUILD = '20260601';

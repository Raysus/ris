/**
 * URL base de la API RIS (sin barra final).
 * Local: 127.0.0.1 / localhost → Laravel en :8000
 * Nube: ris.healthticloud.cl → api.healthticloud.cl
 * LAN: mismo host del navegador, puerto 8000
 */
(function () {
    if (typeof window.__RIS_API_URL__ === 'string' && window.__RIS_API_URL__) {
        window.API_URL = window.__RIS_API_URL__.replace(/\/$/, '');
        return;
    }

    const host = window.location.hostname;
    const isHttps = window.location.protocol === 'https:';

    if (host === 'localhost' || host === '127.0.0.1' || host === '') {
        window.API_URL = 'http://127.0.0.1:8000/api';
        return;
    }

    if (host === 'ris.healthticloud.cl' || host.endsWith('.healthticloud.cl')) {
        window.API_URL = 'https://api.healthticloud.cl/api';
        return;
    }

    const proto = isHttps ? 'https' : 'http';
    window.API_URL = `${proto}://${host}:8000/api`;
})();

/**
 * URL base de la API RIS (sin barra final).
 * Debe ser `var API_URL` (global) para scripts en modo estricto (p. ej. callbacks de jQuery).
 */
function isPrivateLanHost(host) {
    return /^(192\.168\.|172\.17\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);
}
/** Tailscale IPv4 (100.x.x.x): mismo criterio que LAN — API por nginx en :80 (/api). */
function isTailscaleHost(host) {
    return /^100\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host);
}

/** Hostnames DNS local (hosts/router) del laboratorio — no confundir con ris.healthticloud.cl (nube). */
const RIS_LOCAL_LAB_HOSTS = [
    'siresamatriz.healthticloud.cl',
];

function isLocalLabHostname(host) {
    if (RIS_LOCAL_LAB_HOSTS.includes(host)) {
        return true;
    }
    return /^lab[a-z0-9-]*\.healthticloud\.cl$/i.test(host);
}

/** *.healthticloud.cl de producción (nube), no alias LAN del laboratorio. */
function isCloudHealthticloudHost(host) {
    return /\.healthticloud\.cl$/i.test(host) && !isLocalLabHostname(host);
}

function isStandardWebPort(port) {
    return !port || port === '80' || port === '443';
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
        if (isStandardWebPort(port)) {
            return `${proto}://${host}/api`;
        }
        return `${proto}://${host}:8000/api`;
    }
    if (host === '') {
        return 'http://127.0.0.1:8000/api';
    }

    // Frontend nube y otros *.healthticloud.cl (excepto alias LAN de laboratorios)
    if (isCloudHealthticloudHost(host)) {
        return 'https://api.healthticloud.cl/api';
    }

    // Laboratorio con alias DNS local (*.healthticloud.cl → IP LAN, HTTP en :80)
    if (isLocalLabHostname(host) && isStandardWebPort(port)) {
        return `${proto}://${host}/api`;
    }

    // Laboratorio LAN / Tailscale: en :80 la API va por /api (mismo origen, sin CORS).
    if ((isPrivateLanHost(host) || isTailscaleHost(host)) && isStandardWebPort(port)) {
        return `${proto}://${host}/api`;
    }

    return `${proto}://${host}:8000/api`;
}

var API_URL = resolveRisApiUrl();
window.API_URL = API_URL;

/** Incrementar al desplegar frontend para evitar HTML/JS en caché del navegador */
window.RIS_BUILD = '20260710b';

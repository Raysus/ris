/* =========================================
   SERVICE WORKER - RIS PRO (Modo Offline)
   ========================================= */

const CACHE_NAME = 'ris-pro-cache-v1';

// Aquí listamos TODOS los archivos estáticos que hacen funcionar tu app.
// Ajusta las rutas si tienes carpetas diferentes.
const ASSETS_TO_CACHE = [
    '/',
    '/index.html',

    // CSS y Librerías Externas (Puedes agregar tus CSS locales aquí)
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js',
    'https://cdn.jsdelivr.net/npm/chart.js',

    // Core JS
    './js/core/storage.js',
    './js/core/router.js',

    // Workflow JS
    './js/admin/admin.js',
    './js/workflow/agenda.js',
    './js/workflow/worklist.js',
    './js/workflow/radiologist.js',
    './js/workflow/transcription.js',
    './js/workflow/validation.js',
    './js/workflow/entrega.js',
    './js/dashboard/dashboard.js',

    // Vistas HTML
    './pages/admin.html',
    './pages/agenda.html',
    './pages/worklist.html',
    './pages/radiologist.html',
    './pages/transcription.html',
    './pages/validation.html',
    './pages/entrega.html',
    './pages/dashboard.html'
];

// 1. EVENTO INSTALAR: Modo Indestructible (Forgiving Cache)
self.addEventListener('install', (event) => {
    console.log('[Service Worker] Iniciando instalación a prueba de fallos...');

    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            // Recorremos la lista uno por uno
            for (let asset of ASSETS_TO_CACHE) {
                try {
                    // Hacemos la petición al archivo manualmente
                    const request = new Request(asset, { mode: 'no-cors' });
                    const response = await fetch(request);

                    // Si el archivo existe o responde, lo guardamos
                    if (response.ok || response.type === 'opaque') {
                        await cache.put(request, response);
                        console.log('✅ Cacheado con éxito:', asset);
                    } else {
                        console.warn('⚠️ Ignorado (Ruta incorrecta o no existe):', asset);
                    }
                } catch (error) {
                    console.warn('❌ Ignorado (Error de red al buscar):', asset);
                }
            }
        }).then(() => {
            console.log('[Service Worker] Instalación completada. Activando...');
            return self.skipWaiting();
        })
    );
});

// 3. EVENTO FETCH (INTERCEPTOR SEGURO)
self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request).then((response) => {
            // Devuelve la versión cacheada o, si no existe, hace la petición real
            return response || fetch(event.request);
        })
    );
});
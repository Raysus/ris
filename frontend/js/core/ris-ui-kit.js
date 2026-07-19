/**
 * Primitivas UI del RIS (sin React): empty states, chips, headers.
 * Depende de risEscapeHtml (escape.js).
 */
(function (global) {
    'use strict';

    function esc(text) {
        return typeof global.risEscapeHtml === 'function'
            ? global.risEscapeHtml(text)
            : String(text ?? '');
    }

    /**
     * @param {{ icon?: string, title?: string, message?: string, ctaHtml?: string }} opts
     */
    function risEmptyStateHtml(opts) {
        const o = opts || {};
        const icon = o.icon || 'bi-inbox';
        const title = o.title ? `<div class="ris-empty-state__title">${esc(o.title)}</div>` : '';
        const message = o.message ? `<div class="ris-empty-state__message">${esc(o.message)}</div>` : '';
        const cta = o.ctaHtml ? `<div class="ris-empty-state__cta">${o.ctaHtml}</div>` : '';
        return `<div class="ris-empty-state" role="status">
            <i class="bi ${esc(icon)} ris-empty-state__icon" aria-hidden="true"></i>
            ${title}${message}${cta}
        </div>`;
    }

    /**
     * @param {string} label
     * @param {'neutral'|'success'|'warning'|'danger'|'info'|'primary'|string} tone
     */
    function risStatusChipHtml(label, tone) {
        const t = tone || 'neutral';
        return `<span class="ris-status-chip ris-status-chip--${esc(t)}">${esc(label)}</span>`;
    }

    function risSyncSidebarAria() {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('toggleSidebar');
        if (!sidebar || !toggle) return;
        const isMobile = window.innerWidth <= 768;
        const expanded = isMobile
            ? sidebar.classList.contains('mobile-open')
            : !sidebar.classList.contains('collapsed');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function risInitAdminHubs() {
        const root = document.getElementById('adminTabs');
        if (!root) return;
        root.querySelectorAll('.admin-hub').forEach((hub) => {
            const summary = hub.querySelector('.admin-hub-summary');
            if (!summary) return;
            summary.addEventListener('click', (e) => {
                // Allow native <details> toggle; keep ARIA in sync
                requestAnimationFrame(() => {
                    summary.setAttribute('aria-expanded', hub.open ? 'true' : 'false');
                });
            });
            summary.setAttribute('aria-expanded', hub.open ? 'true' : 'false');
        });

        // Expand hub that contains the active tab
        const active = root.querySelector('.nav-link.active');
        if (active) {
            const hub = active.closest('.admin-hub');
            if (hub) hub.open = true;
        }

        root.querySelectorAll('.nav-link[data-bs-toggle="tab"]').forEach((btn) => {
            btn.addEventListener('shown.bs.tab', () => {
                const hub = btn.closest('.admin-hub');
                if (hub) hub.open = true;
            });
        });
    }

    global.risEmptyStateHtml = risEmptyStateHtml;
    global.risStatusChipHtml = risStatusChipHtml;
    global.risSyncSidebarAria = risSyncSidebarAria;
    global.risInitAdminHubs = risInitAdminHubs;
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Captura screenshots reales del frontend RIS para el instructivo PDF.
 * Requisitos: backend en :8000, frontend servido en :5500, config.js presente.
 *
 * Uso:
 *   npx playwright install chromium
 *   node docs/capture_screenshots.mjs
 */

import { chromium } from "playwright";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(__dirname, "assets", "screenshots");
const BASE = process.env.RIS_FRONTEND_URL || "http://127.0.0.1:8765";
const API = process.env.RIS_API_URL || "http://127.0.0.1:8000/api";
const USER = process.env.RIS_USER || "rgutierrez";
const PASS = process.env.RIS_PASS || "rgutierrez";

fs.mkdirSync(OUT, { recursive: true });

async function shot(page, name, opts = {}) {
    const file = path.join(OUT, name);
    if (opts.fullPage) {
        await page.screenshot({ path: file, fullPage: true });
    } else if (opts.selector) {
        const el = page.locator(opts.selector);
        await el.waitFor({ state: "visible", timeout: 15000 });
        await el.screenshot({ path: file });
    } else {
        await page.screenshot({ path: file });
    }
    console.log(`  OK ${name}`);
}

async function waitApp(page) {
    await page.waitForFunction(() => typeof loadPage === "function", null, { timeout: 20000 });
    await page.waitForTimeout(1200);
}

async function goModule(page, module) {
    await page.click(`.sidebar nav a[data-page="${module}"]:visible`);
    await waitApp(page);
}

async function loginViaApi(page) {
    const res = await fetch(`${API}/login`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ login_field: USER, password: PASS }),
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.message || "Login API falló");
    }

    await page.goto(`${BASE}/index.html`, { waitUntil: "domcontentloaded" });
    await page.evaluate((payload) => {
        localStorage.setItem("ris_token", payload.access_token);
        const ctx = payload.contexto_laboratorio || {};
        let labId = ctx.laboratorio_id;
        const labs = ctx.laboratorios_permitidos || [];
        if (!labId && labs.length && labs[0] !== "*") labId = labs[0];
        if (labId) localStorage.setItem("ris_lab_id", labId);
        else localStorage.removeItem("ris_lab_id");
        const u = payload.user || {};
        localStorage.setItem("ris_user_profile", u.tipo_usuario?.name || u.role || "admin");
        localStorage.setItem("ris_permissions", JSON.stringify(u.tipo_usuario?.permissions || {}));
        localStorage.setItem("ris_user_data", JSON.stringify(u));
        localStorage.setItem("ris_all_labs", labs.includes("*") ? "true" : "false");
    }, data);
}

async function main() {
    console.log(`Frontend: ${BASE}`);
    console.log(`Salida: ${OUT}`);

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1.25,
    });
    const page = await context.newPage();

    // Login screen (sin sesión)
    await page.goto(`${BASE}/index.html`, { waitUntil: "networkidle" });
    await shot(page, "01_login.png", { selector: ".login-card" });

    await loginViaApi(page);
    await page.goto(`${BASE}/layout.html`, { waitUntil: "networkidle" });
    await waitApp(page);
    await shot(page, "02_layout_agenda.png");

    const modules = [
        ["agenda", "03_modulo_agenda.png"],
        ["worklist", "04_modulo_worklist.png"],
        ["radiologist", "05_modulo_radiologo.png"],
        ["transcription", "06_modulo_transcripcion.png"],
        ["validation", "07_modulo_validacion.png"],
        ["entrega", "08_modulo_entrega.png"],
        ["admin", "09_modulo_admin.png"],
        ["dashboard", "10_modulo_dashboard.png"],
    ];

    for (const [mod, file] of modules) {
        const link = page.locator(`.sidebar nav a[data-page="${mod}"]`);
        if ((await link.count()) === 0 || !(await link.isVisible())) {
            console.log(`  SKIP ${file} (sin permiso visible)`);
            continue;
        }
        await goModule(page, mod);
        await shot(page, file);
    }

    // Wizard agenda
    await goModule(page, "agenda");
    await page.evaluate(() => {
        if (typeof abrirModalCita === "function") {
            const d = new Date();
            d.setHours(10, 0, 0, 0);
            abrirModalCita({ start: d.toISOString() });
        }
    });
    await page.waitForSelector("#appointmentModal.show, #appointmentModal.showing", { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(800);
    const modalVisible = await page.locator("#appointmentModal.show, #appointmentModal.showing").count();
    if (modalVisible > 0) {
        await shot(page, "11_wizard_agenda.png", { selector: "#appointmentModal .modal-content" });
        await page.keyboard.press("Escape");
        await page.waitForTimeout(400);
    }

    await browser.close();
    console.log("Capturas completadas.");
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});

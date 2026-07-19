/* =========================================
   UTILIDADES UI GLOBALES (ui.js)
   ========================================= */

function updateSidebarUI(element) {
    $(".sidebar nav a").removeClass("active");
    $(element).addClass("active");
}

function showLoader(message) {
    const $loader = $("#globalLoader");
    if (message) {
        let $msg = $loader.find(".loader-message");
        if (!$msg.length) {
            $loader.append('<p class="loader-message text-white mt-3 mb-0 fw-bold"></p>');
            $msg = $loader.find(".loader-message");
        }
        $msg.text(message);
    }
    $loader.css("display", "flex").hide().fadeIn(100);
}

function hideLoader() {
    $("#globalLoader").fadeOut(100);
}

function _ensureToastContainer() {
    if (!$(".toast-container-ris").length) {
        $("body").append(
            '<div class="toast-container toast-container-ris position-fixed bottom-0 end-0 p-3" aria-live="polite" aria-atomic="true"></div>'
        );
    }
}

function showToast(msg, tipo = "info") {
    _ensureToastContainer();

    const bgClass = tipo === "danger" ? "text-bg-danger"
        : tipo === "warning" ? "text-bg-warning"
        : tipo === "success" ? "text-bg-success"
        : "text-bg-primary";

    const iconClass = tipo === "danger" ? "bi-x-circle-fill"
        : tipo === "warning" ? "bi-exclamation-triangle-fill"
        : tipo === "success" ? "bi-check-circle-fill"
        : "bi-info-circle-fill";

    const closeClass = tipo === "warning" ? "btn-close" : "btn-close btn-close-white";
    const id = `toast-${Date.now()}`;
    const safeMsg = typeof risSanitizeToastMessage === "function"
        ? risSanitizeToastMessage(msg)
        : String(msg ?? "");

    const html = `
        <div id="${id}" class="toast align-items-center ${bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"><i class="bi ${iconClass} me-2" aria-hidden="true"></i>${safeMsg}</div>
                <button type="button" class="${closeClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
            </div>
        </div>`;

    const $container = $(".toast-container-ris");
    $container.append(html);
    const el = document.getElementById(id);

    if (typeof bootstrap !== "undefined" && bootstrap.Toast) {
        const toast = bootstrap.Toast.getOrCreateInstance(el, { delay: 4000 });
        toast.show();
        el.addEventListener("hidden.bs.toast", () => el.remove());
    } else {
        setTimeout(() => $(`#${id}`).fadeOut(300, function () { $(this).remove(); }), 4000);
    }
}

function showAlert(message, title = "Aviso", type = "info") {
    const modalEl = document.getElementById("risAlertModal");
    if (!modalEl) {
        showToast(message, type === "danger" ? "danger" : "info");
        return Promise.resolve();
    }
    $("#risAlertTitle").text(title);
    $("#risAlertMessage").text(message);
    const header = $("#risAlertModal .modal-header");
    header.removeClass("bg-danger bg-success bg-primary bg-warning text-white text-dark");
    if (type === "danger") header.addClass("bg-danger text-white");
    else if (type === "success") header.addClass("bg-success text-white");
    else if (type === "warning") header.addClass("bg-warning text-dark");
    else header.addClass("bg-primary text-white");
    risBoostModalStack(modalEl);
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
    return Promise.resolve(modal);
}

function risCleanupModalState() {
    const openModals = document.querySelectorAll('.modal.show');
    const backdrops = document.querySelectorAll('.modal-backdrop');

    if (openModals.length === 0) {
        backdrops.forEach((el) => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    } else if (backdrops.length > openModals.length) {
        for (let i = backdrops.length - 1; i >= openModals.length; i -= 1) {
            backdrops[i]?.remove();
        }
    }

    document.querySelectorAll('.modal-backdrop').forEach((el) => {
        el.style.removeProperty('z-index');
    });
    document.querySelectorAll('.ris-system-modal').forEach((el) => {
        if (!el.classList.contains('show')) {
            el.style.removeProperty('z-index');
        }
    });
}

function showConfirm(message, options = {}) {
    const {
        title = "Confirmar acción",
        confirmText = "Confirmar",
        cancelText = "Cancelar",
        variant = "primary",
        dangerous = false,
        nested = false,
    } = options;

    return new Promise((resolve) => {
        const modalEl = document.getElementById("risConfirmModal");
        if (!modalEl) {
            resolve(window.confirm(message));
            return;
        }

        $("#risConfirmTitle").text(title);
        $("#risConfirmMessage").text(message);
        const $btn = $("#risConfirmBtn");
        $btn.text(confirmText).removeClass("btn-danger btn-primary btn-success btn-warning");
        $btn.addClass(dangerous ? "btn-danger" : `btn-${variant}`);
        $("#risConfirmCancelBtn").text(cancelText);

        risBoostModalStack(modalEl);

        const parentModalOpen = nested || !!document.querySelector('.modal.show:not(.ris-system-modal)');
        const previousInstance = bootstrap.Modal.getInstance(modalEl);
        if (previousInstance) {
            previousInstance.dispose();
        }
        const modal = new bootstrap.Modal(modalEl, {
            backdrop: parentModalOpen ? false : true,
            focus: true,
        });

        let confirmed = false;
        let settled = false;

        const cleanup = () => {
            $btn.off("click.risConfirm");
            $("#risConfirmCancelBtn").off("click.risConfirm");
            modalEl.removeEventListener("hidden.bs.modal", onHidden);
        };

        const finish = (value) => {
            if (settled) return;
            settled = true;
            cleanup();
            risCleanupModalState();
            resolve(value);
        };

        const onHidden = () => {
            finish(confirmed);
        };

        $btn.off("click.risConfirm").on("click.risConfirm", () => {
            confirmed = true;
            modal.hide();
        });

        $("#risConfirmCancelBtn").off("click.risConfirm").on("click.risConfirm", () => {
            confirmed = false;
            modal.hide();
        });

        modalEl.addEventListener("hidden.bs.modal", onHidden, { once: true });
        modal.show();
    });
}

function showPrompt(message, options = {}) {
    const {
        title = "Ingrese información",
        confirmText = "Aceptar",
        cancelText = "Cancelar",
        placeholder = "Escriba aquí...",
        required = true
    } = options;

    return new Promise((resolve) => {
        const modalEl = document.getElementById("risPromptModal");
        if (!modalEl) {
            resolve(window.prompt(message));
            return;
        }

        $("#risPromptTitle").text(title);
        $("#risPromptMessage").text(message);
        const $input = $("#risPromptInput");
        $input.val("").attr("placeholder", placeholder);
        $("#risPromptConfirmBtn").text(confirmText);
        $("#risPromptCancelBtn").text(cancelText);

        risBoostModalStack(modalEl);
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        const cleanup = () => {
            $("#risPromptConfirmBtn").off("click.risPrompt");
            $("#risPromptCancelBtn").off("click.risPrompt");
            modalEl.removeEventListener("hidden.bs.modal", onHidden);
        };

        const onHidden = () => {
            cleanup();
            resolve(null);
        };

        $("#risPromptConfirmBtn").off("click.risPrompt").on("click.risPrompt", () => {
            const value = $input.val()?.trim() ?? "";
            if (required && !value) {
                showToast("Debe ingresar un valor.", "warning");
                return;
            }
            cleanup();
            modal.hide();
            resolve(value || null);
        });

        $("#risPromptCancelBtn").off("click.risPrompt").on("click.risPrompt", () => {
            cleanup();
            modal.hide();
            resolve(null);
        });

        modalEl.addEventListener("hidden.bs.modal", onHidden, { once: true });
        modal.show();
        setTimeout(() => $input.trigger("focus"), 200);
    });
}

const RIS_SYSTEM_MODAL_IDS = ['risConfirmModal', 'risPromptModal', 'risAlertModal'];

function risEnsureModalInBody(id) {
    const el = typeof id === 'string' ? document.getElementById(id) : id;
    if (el && el.parentElement !== document.body) {
        document.body.appendChild(el);
    }
    return el;
}

/** Confirmación / alerta / prompt siempre encima de modales de página (ej. modalAtencion). */
function risBoostModalStack(modalEl) {
    if (!modalEl) return;
    risEnsureModalInBody(modalEl);
    modalEl.classList.add('ris-system-modal');

    const adjustStack = () => {
        let maxZ = 1055;
        document.querySelectorAll('.modal.show').forEach((m) => {
            if (m === modalEl) return;
            const z = parseInt(window.getComputedStyle(m).zIndex, 10);
            if (!Number.isNaN(z) && z >= maxZ) maxZ = z;
        });
        modalEl.style.zIndex = String(maxZ + 20);
        const backdrops = document.querySelectorAll('.modal-backdrop.show');
        if (backdrops.length) {
            backdrops[backdrops.length - 1].style.zIndex = String(maxZ + 10);
        }
    };

    modalEl.addEventListener('shown.bs.modal', adjustStack, { once: true });
    modalEl.addEventListener('hidden.bs.modal', () => {
        modalEl.style.zIndex = '';
    }, { once: true });
}

function risInitSystemModals() {
    RIS_SYSTEM_MODAL_IDS.forEach((id) => risEnsureModalInBody(id));
}

function openModal(id) {
    const el = document.getElementById(id);
    if (!el) return Promise.resolve();
    risEnsureModalInBody(el);

    if (!document.querySelector('.modal.show') && document.querySelector('.modal-backdrop')) {
        risCleanupModalState();
    }

    const modal = bootstrap.Modal.getOrCreateInstance(el);
    modal.show();
    return Promise.resolve(modal);
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;

    const finishClose = () => {
        el.classList.remove('show');
        el.setAttribute('aria-hidden', 'true');
        el.style.removeProperty('display');
        risCleanupModalState();
    };

    const modal = bootstrap.Modal.getInstance(el);
    if (!modal) {
        finishClose();
        return;
    }

    el.addEventListener('hidden.bs.modal', finishClose, { once: true });
    modal.hide();
}

function notify(title, message, type = "info") {
    showToast(`${title}: ${message}`, type);
}

function initMobileSidebar() {
    const $sidebar = $("#sidebar");
    const $overlay = $("#sidebarOverlay");
    const $toggle = $("#toggleSidebar");

    $toggle.off("click.mobileSidebar").on("click.mobileSidebar", () => {
        if (window.innerWidth > 768) return;
        $sidebar.toggleClass("mobile-open");
        $overlay.toggleClass("show", $sidebar.hasClass("mobile-open"));
        if (typeof risSyncSidebarAria === "function") risSyncSidebarAria();
    });

    $overlay.off("click.mobileSidebar").on("click.mobileSidebar", () => {
        $sidebar.removeClass("mobile-open");
        $overlay.removeClass("show");
        if (typeof risSyncSidebarAria === "function") risSyncSidebarAria();
    });

    $("#sidebar nav a").off("click.mobileSidebar").on("click.mobileSidebar", () => {
        if (window.innerWidth <= 768) {
            $sidebar.removeClass("mobile-open");
            $overlay.removeClass("show");
            if (typeof risSyncSidebarAria === "function") risSyncSidebarAria();
        }
    });

    if (typeof risSyncSidebarAria === "function") risSyncSidebarAria();
}

/* Wizard de agenda */
let _agendaWizardStep = 1;
const AGENDA_WIZARD_MAX = 4;

function initAgendaWizard() {
    _agendaWizardStep = 1;
    updateAgendaWizardUI();
}

async function goAgendaWizardStep(step) {
    if (step < 1 || step > AGENDA_WIZARD_MAX) return;
    if (step > _agendaWizardStep) {
        if (_agendaWizardStep === 1 && typeof window.ensureAgendaPacienteCargado === 'function') {
            await window.ensureAgendaPacienteCargado();
        }
        if (!validateAgendaWizardStep(_agendaWizardStep)) return;
    }
    _agendaWizardStep = step;
    updateAgendaWizardUI();
    if (step === 3 && typeof window.refreshAgendaExamSelects === 'function') {
        window.refreshAgendaExamSelects();
    }
}

async function nextAgendaWizardStep() {
    if (_agendaWizardStep === 1 && typeof window.ensureAgendaPacienteCargado === 'function') {
        await window.ensureAgendaPacienteCargado();
    }
    if (!validateAgendaWizardStep(_agendaWizardStep)) return;
    if (_agendaWizardStep < AGENDA_WIZARD_MAX) {
        _agendaWizardStep++;
        updateAgendaWizardUI();
    }
}

function prevAgendaWizardStep() {
    if (_agendaWizardStep > 1) {
        _agendaWizardStep--;
        updateAgendaWizardUI();
    }
}

function validateAgendaWizardStep(step) {
    if (step === 1) {
        const docLabel = (typeof getLabProfile === 'function' ? getLabProfile().patient_id_label : 'RUT');
        const pLabel = (typeof getLabProfile === 'function' ? getLabProfile().patient_label : 'Paciente');
        if (!$("#pRut").val()?.trim() || !$("#pName").val()?.trim() || !$("#pLastName").val()?.trim()) {
            showToast(`Complete ${docLabel}, nombres y apellido del ${pLabel.toLowerCase()}.`, "warning");
            return false;
        }
        if (typeof validarDocumentoAgenda === 'function' && !validarDocumentoAgenda()) {
            return false;
        }
    }
    if (step === 2) {
        if (!$("#mTratante").val()) {
            showToast("Seleccione el médico tratante.", "warning");
            return false;
        }
    }
    if (step === 3) {
        if ($(".study-entry").length === 0) {
            showToast("Agregue al menos un examen.", "warning");
            return false;
        }
        let filaValida = false;
        $(".study-entry").each(function () {
            const machine = $(this).find(".eMachine").val();
            const exam = $(this).find(".eExam").val();
            if (machine && exam) filaValida = true;
        });
        if (!filaValida) {
            showToast("Complete sala y examen en al menos una fila.", "warning");
            return false;
        }
    }
    return true;
}

function setAgendaWizardFooterBtn(id, visible) {
    const $btn = $(`#${id}`);
    if (!$btn.length) return;
    $btn.toggleClass("d-none", !visible);
    if (visible) {
        $btn.css("display", "");
    } else {
        $btn.css("display", "none");
    }
}

function updateAgendaWizardUI() {
    $(".agenda-wizard-step").addClass("d-none");
    $(`.agenda-wizard-step[data-step="${_agendaWizardStep}"]`).removeClass("d-none");

    $(".agenda-wizard-nav .nav-link").removeClass("active");
    $(`.agenda-wizard-nav .nav-link[data-step="${_agendaWizardStep}"]`).addClass("active");

    setAgendaWizardFooterBtn("btnWizardPrev", _agendaWizardStep > 1);
    setAgendaWizardFooterBtn("btnWizardNext", _agendaWizardStep < AGENDA_WIZARD_MAX);
    setAgendaWizardFooterBtn("btnGuardarCita", _agendaWizardStep === AGENDA_WIZARD_MAX);
}

/** Valida campos obligatorios marcados con una clase (ej. .req-sala). */
function validarFormulario(selector) {
    let ok = true;
    $(selector).each(function () {
        const $el = $(this);
        const val = $el.val();
        if (val === null || val === undefined || String(val).trim() === "") {
            $el.addClass("is-invalid").removeClass("is-valid");
            ok = false;
        } else {
            $el.removeClass("is-invalid").addClass("is-valid");
        }
    });
    if (!ok) {
        showToast("Complete los campos obligatorios marcados.", "warning");
    }
    return ok;
}

function limpiarFormulario(selector) {
    $(selector).removeClass("is-valid is-invalid");
}

window.validarFormulario = validarFormulario;
window.limpiarFormulario = limpiarFormulario;
window.risEnsureModalInBody = risEnsureModalInBody;
window.risBoostModalStack = risBoostModalStack;
window.risCleanupModalState = risCleanupModalState;
window.initAgendaWizard = initAgendaWizard;
window.goAgendaWizardStep = goAgendaWizardStep;
window.nextAgendaWizardStep = nextAgendaWizardStep;
window.prevAgendaWizardStep = prevAgendaWizardStep;
window.updateAgendaWizardUI = updateAgendaWizardUI;

$(document).ready(function () {
    risInitSystemModals();
    initMobileSidebar();
    $(window).on("resize", function () {
        initMobileSidebar();
        if (typeof risSyncSidebarAria === "function") risSyncSidebarAria();
    });
});

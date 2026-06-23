/**
 * Perfil operativo del laboratorio (clínico / dental / veterinario).
 */
window.RIS_LAB_PROFILE = window.RIS_LAB_PROFILE || null;

const RIS_DEFAULT_PROFILE = {
    code: 'clinical',
    uses_dicom_worklist: true,
    dicom_integration_mode: 'worklist',
    uses_fonasa: true,
    uses_bono: true,
    uses_clinical_insurance: true,
    show_insurance_fields: true,
    show_fonasa_panel: true,
    patient_label: 'Paciente',
    patient_id_label: 'RUT / Documento',
    service_code_label: 'Cód. FONASA',
    referring_label: 'Médico derivante',
};

/** UUID de sede concreta (no visión global ni "Todas mis sucursales"). */
function risIsConcreteLabId(labId) {
    if (labId === null || labId === undefined) return false;
    const v = String(labId).trim();
    return v !== '' && v !== 'ALL';
}

/** Headers Authorization + X-Lab-Id solo si hay sede concreta. */
function risBuildAuthHeaders(extra = {}) {
    const headers = {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('ris_token') || ''}`,
        ...extra,
    };
    const labId = typeof risRequireConcreteLabId === 'function'
        ? risRequireConcreteLabId(false)
        : localStorage.getItem('ris_lab_id');
    if (labId && labId !== 'ALL') {
        headers['X-Lab-Id'] = labId;
    }
    return headers;
}

/**
 * Bloquea carga de módulo si no hay sede concreta (p. ej. "Todas mis sucursales").
 * Sysadmin sin sede puede usar visión global en admin.
 */
function risGuardConcreteLabForModule(page) {
    if (typeof risIsSysAdmin === 'function' && risIsSysAdmin() && !risIsConcreteLabId(localStorage.getItem('ris_lab_id'))) {
        if (page === 'admin') {
            return true;
        }
    }
    if (typeof risRequireConcreteLabId === 'function') {
        return !!risRequireConcreteLabId();
    }
    const labId = localStorage.getItem('ris_lab_id');
    if (!labId || labId === 'ALL') {
        if (typeof showToast === 'function') {
            showToast('Seleccione una sede específica en la barra superior.', 'warning');
        }
        return false;
    }
    return true;
}

/** Devuelve ris_lab_id válido o null y opcionalmente muestra aviso. */
function risRequireConcreteLabId(showWarning = true) {
    const labId = localStorage.getItem('ris_lab_id');
    if (risIsConcreteLabId(labId)) {
        return labId;
    }
    if (showWarning && typeof showToast === 'function') {
        showToast('Seleccione una sede específica en la barra superior (no "Todas mis sucursales").', 'warning');
    }
    return null;
}

function getLabProfile() {
    if (window.RIS_LAB_PROFILE) {
        return { ...RIS_DEFAULT_PROFILE, ...window.RIS_LAB_PROFILE };
    }
    try {
        const stored = localStorage.getItem('ris_lab_profile');
        if (stored) {
            window.RIS_LAB_PROFILE = JSON.parse(stored);
            return { ...RIS_DEFAULT_PROFILE, ...window.RIS_LAB_PROFILE };
        }
    } catch (e) {
        console.warn('Perfil de laboratorio inválido en localStorage', e);
    }
    return { ...RIS_DEFAULT_PROFILE };
}

function setLabProfile(profile) {
    window.RIS_LAB_PROFILE = profile ? { ...RIS_DEFAULT_PROFILE, ...profile } : null;
    if (profile) {
        localStorage.setItem('ris_lab_profile', JSON.stringify(window.RIS_LAB_PROFILE));
        if (profile.code) {
            localStorage.setItem('ris_lab_type_code', profile.code);
        }
    } else {
        localStorage.removeItem('ris_lab_profile');
    }
}

function applyLabProfileUI(root = document) {
    const p = getLabProfile();
    const scope = root && root.querySelector ? root : document;

    const byId = (id) => scope.querySelector(`#${id}`);

    scope.querySelectorAll('[data-ris-label="patient"]').forEach(el => {
        el.textContent = p.patient_label;
    });
    const lblDoc = byId('lblDoc');
    if (lblDoc && !lblDoc.dataset.risLabelPrefix) {
        lblDoc.dataset.risLabelPrefix = 'N° de ';
        lblDoc.dataset.risLabelSuffix = ' *';
    }
    scope.querySelectorAll('[data-ris-label="patient-id"]').forEach(el => {
        const prefix = el.dataset.risLabelPrefix || '';
        const suffix = el.dataset.risLabelSuffix || '';
        el.textContent = `${prefix}${p.patient_id_label}${suffix}`;
    });
    scope.querySelectorAll('[data-ris-label="service-code"]').forEach(el => {
        el.textContent = p.service_code_label;
    });

    const panelFonasa = byId('panelFonasaBono');
    if (panelFonasa) {
        panelFonasa.classList.toggle('d-none', !p.show_fonasa_panel);
    }

    const tipoBono = byId('pTipoBono');
    if (tipoBono) {
        tipoBono.querySelectorAll('option').forEach(opt => {
            const needsFonasa = opt.dataset.requiresFonasa === 'true';
            opt.hidden = needsFonasa && !p.uses_bono;
            opt.disabled = needsFonasa && !p.uses_bono;
        });
        if (!p.uses_bono && (tipoBono.value === 'Electronico' || tipoBono.value === 'Manual')) {
            tipoBono.value = 'Sin Bono';
        }
    }

    const insuranceCol = byId('pInsurance')?.closest('.col-md-3');
    const planCol = byId('pPlan')?.closest('.col-md-3');
    if (insuranceCol) insuranceCol.classList.toggle('d-none', !p.show_insurance_fields);
    if (planCol) planCol.classList.toggle('d-none', !p.show_insurance_fields);
    if (!p.show_insurance_fields) {
        const ins = byId('pInsurance');
        const plan = byId('pPlan');
        if (ins) ins.value = '';
        if (plan) plan.value = '';
    }

    const copagoReadonly = byId('percentageInsurance');
    if (copagoReadonly && !p.uses_clinical_insurance) {
        copagoReadonly.value = '0';
    }
}

async function refreshLabProfileFromApi() {
    const token = localStorage.getItem('ris_token');
    const labId = localStorage.getItem('ris_lab_id');
    if (!token || !risIsConcreteLabId(labId)) {
        return getLabProfile();
    }

    try {
        const res = await fetch(`${API_URL}/lab-profile`, {
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json',
                'X-Lab-Id': labId,
            },
        });
        const json = await res.json();
        if (res.ok && json.success) {
            setLabProfile(json.data);
            applyLabProfileUI();
            if (typeof applyOperationalModuleNav === 'function') applyOperationalModuleNav();
            return getLabProfile();
        }
    } catch (e) {
        console.warn('No se pudo cargar lab-profile', e);
    }

    return getLabProfile();
}

/** Página de atención técnica según perfil del lab (worklist vs salas). */
function getOperationalTechnicianPage() {
    const p = getLabProfile();
    return p.uses_dicom_worklist === false ? 'atencion' : 'worklist';
}

/** Sysadmin: todos los laboratorios del sistema (ris_all_labs o perfil sis_admin). */
function risIsSysAdmin() {
    const profileName = (localStorage.getItem('ris_user_profile') || '').toLowerCase();
    if (profileName === 'sis_admin' || profileName === 'super_admin') {
        return true;
    }
    if (localStorage.getItem('ris_all_labs') === 'true') {
        return true;
    }
    try {
        const userData = JSON.parse(localStorage.getItem('ris_user_data') || '{}');
        if (String(userData.username || '').toLowerCase() === 'admin') {
            return true;
        }
        const roles = Array.isArray(userData.settings?.roles) ? userData.settings.roles : [];
        return roles.some((r) => String(r).toLowerCase() === 'sis_admin');
    } catch (e) {
        return false;
    }
}

/** Admin de clínica: todos los módulos del menú, pero solo en sus labs asignados. */
function risIsClinicAdmin() {
    const profileName = (localStorage.getItem('ris_user_profile') || '').toLowerCase();
    if (profileName === 'admin') {
        return true;
    }
    if (risIsSysAdmin()) {
        return true;
    }
    try {
        const userData = JSON.parse(localStorage.getItem('ris_user_data') || '{}');
        const roles = Array.isArray(userData.settings?.roles) ? userData.settings.roles : [];
        return roles.some((r) => String(r).toLowerCase() === 'admin');
    } catch (e) {
        return false;
    }
}

/** @deprecated Use risIsSysAdmin() */
function risHasGlobalAccess() {
    return risIsSysAdmin();
}

/** Muestra Worklist y Atención para admins; en dental/vet solo Atención en salas. */
function applyOperationalModuleNav() {
    const manual = getLabProfile().uses_dicom_worklist === false;

    const canOperational = (module) => {
        if (typeof risIsClinicAdmin === 'function' && risIsClinicAdmin()) {
            return true;
        }
        const allowed = {
            worklist: ['admin', 'tecnologo', 'sis_admin'],
            atencion: ['admin', 'tecnologo', 'sis_admin'],
        }[module] || [];
        const profileName = (localStorage.getItem('ris_user_profile') || '').toLowerCase();
        if (allowed.includes(profileName)) {
            return true;
        }
        try {
            const userData = JSON.parse(localStorage.getItem('ris_user_data') || '{}');
            const roles = Array.isArray(userData.settings?.roles) ? userData.settings.roles : [];
            return roles.some((r) => allowed.includes(String(r).toLowerCase()));
        } catch (e) {
            return false;
        }
    };

    if (risIsClinicAdmin()) {
        document.querySelectorAll('#sidebar nav a[data-page="worklist"]').forEach((el) => {
            el.classList.toggle('d-none', manual);
        });
        document.querySelectorAll('#sidebar nav a[data-page="atencion"]').forEach((el) => {
            el.classList.remove('d-none');
        });
        return;
    }
    document.querySelectorAll('#sidebar nav a[data-page="worklist"]').forEach((el) => {
        if (!canOperational('worklist')) return;
        el.classList.toggle('d-none', manual);
    });
    document.querySelectorAll('#sidebar nav a[data-page="atencion"]').forEach((el) => {
        if (!canOperational('atencion')) return;
        el.classList.toggle('d-none', !manual);
    });
}

window.getLabProfile = getLabProfile;
window.setLabProfile = setLabProfile;
window.applyLabProfileUI = applyLabProfileUI;
window.refreshLabProfileFromApi = refreshLabProfileFromApi;
window.getOperationalTechnicianPage = getOperationalTechnicianPage;
window.risIsSysAdmin = risIsSysAdmin;
window.risIsClinicAdmin = risIsClinicAdmin;
window.risHasGlobalAccess = risHasGlobalAccess;
window.applyOperationalModuleNav = applyOperationalModuleNav;
window.risIsConcreteLabId = risIsConcreteLabId;
window.risRequireConcreteLabId = risRequireConcreteLabId;
window.risBuildAuthHeaders = risBuildAuthHeaders;
window.risGuardConcreteLabForModule = risGuardConcreteLabForModule;

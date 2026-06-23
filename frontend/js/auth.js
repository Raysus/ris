$(document).ready(function () {

    if ($("#rememberMe").is(":checked") || localStorage.getItem("ris_remember_user") === "true") {
        const saved = localStorage.getItem("ris_saved_username");
        if (saved) {
            $("#username").val(saved);
            $("#rememberMe").prop("checked", true);
        }
    }

    async function intentarLogin() {
        const emailInput = $("#username").val().trim();
        const passwordInput = $("#password").val();

        $("#loginError").addClass("d-none");

        if (!emailInput || !passwordInput) {
            $("#loginError").removeClass("d-none").text("Por favor, ingrese su usuario y contraseña.");
            return;
        }

        const $btn = $("#loginBtn");
        const textoOriginal = $btn.html();
        $btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm me-2"></span>Conectando...');

        const apiBase = typeof API_URL !== 'undefined' ? API_URL : window.API_URL;
        if (!apiBase) {
            $("#loginError").removeClass("d-none").text('Configuración incompleta: no se cargó js/config.js. Recargue la página (Ctrl+F5).');
            $btn.prop("disabled", false).html(textoOriginal);
            return;
        }

        try {
            const response = await fetch(`${apiBase}/login`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    login_field: emailInput,
                    password: passwordInput
                })
            });

            let data;
            try {
                data = await response.json();
            } catch (e) {
                throw new Error("El servidor no devolvió una respuesta válida.");
            }

            if (response.ok && data.success) {
                if ($("#rememberMe").is(":checked")) {
                    localStorage.setItem("ris_remember_user", "true");
                    localStorage.setItem("ris_saved_username", emailInput);
                } else {
                    localStorage.removeItem("ris_remember_user");
                    localStorage.removeItem("ris_saved_username");
                }

                localStorage.setItem('ris_token', data.access_token);

                let labIdInicial = data.contexto_laboratorio.laboratorio_id;
                const labsPermitidos = data.contexto_laboratorio.laboratorios_permitidos || [];
                if (!labIdInicial && labsPermitidos.length > 0 && labsPermitidos[0] !== '*') {
                    labIdInicial = labsPermitidos[0];
                }
                if (labIdInicial) {
                    localStorage.setItem('ris_lab_id', labIdInicial);
                } else {
                    localStorage.removeItem('ris_lab_id');
                }
                const labNombre = data.contexto_laboratorio?.laboratorio_nombre || '';
                if (labNombre) {
                    localStorage.setItem('ris_lab_name', labNombre);
                } else {
                    localStorage.removeItem('ris_lab_name');
                }

                const labsPermitidos = data.contexto_laboratorio.laboratorios_permitidos || [];
                const esSysAdmin = labsPermitidos.includes('*')
                    || String(data.user.username || '').toLowerCase() === 'admin';
                const profileName = esSysAdmin
                    ? 'sis_admin'
                    : (data.user.tipo_usuario?.name || data.user.role || 'Invitado');
                const permissions = data.user.tipo_usuario?.permissions || {};

                localStorage.setItem('ris_user_profile', profileName);
                localStorage.setItem('ris_permissions', JSON.stringify(permissions));
                localStorage.setItem('ris_user_data', JSON.stringify(data.user));
                localStorage.setItem('ris_all_labs', esSysAdmin ? 'true' : 'false');
                localStorage.setItem('ris_labs_permitidos', JSON.stringify(labsPermitidos));

                if (data.contexto_laboratorio.perfil_laboratorio) {
                    localStorage.setItem('ris_lab_profile', JSON.stringify(data.contexto_laboratorio.perfil_laboratorio));
                    localStorage.setItem('ris_lab_type_code', data.contexto_laboratorio.tipo_laboratorio_code || 'clinical');
                }
                if (data.contexto_laboratorio.tipo_laboratorio_id) {
                    localStorage.setItem('ris_lab_type_id', data.contexto_laboratorio.tipo_laboratorio_id);
                }

                window.location.href = "layout.html";
            } else {
                $("#loginError").removeClass("d-none").text(data.message || "Usuario o contraseña incorrectos.");
                $("#password").val("").focus();
            }

        } catch (error) {
            console.error('Error de red o servidor:', error);
            $("#loginError").removeClass("d-none").text('No se pudo conectar con el servidor. Revise su conexión.');
        } finally {
            $btn.prop("disabled", false).html(textoOriginal);
        }
    }

    $("#loginBtn").click(intentarLogin);

    $("#password, #username").keypress(function (e) {
        if (e.which === 13) intentarLogin();
    });
});

function mostrarModalRecuperar() {
    openModal("modalRecuperarPassword");
}

async function enviarSolicitudRecuperacion() {
    const email = $("#emailRecuperar").val().trim();
    if (!email) return showToast("Ingrese un correo válido.", "warning");

    const $btn = $("#modalRecuperarPassword .btn-primary");

    try {
        $btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span>');

        const apiBase = typeof API_URL !== 'undefined' ? API_URL : window.API_URL;
        if (!apiBase) {
            showToast('No se cargó js/config.js. Recargue la página (Ctrl+F5).', 'danger');
            return;
        }
        const response = await fetch(`${apiBase}/password/forgot`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email: email })
        });

        const data = await response.json();

        if (response.ok && data.success) {
            showToast("Correo enviado. Revise su bandeja de entrada.", "success");
            closeModal("modalRecuperarPassword");
        } else {
            showToast(data.message || 'El correo no existe en el sistema.', "danger");
        }
    } catch (error) {
        showToast("Error de conexión", "danger");
    } finally {
        $btn.prop("disabled", false).text('Enviar Instrucciones');
    }
}

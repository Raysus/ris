const API_BASE_URL = 'http://localhost/api';
$(document).ready(function () {


    async function intentarLogin() {
        const emailInput = $("#username").val().trim();
        const passwordInput = $("#password").val();

        const laboratorioSeleccionado = $("#laboratorio").val() || 1;

        if (!emailInput || !passwordInput) {
            alert("Por favor, ingrese su correo y contraseña.");
            return;
        }

        const $btn = $("#loginBtn");
        const textoOriginal = $btn.text();
        $btn.prop("disabled", true).text("Conectando...");

        try {
            const response = await fetch(`${API_URL}/login`, {
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
                localStorage.setItem('ris_token', data.access_token);

                const labIdInicial = data.contexto_laboratorio.laboratorio_id;
                localStorage.setItem('ris_lab_id', labIdInicial);

                const profileName = data.user.tipo_usuario?.name || 'Invitado';
                const permissions = data.user.tipo_usuario?.permissions || {};

                localStorage.setItem('ris_user_profile', profileName);
                localStorage.setItem('ris_permissions', JSON.stringify(permissions));
                localStorage.setItem('ris_user_data', JSON.stringify(data.user));

                localStorage.setItem('ris_all_labs', data.contexto_laboratorio.laboratorios_permitidos.includes('*') ? 'true' : 'false');

                window.location.href = "layout.html";
            } else {
                alert("Error: " + (data.message || "Usuario o contraseña incorrectos."));
                $("#password").val("").focus();
            }

        } catch (error) {
            console.error('Error de red o servidor:', error);
            alert('No se pudo conectar con el servidor. Revise su conexión o contacte soporte.');
        } finally {
            $btn.prop("disabled", false).text(textoOriginal);
        }
    }

    $("#loginBtn").click(intentarLogin);

    $("#password").keypress(function (e) {
        if (e.which === 13) intentarLogin();
    });
});

function mostrarModalRecuperar() {
    $("#modalRecuperarPassword").modal('show');
}

async function enviarSolicitudRecuperacion() {
    const email = $("#emailRecuperar").val().trim();
    if (!email) return showToast("⚠️ Ingrese un correo válido.", "warning");

    const $btn = $("#modalRecuperarPassword .btn-primary");

    try {
        $btn.prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span>');

        const response = await fetch(`${API_URL}/password/forgot`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email: email })
        });

        const data = await response.json();

        if (response.ok && data.success) {
            showToast("✅ Correo enviado. Revise su bandeja de entrada.", "success");
            $("#modalRecuperarPassword").modal('hide');
        } else {
            showToast(`❌ ${data.message || 'El correo no existe en el sistema.'}`, "danger");
        }
    } catch (error) {
        showToast("🔌 Error de conexión", "danger");
    } finally {
        $btn.prop("disabled", false).text('Enviar Instrucciones');
    }
}

function showToast(mensaje, tipo = "info") {
    let toastContainer = document.getElementById("toast-container-login");
    if (!toastContainer) {
        const containerHtml = `<div id="toast-container-login" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1055;"></div>`;
        document.body.insertAdjacentHTML("beforeend", containerHtml);
        toastContainer = document.getElementById("toast-container-login");
    }

    let bgClass = "bg-primary text-white";
    let btnCloseClass = "btn-close-white";

    if (tipo === "danger") bgClass = "bg-danger text-white";
    if (tipo === "success") bgClass = "bg-success text-white";
    if (tipo === "warning") {
        bgClass = "bg-warning text-dark";
        btnCloseClass = "";
    }

    const toastId = "toast-" + Date.now();
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center ${bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body fw-bold">
                    ${mensaje}
                </div>
                <button type="button" class="btn-close ${btnCloseClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;

    toastContainer.insertAdjacentHTML("beforeend", toastHtml);
    const toastEl = document.getElementById(toastId);

    if (typeof bootstrap !== 'undefined') {
        const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();

        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });
    } else {
        alert(mensaje);
        toastEl.remove();
    }
}
/**
 * Botón ojo en inputs type="password" (login, recuperación, etc.)
 */
(function () {
    function togglePasswordInput(btn) {
        const group = btn.closest(".input-group");
        const input = group?.querySelector("input");
        const icon = btn.querySelector("i");
        if (!input || !icon) return;

        const show = input.type === "password";
        input.type = show ? "text" : "password";
        icon.classList.toggle("bi-eye", !show);
        icon.classList.toggle("bi-eye-slash", show);
        btn.setAttribute("aria-label", show ? "Ocultar contraseña" : "Mostrar contraseña");
        btn.setAttribute("aria-pressed", show ? "true" : "false");
    }

    function bindPasswordToggle(btn) {
        if (btn.dataset.passwordToggleBound === "1") return;
        btn.dataset.passwordToggleBound = "1";
        btn.setAttribute("type", "button");
        btn.setAttribute("aria-pressed", "false");
        if (!btn.getAttribute("aria-label")) {
            btn.setAttribute("aria-label", "Mostrar contraseña");
        }
        btn.addEventListener("click", function () {
            togglePasswordInput(btn);
        });
    }

    function initPasswordToggles(root) {
        (root || document).querySelectorAll(".password-toggle-btn").forEach(bindPasswordToggle);
    }

    window.initPasswordToggles = initPasswordToggles;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            initPasswordToggles();
        });
    } else {
        initPasswordToggles();
    }
})();

$(document).ready(function () {
    if (typeof loadRISState === "function") loadRISState();

    function intentarLogin() {
        const username = $("#username").val().trim();
        const password = $("#password").val();

        if (!username || !password) {
            alert("Por favor, ingrese usuario y contraseña.");
            return;
        }

        if (window.RIS && window.RIS.users) {
            const usuarioValido = window.RIS.users.find(u => u.username === username && u.password === password);

            if (usuarioValido) {
                localStorage.setItem("ris_current_user", JSON.stringify(usuarioValido));

                window.location.href = "layout.html";
            } else {
                alert("Usuario o contraseña incorrectos.");
                $("#password").val("").focus();
            }
        } else {
            alert("Error de sistema: Base de datos no inicializada.");
        }
    }

    $("#loginBtn").click(intentarLogin);

    $("#password").keypress(function (e) {
        if (e.which === 13) intentarLogin();
    });
});
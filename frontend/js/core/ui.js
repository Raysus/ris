function updateSidebarUI(element) {
    $(".sidebar nav a").removeClass("active");
    $(element).addClass("active");
}

function showLoader() {
    $("#globalLoader").fadeIn(100);
}

function hideLoader() {
    $("#globalLoader").fadeOut(100);
}

function showToast(msg, tipo) {
    if ($(".toast-container").length === 0) {
        $("body").append('<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>');
    }

    const bgClass = tipo === 'danger' ? 'text-bg-danger'
        : tipo === 'warning' ? 'text-bg-warning'
        : tipo === 'success' ? 'text-bg-success'
        : 'text-bg-primary';

    const id = Date.now();
    const html = `
        <div id="toast-${id}" class="toast align-items-center ${bgClass} border-0 show" role="alert">
            <div class="d-flex">
                <div class="toast-body">${msg}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;

    $(".toast-container").append(html);
    setTimeout(() => { $(`#toast-${id}`).fadeOut(300, function () { $(this).remove(); }); }, 3000);
}

function notify(title, message, type = 'info') {
    console.log(`[${type.toUpperCase()}] ${title}: ${message}`);

    if (typeof showToast === 'function') {
        showToast(`${title}: ${message}`, type);
    } else {
        alert(`${title}\n${message}`);
    }
}
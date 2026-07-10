/* =========================================
   Pegar / soltar archivos de texto en textareas de informe
   ========================================= */

function risIsPlainTextFile(file) {
    if (!file) return false;
    const name = String(file.name || '').toLowerCase();
    const type = String(file.type || '').toLowerCase();
    if (type.startsWith('text/')) return true;
    return /\.(txt|text|md|csv|log|json|xml|html?|rtf)$/i.test(name);
}

function risReadTextFile(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result || ''));
        reader.onerror = () => reject(reader.error || new Error('No se pudo leer el archivo'));
        reader.readAsText(file, 'UTF-8');
    });
}

function risInsertTextAtCursor(textarea, text) {
    if (!textarea || text == null) return;
    const el = textarea;
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? el.value.length;
    const before = el.value.slice(0, start);
    const after = el.value.slice(end);
    el.value = before + text + after;
    const pos = start + String(text).length;
    el.selectionStart = el.selectionEnd = pos;
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * Habilita Ctrl+V / pegar y drag-drop de .txt (y similares) en selectores de textarea.
 * @param {string|string[]} selectors
 */
function risEnableTextFilePaste(selectors) {
    const list = Array.isArray(selectors) ? selectors : [selectors];
    list.forEach((sel) => {
        const nodes = document.querySelectorAll(sel);
        nodes.forEach((el) => {
            if (!el || el.dataset.risTextPaste === '1') return;
            el.dataset.risTextPaste = '1';

            el.addEventListener('paste', async (e) => {
                const items = e.clipboardData?.items;
                const files = e.clipboardData?.files;
                let file = null;
                if (files && files.length) {
                    file = Array.from(files).find(risIsPlainTextFile) || null;
                }
                if (!file && items) {
                    for (const item of items) {
                        if (item.kind === 'file') {
                            const f = item.getAsFile();
                            if (risIsPlainTextFile(f)) {
                                file = f;
                                break;
                            }
                        }
                    }
                }
                if (!file) return;
                e.preventDefault();
                try {
                    const text = await risReadTextFile(file);
                    risInsertTextAtCursor(el, text);
                    if (typeof showToast === 'function') {
                        showToast(`Texto pegado desde «${file.name}».`, 'success');
                    }
                } catch (err) {
                    console.warn('risEnableTextFilePaste paste:', err);
                    if (typeof showToast === 'function') {
                        showToast('No se pudo pegar el archivo de texto.', 'danger');
                    }
                }
            });

            el.addEventListener('dragover', (e) => {
                if ([...e.dataTransfer.types].includes('Files')) {
                    e.preventDefault();
                    el.classList.add('ris-drop-text-active');
                }
            });
            el.addEventListener('dragleave', () => el.classList.remove('ris-drop-text-active'));
            el.addEventListener('drop', async (e) => {
                el.classList.remove('ris-drop-text-active');
                const file = Array.from(e.dataTransfer?.files || []).find(risIsPlainTextFile);
                if (!file) return;
                e.preventDefault();
                try {
                    const text = await risReadTextFile(file);
                    risInsertTextAtCursor(el, text);
                    if (typeof showToast === 'function') {
                        showToast(`Texto cargado desde «${file.name}».`, 'success');
                    }
                } catch (err) {
                    console.warn('risEnableTextFilePaste drop:', err);
                    if (typeof showToast === 'function') {
                        showToast('No se pudo cargar el archivo de texto.', 'danger');
                    }
                }
            });
        });
    });
}

window.risEnableTextFilePaste = risEnableTextFilePaste;

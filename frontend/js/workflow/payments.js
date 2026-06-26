/**
 * MÓDULO DE GESTIÓN DE PAGOS
 */

function payEscapeHtml(text) {
    return typeof risEscapeHtml === 'function' ? risEscapeHtml(text) : String(text ?? '');
}

function showPaymentToast(msg, type) {
    if (typeof showToast === 'function') {
        showToast(msg, type || 'info');
    } else {
        console.log(msg);
    }
}

function showPaymentModal(title, html) {
    let modal = document.getElementById('paymentReceiptModal');
    if (!modal) {
        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal fade" id="paymentReceiptModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="paymentReceiptModalTitle"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="paymentReceiptModalBody"></div>
                    </div>
                </div>
            </div>`);
        modal = document.getElementById('paymentReceiptModal');
    }
    document.getElementById('paymentReceiptModalTitle').textContent = title;
    document.getElementById('paymentReceiptModalBody').innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

class PaymentManager {
    constructor() {
        this.currentBreakdown = null;
        this.currentPayments = [];
        this.apiUrl = `${API_URL}/payments`;
    }

    setupEventListeners() {
        if (this._listenersReady) return;
        this._listenersReady = true;

        const studyBody = document.getElementById('studyBody');
        if (studyBody) {
            const observer = new MutationObserver(() => {
                if (this._updatingDesglose) return;
                this.actualizarDesglosePrecios();
            });
            observer.observe(studyBody, { childList: true, subtree: true });
        }

        ['percentageInsurance', 'payMethod', 'paymentStatus'].forEach(campo => {
            const el = document.getElementById(campo);
            if (el) el.addEventListener('change', () => this.recalcularTotal());
        });

        const tipoBono = document.getElementById('pTipoBono');
        if (tipoBono) {
            tipoBono.addEventListener('change', () => {
                this.toggleFonasaPanel();
                const aid = document.getElementById('appointmentId')?.value;
                if (aid) this.cargarPreviewFonasa(aid);
            });
        }

        $(document).on('input.agendaPagos change.agendaPagos', '.ePrice, .eQty', () => {
            this.actualizarDesglosePrecios();
        });
    }

    actualizarDesglosePrecios() {
        const tbody = document.getElementById('desgloseBody');
        if (!tbody) return;

        this._updatingDesglose = true;
        const porcentaje = parseFloat(document.getElementById('percentageInsurance')?.value) || 0;
        let html = '';
        let subtotal = 0;

        document.querySelectorAll('.study-entry').forEach(row => {
            const nombre = row.querySelector('.eExam option:checked')?.text?.trim() || 'Examen';
            const precio = parseFloat(row.querySelector('.ePrice')?.value) || 0;
            const qty = parseInt(row.querySelector('.eQty')?.value, 10) || 1;
            const totalLinea = precio * qty;
            subtotal += totalLinea;
            const copago = (totalLinea * porcentaje) / 100;
            html += `
                <tr>
                    <td class="small"><strong>${payEscapeHtml(nombre)}</strong></td>
                    <td class="text-end small">$${this.formatPeso(totalLinea)}</td>
                    <td class="text-end small">${porcentaje}%</td>
                    <td class="text-end small text-danger">$${this.formatPeso(copago)}</td>
                </tr>`;
        });

        if (!html) {
            html = `<tr><td colspan="4" class="text-muted text-center small py-2">Agregue exámenes para ver el desglose</td></tr>`;
        }

        tbody.innerHTML = html;
        this.currentBreakdown = {
            resumen: {
                total_arancel: subtotal,
                copago: (subtotal * porcentaje) / 100,
                subtotal: subtotal
            }
        };
        this._updatingDesglose = false;
    }

    async cargarDesglose(appointmentId) {
        try {
            const token = localStorage.getItem('ris_token');
            const labId = localStorage.getItem('ris_lab_id');

            const response = await fetch(`${this.apiUrl}/breakdown/${appointmentId}`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'X-Lab-Id': labId
                }
            });

            if (!response.ok) throw new Error('Error al cargar desglose');

            const result = await response.json();
            if (result.success) {
                this.currentBreakdown = result.data;
                this.actualizarUIDesglose(result.data);
                if (result.data.plan?.percentage != null) {
                    document.getElementById('percentageInsurance').value = result.data.plan.percentage;
                }
            }
        } catch (e) {
            console.error('Error cargando desglose:', e);
            this.actualizarDesglosePrecios();
        }
    }

    actualizarUIDesglose(data) {
        const tbody = document.getElementById('desgloseBody');
        if (!tbody) return;

        const porcentaje = parseFloat(document.getElementById('percentageInsurance')?.value)
            || parseFloat(data.plan?.percentage) || 0;

        let html = '';
        (data.estudios || []).forEach(estudio => {
            const copago = (estudio.precio * porcentaje) / 100;
            html += `
                <tr>
                    <td class="small"><strong>${payEscapeHtml(estudio.nombre)}</strong></td>
                    <td class="text-end small">$${this.formatPeso(estudio.precio)}</td>
                    <td class="text-end small">${porcentaje}%</td>
                    <td class="text-end small text-danger">$${this.formatPeso(copago)}</td>
                </tr>`;
        });

        if (!html) {
            this.actualizarDesglosePrecios();
            return;
        }

        tbody.innerHTML = html;
        this.recalcularTotal();
    }

    recalcularTotal() {
        if (!this.currentBreakdown?.resumen) return;
        const resumen = this.currentBreakdown.resumen;
        const totalFinal = Math.max(0, (resumen.subtotal ?? resumen.total_arancel) - (resumen.copago ?? 0));
        if (document.getElementById('totalCopay')) {
            document.getElementById('totalCopay').innerHTML = `$${this.formatPeso(totalFinal)}`;
        }
    }

    async registrarPago(appointmentId, datoPago) {
        try {
            const token = localStorage.getItem('ris_token');
            const labId = localStorage.getItem('ris_lab_id');

            const response = await fetch(`${this.apiUrl}`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'X-Lab-Id': labId,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    appointment_id: appointmentId,
                    amount: datoPago.monto,
                    payment_method: datoPago.metodo,
                    status: datoPago.estado,
                    transaction_code: datoPago.codigoTransaccion || null
                })
            });

            if (!response.ok) throw new Error('Error al registrar pago');

            const result = await response.json();
            if (result.success) {
                showPaymentToast('Pago registrado exitosamente', 'success');
                await this.cargarHistorialPagos(appointmentId);
                return result.data;
            }
        } catch (e) {
            console.error('Error registrando pago:', e);
            showPaymentToast('Error al registrar pago', 'danger');
        }
    }

    async cargarHistorialPagos(appointmentId) {
        try {
            const token = localStorage.getItem('ris_token');
            const labId = localStorage.getItem('ris_lab_id');

            const response = await fetch(`${this.apiUrl}/history/${appointmentId}`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'X-Lab-Id': labId
                }
            });

            if (!response.ok) throw new Error('Error al cargar historial');

            const result = await response.json();
            if (result.success) {
                this.actualizarUIHistorial(result.data);
            }
        } catch (e) {
            console.error('Error cargando historial:', e);
        }
    }

    actualizarUIHistorial(data) {
        const container = document.getElementById('historialPagosContainer');
        if (!container) return;

        if (!data.pagos || data.pagos.length === 0) {
            container.innerHTML = `
                <p class="text-muted text-center py-2 mb-0 small">
                    <i class="bi bi-inbox"></i> Sin pagos registrados aún
                </p>`;
            return;
        }

        let html = `
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="small">Fecha</th>
                            <th class="small">Monto</th>
                            <th class="small">Método</th>
                            <th class="small">Estado</th>
                        </tr>
                    </thead>
                    <tbody>`;

        data.pagos.forEach(pago => {
            const fecha = new Date(pago.created_at).toLocaleDateString('es-CL');
            html += `
                <tr>
                    <td class="small">${fecha}</td>
                    <td class="small fw-bold">$${this.formatPeso(pago.amount)}</td>
                    <td class="small">${payEscapeHtml(pago.payment_method)}</td>
                    <td class="small"><span class="badge bg-success">${payEscapeHtml(pago.status)}</span></td>
                </tr>`;
        });

        html += `
                    </tbody>
                </table>
            </div>
            <div class="alert alert-info py-2 mt-2 mb-0 small">
                <strong>Total pagado:</strong> $${this.formatPeso(data.resumen?.total_pagado || 0)}
                — <strong>Estado:</strong> ${payEscapeHtml(data.resumen?.estado || '-')}
            </div>`;

        container.innerHTML = html;
    }

    async generarComprobante(paymentId) {
        try {
            const token = localStorage.getItem('ris_token');
            const labId = localStorage.getItem('ris_lab_id');

            const response = await fetch(`${this.apiUrl}/${paymentId}/receipt`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'X-Lab-Id': labId
                }
            });

            if (!response.ok) throw new Error('Error al generar comprobante');

            const result = await response.json();
            if (result.success) {
                const c = result.data.comprobante;
                showPaymentModal('Comprobante de Pago', `
                    <p><strong>Paciente:</strong> ${payEscapeHtml(c.paciente)}</p>
                    <p><strong>Monto:</strong> $${this.formatPeso(c.monto)}</p>
                    <p><strong>Método:</strong> ${payEscapeHtml(c.metodo)}</p>
                    <p><strong>Fecha:</strong> ${payEscapeHtml(c.fecha)}</p>
                    <button class="btn btn-sm btn-primary" onclick="window.print()">Imprimir</button>`);
            }
        } catch (e) {
            console.error('Error:', e);
            showPaymentToast('Error al generar comprobante', 'danger');
        }
    }

    formatPeso(valor) {
        return Math.round(Number(valor) || 0).toLocaleString('es-CL');
    }

    toggleFonasaPanel() {
        const profile = typeof getLabProfile === 'function' ? getLabProfile() : { show_fonasa_panel: true };
        const panel = document.getElementById('panelFonasaBono');
        if (panel) {
            const tipo = document.getElementById('pTipoBono')?.value;
            const show = profile.show_fonasa_panel && (tipo === 'Electronico' || tipo === 'Manual');
            panel.classList.toggle('d-none', !show);
        }
    }

    async cargarPreviewFonasa(appointmentId) {
        const profile = typeof getLabProfile === 'function' ? getLabProfile() : {};
        if (!profile.show_fonasa_panel || !appointmentId) return;
        try {
            const res = await fetch(`${API_URL}/fonasa/appointments/${appointmentId}/preview`, {
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('ris_token')}`,
                    'X-Lab-Id': localStorage.getItem('ris_lab_id'),
                },
            });
            const json = await res.json();
            if (json.success) {
                const el = document.getElementById('fonasaMontosPreview');
                if (el) {
                    el.textContent = `Bonif. $${this.formatPeso(json.data.monto_bonificacion)} · Copago $${this.formatPeso(json.data.monto_copago)}`;
                }
            }
        } catch (e) {
            console.warn('Preview FONASA:', e);
        }
    }

    async registrarBonoFonasa(appointmentId) {
        const folio = document.getElementById('fonasaFolio')?.value?.trim();
        if (!folio || !appointmentId) {
            showPaymentToast('Ingrese folio y guarde la cita primero', 'warning');
            return null;
        }
        const res = await fetch(`${API_URL}/fonasa/appointments/${appointmentId}/bono`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('ris_token')}`,
                'X-Lab-Id': localStorage.getItem('ris_lab_id'),
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                folio,
                tipo: document.getElementById('pTipoBono')?.value || 'Electronico',
                rut_beneficiario: document.getElementById('fonasaRutBenef')?.value?.trim() || null,
                ...(window._risBonoMontos ? {
                    monto_total: window._risBonoMontos.monto_total,
                    monto_bonificacion: window._risBonoMontos.monto_bonificacion,
                    monto_copago: window._risBonoMontos.monto_copago,
                } : {}),
            }),
        });
        const json = await res.json();
        if (json.success) {
            showPaymentToast('Bono registrado', 'success');
            document.getElementById('pTransactionCode').value = folio;
            window._lastFonasaBonoId = json.data.id;
            return json.data;
        }
        showPaymentToast(json.message || 'Error al registrar bono', 'danger');
        return null;
    }

    async validarBonoFonasa(appointmentId, bonoId) {
        bonoId = bonoId || window._lastFonasaBonoId;
        if (!appointmentId || !bonoId) {
            showPaymentToast('Registre el bono antes de validar', 'warning');
            return;
        }
        const res = await fetch(`${API_URL}/fonasa/appointments/${appointmentId}/bono/${bonoId}/validate`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('ris_token')}`,
                'X-Lab-Id': localStorage.getItem('ris_lab_id'),
            },
        });
        const json = await res.json();
        const statusEl = document.getElementById('fonasaBonoStatus');
        if (statusEl) statusEl.textContent = json.message || '';
        showPaymentToast(json.message || (json.success ? 'Validado' : 'Rechazado'), json.success ? 'success' : 'danger');
        if (json.success && json.data?.estado === 'validado') {
            document.getElementById('paymentStatus').value = 'Pagado';
        }
    }

    async emitirDte(appointmentId, documentType = 'boleta') {
        if (!appointmentId) {
            showPaymentToast('Guarde la cita antes de emitir DTE', 'warning');
            return;
        }
        const res = await fetch(`${API_URL}/billing/appointments/${appointmentId}/dte`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('ris_token')}`,
                'X-Lab-Id': localStorage.getItem('ris_lab_id'),
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ document_type: documentType }),
        });
        const json = await res.json();
        if (json.success) {
            showPaymentModal('DTE emitido', `<p><strong>Folio:</strong> ${json.data.folio}</p><p><strong>Total:</strong> $${this.formatPeso(json.data.monto_total)}</p><p><strong>Estado:</strong> ${json.data.status}</p>`);
        } else {
            showPaymentToast(json.message || 'Error al emitir DTE', 'danger');
        }
    }
}

function registrarBonoFonasa() {
    initPaymentManager().registrarBonoFonasa(document.getElementById('appointmentId')?.value);
}

function validarBonoFonasa() {
    initPaymentManager().validarBonoFonasa(document.getElementById('appointmentId')?.value);
}

function emitirDteCita(tipo) {
    initPaymentManager().emitirDte(document.getElementById('appointmentId')?.value, tipo);
}

function initPaymentManager() {
    if (!window.paymentManager) {
        window.paymentManager = new PaymentManager();
        window.paymentManager.setupEventListeners();
    }
    return window.paymentManager;
}

window.PaymentManager = PaymentManager;
window.initPaymentManager = initPaymentManager;

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\WorklistController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\AgendaCatalogController;
use App\Http\Controllers\RadiologistController;
use App\Http\Controllers\TranscriptionController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\TemplateController;


use App\Http\Controllers\PaymentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ViewerConfigController;
use App\Http\Controllers\CloudSyncController;
use App\Http\Controllers\CloudSyncInboundController;
use App\Http\Controllers\LocalAppointmentSyncController;
use App\Http\Controllers\LocalMwlRelayController;
use App\Http\Controllers\Hl7Controller;
use App\Http\Controllers\FonasaController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\FhirController;
use App\Http\Controllers\LabProfileController;
use App\Http\Controllers\PatientPortalIntegrationController;

Route::get('/health', HealthController::class);

Route::post('/hl7/inbound', [Hl7Controller::class, 'inbound']);

Route::middleware('cloud.sync')->group(function () {
    Route::post('/integrations/cloud-sync/inbound', [CloudSyncInboundController::class, 'receive']);
    Route::get('/integrations/cloud-sync/export', [CloudSyncInboundController::class, 'export']);
    Route::post('/integrations/local-mwl/relay', [LocalMwlRelayController::class, 'receive']);
    Route::post('/integrations/local-sync/appointment', [LocalAppointmentSyncController::class, 'receive']);
});

Route::middleware('portal.integration')->group(function () {
    Route::get('/integrations/portal/reports', [PatientPortalIntegrationController::class, 'index']);
    Route::get('/integrations/portal/reports/{appointmentId}', [PatientPortalIntegrationController::class, 'show']);
});

Route::get('/fhir/metadata', [FhirController::class, 'metadata']);
Route::post('/fhir/ServiceRequest', [FhirController::class, 'serviceRequest']);

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('login');
Route::post('/password/forgot', [AuthController::class, 'forgotPassword']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', function (Request $request) {
        return $request->user();
    });
    // Cambia esto:
    Route::get('/laboratories', [SettingController::class, 'getAllLaboratories']);
    Route::get('/patients/search', [PatientController::class, 'searchByRut']);

    Route::apiResource('patients', PatientController::class);

    Route::apiResource('machines', MachineController::class);
    Route::post('/machines/{id}/ping', [MachineController::class, 'pingDicom']);

    Route::apiResource('exams', ExamController::class);
    Route::post('exams/import', [ExamController::class, 'importExams']);

    Route::get('/settings', [SettingController::class, 'getSettings']);
    Route::get('/laboratory-types', [SettingController::class, 'getLaboratoryTypes']);
    Route::post('/settings', [SettingController::class, 'updateSettings']);
    Route::post('/branches', [SettingController::class, 'storeBranch']);
    Route::delete('/branches/{id}', [SettingController::class, 'destroyBranch']);
    Route::get('/all-laboratories', [SettingController::class, 'getAllLaboratories']);
    Route::post('/laboratories', [SettingController::class, 'storeLaboratory']);

    Route::put('/appointments/{id}/status', [WorklistController::class, 'updateStatus']);
    Route::put('/appointments/{id}/clear-review', [AppointmentController::class, 'clearReview']);
    Route::apiResource('appointments', AppointmentController::class);
    Route::post('/referring-doctors', [AgendaCatalogController::class, 'storeReferringDoctor']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('supplies', SupplyController::class);

    Route::get('/worklist', [WorklistController::class, 'index']);
    Route::post('/appointments/{id}/dicom', [WorklistController::class, 'sendToDicom']);
    Route::post('/appointments/{id}/upload-dicom', [WorklistController::class, 'uploadDicomStudy']);
    Route::post('/appointments/{id}/mark-dicom-received', [WorklistController::class, 'markDicomReceived']);
    Route::post('/appointments/{id}/complete-worklist', [WorklistController::class, 'complete']);

    // MÓDULO RADIÓLOGO
    Route::get('/radiologist/studies', [RadiologistController::class, 'index']);
    Route::post('/radiologist/appointments/{id}/sign', [RadiologistController::class, 'signReport']);
    Route::post('/radiologist/appointments/{id}/draft', [RadiologistController::class, 'saveDraft']);
    Route::post('/radiologist/appointments/{id}/return', [RadiologistController::class, 'returnToTechnologist']);
    Route::post('/radiologist/appointments/{id}/transcribe', [RadiologistController::class, 'sendToTranscription']);
    Route::post('/radiologist/studies/{studyId}/upload', [RadiologistController::class, 'uploadStudyAudio']);

    // MÓDULO DE TRANSCRIPCIÓN
    Route::get('/transcription/studies', [TranscriptionController::class, 'index']);
    Route::get('/transcription/appointments', [TranscriptionController::class, 'index']);
    Route::post('/transcription/appointments/{id}/draft', [TranscriptionController::class, 'saveDraft']);
    Route::post('/transcription/appointments/{id}/validate', [TranscriptionController::class, 'sendToValidation']);
    Route::post('/transcription/appointments/{id}/return', [TranscriptionController::class, 'returnToDoctor']);

    // MÓDULO DE VALIDACIÓN
    Route::get('/radiologist/validations', [RadiologistController::class, 'validations']);
    Route::post('/radiologist/appointments/{id}/reject-transcription', [RadiologistController::class, 'rejectTranscription']);

    // MÓDULO DE ENTREGA DE RESULTADOS
    Route::get('/delivery/studies', [DeliveryController::class, 'index']);
    Route::post('/delivery/appointments/{id}/deliver', [DeliveryController::class, 'deliver']);
    Route::post('/delivery/appointments/{id}/revert', [DeliveryController::class, 'revert']);
    Route::post('/delivery/appointments/{id}/email', [DeliveryController::class, 'sendEmail']);
    Route::post('/delivery/appointments/{id}/log-print', [DeliveryController::class, 'logPrint']);

    // MÓDULO DE PAGOS (RECEPCIONISTA/CAJA)
    Route::get('/payments/breakdown/{appointment_id}', [PaymentController::class, 'getBreakdown']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/history/{appointment_id}', [PaymentController::class, 'getHistory']);
    Route::get('/payments/insurance-plans', [PaymentController::class, 'getInsurancePlans']);
    Route::get('/payments/reports/daily', [PaymentController::class, 'getDailyReport']);
    Route::get('/payments/reports/monthly', [PaymentController::class, 'getMonthlyReport']);
    Route::get('/payments/reports/cashier', [PaymentController::class, 'getCashierReport']);
    Route::get('/payments/reports/cash-close', [PaymentController::class, 'getCashClose']);
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'generateReceipt']);

    Route::get('/fonasa/appointments/{id}/preview', [FonasaController::class, 'preview']);
    Route::get('/fonasa/appointments/{id}/bono', [FonasaController::class, 'show']);
    Route::post('/fonasa/appointments/{id}/bono', [FonasaController::class, 'store']);
    Route::post('/fonasa/appointments/{id}/bono/{bonoId}/validate', [FonasaController::class, 'validateBono']);

    Route::get('/billing/appointments/{id}/dte', [BillingController::class, 'indexForAppointment']);
    Route::get('/billing/appointments/{id}/dte/preview', [BillingController::class, 'preview']);
    Route::post('/billing/appointments/{id}/dte', [BillingController::class, 'emit']);
    Route::get('/billing/dte', [BillingController::class, 'list']);
    Route::get('/billing/dte/{id}', [BillingController::class, 'show']);

    Route::get('/fhir/Patient/{id}', [FhirController::class, 'patient']);
    Route::get('/fhir/ServiceRequest', [FhirController::class, 'serviceRequest']);
    Route::get('/fhir/DiagnosticReport', [FhirController::class, 'diagnosticReport']);
    Route::get('/fhir/Appointment/{id}/bundle', [FhirController::class, 'appointmentBundle']);

    Route::get('/integrations/cloud-sync/status', [CloudSyncController::class, 'status']);
    Route::get('/integrations/cloud-sync', [CloudSyncController::class, 'index']);
    Route::post('/integrations/cloud-sync/pull-catalog', [CloudSyncController::class, 'pullCatalog']);
    Route::post('/integrations/cloud-sync/retry-failed', [CloudSyncController::class, 'retryFailed']);
    Route::post('/integrations/cloud-sync/{id}/retry', [CloudSyncController::class, 'retry']);
    Route::get('/integrations/hl7/messages', [Hl7Controller::class, 'index']);
    Route::post('/integrations/hl7/appointments/{id}/oru', [Hl7Controller::class, 'resendOru']);

    Route::apiResource('services', ServiceController::class);
    Route::get('roles', [UserController::class, 'getRoles']);
    Route::get('/agenda-catalogs', [AgendaCatalogController::class, 'index']);

    // MÓDULO REPORTES (ADMIN)
    Route::get('/reports/honorarios', [ReportController::class, 'getHonorarios']);
    Route::get('/reports/examenes', [ReportController::class, 'getExamenesMensuales']);
    Route::get('/reports/nomina-diaria', [ReportController::class, 'getNominaDiaria']);
    Route::get('/reports/nomina-mensual', [ReportController::class, 'getNominaMensual']);
    Route::get('/reports/consolidated-matrix', [ReportController::class, 'getConsolidatedMatrix']);

    // MÓDULO DASHBOARD (ADMIN)
    Route::get('/dashboard/metrics', [DashboardController::class, 'getMetrics']);
    Route::get('/viewer-config', [ViewerConfigController::class, 'config']);
    Route::get('/viewer-study', [ViewerConfigController::class, 'resolveStudy']);
    Route::get('/viewer-study-uid', [ViewerConfigController::class, 'resolveStudyUid']);
    Route::get('/lab-profile', LabProfileController::class);

    // MÓDULO ADMINISTRACIÓN: SECCIÓN DE PLANES Y CONVENIOS
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::delete('/plans/{id}', [PlanController::class, 'destroy']);

    // MÓDULO ADMINISTRACIÓN: SECCIÓN DE PREVISIONES
    Route::get('/insurances', [InsuranceController::class, 'index']);
    Route::post('/insurances', [InsuranceController::class, 'store']);
    Route::delete('/insurances/{id}', [InsuranceController::class, 'destroy']);

    // MÓDULO ADMINISTRACIÓN: SECCIÓN DE PLANTILLAS DE INFORMES
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::post('/templates', [TemplateController::class, 'store']);
    Route::post('/templates/import-word', [TemplateController::class, 'importFromWord']);
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy']);
});
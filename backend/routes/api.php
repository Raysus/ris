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
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'generateReceipt']);

    Route::apiResource('services', ServiceController::class);
    Route::get('roles', [UserController::class, 'getRoles']);
    Route::get('/agenda-catalogs', [AgendaCatalogController::class, 'index']);

    // MÓDULO REPORTES (ADMIN)
    Route::get('/reports/honorarios', [ReportController::class, 'getHonorarios']);
    Route::get('/reports/examenes', [ReportController::class, 'getExamenesMensuales']);
    Route::get('/reports/nomina-diaria', [ReportController::class, 'getNominaDiaria']);
    Route::get('/reports/nomina-mensual', [ReportController::class, 'getNominaMensual']);

    // MÓDULO DASHBOARD (ADMIN)
    Route::get('/dashboard/metrics', [DashboardController::class, 'getMetrics']);

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
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy']);
});
<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\OrthancStudyLookup;
use Illuminate\Http\Request;

class ViewerConfigController extends Controller
{
    public function config(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'viewer_type' => env('VIEWER_TYPE', 'ohif'),
                'viewer_url' => rtrim(env('VIEWER_URL', 'https://viewer.healthticloud.cl'), '/'),
                'viewer_path' => env('VIEWER_PATH', '/viewer'),
                'viewer_query_param' => env('VIEWER_QUERY_PARAM', 'StudyInstanceUIDs'),
                'viewer_accession_param' => env('VIEWER_ACCESSION_PARAM', ''),
                'viewer_token' => env('VIEWER_TOKEN', ''),
                'patient_portal_url' => rtrim(env('PATIENT_PORTAL_URL', 'https://portal.healthticloud.cl'), '/'),
                'pacs_bridge_url' => env('PACS_BRIDGE_URL', 'http://localhost:8181/open-dicom'),
                'pacs_ip' => env('PACS_DEFAULT_IP', '172.16.66.11'),
                'pacs_port' => (int) env('PACS_DEFAULT_PORT', 4242),
                'pacs_aet' => env('PACS_DEFAULT_AET', 'HealthTICloud'),
                'orthanc_url' => \App\Support\OrthancUrl::base(),
                'viewer_custom_url' => env('VIEWER_CUSTOM_URL', ''),
            ],
        ]);
    }

    public function resolveStudy(Request $request, OrthancStudyLookup $lookup)
    {
        $accession = trim((string) $request->query('accession', ''));
        if ($accession === '') {
            return response()->json([
                'success' => false,
                'message' => 'Falta accession o StudyInstanceUID.',
            ], 422);
        }

        $appointment = $this->resolveAppointment($request);

        $study = $lookup->resolveStudyByAccession(
            $accession,
            $appointment,
            $request->bearerToken()
        );

        if ($study === null || empty($study['study_instance_uid'])) {
            return response()->json([
                'success' => false,
                'message' => 'No hay estudio en PACS para ese accession. '
                    . 'Verifique que el equipo ya envió las imágenes y que el Accession Number coincide.',
                'orthanc_url' => \App\Support\OrthancUrl::base(),
            ], 404);
        }

        if ($appointment) {
            $lookup->persistStudyInstanceUid($appointment, (string) $study['study_instance_uid']);
        }

        return response()->json([
            'success' => true,
            'data' => $study,
        ]);
    }

    /** @deprecated Alias — usar /viewer-study */
    public function resolveStudyUid(Request $request, OrthancStudyLookup $lookup)
    {
        $response = $this->resolveStudy($request, $lookup);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        $study = $payload['data'] ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'accession' => $study['accession_requested'] ?? $request->query('accession'),
                'study_instance_uid' => $study['study_instance_uid'] ?? null,
                'orthanc_study_id' => $study['orthanc_study_id'] ?? null,
                'pacs' => $study,
            ],
        ]);
    }

    /** @deprecated Use config() — kept for Route::get single action */
    public function __invoke(Request $request)
    {
        return $this->config($request);
    }

    private function resolveAppointment(Request $request): ?Appointment
    {
        $appointmentId = trim((string) $request->query('appointment_id', ''));
        if ($appointmentId === '') {
            return null;
        }

        return Appointment::query()
            ->with('patient.persona')
            ->find($appointmentId);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ViewerConfigController extends Controller
{
    public function __invoke(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'viewer_type' => env('VIEWER_TYPE', 'ohif'),
                'viewer_url' => rtrim(env('VIEWER_URL', 'https://viewer.healthticloud.cl'), '/'),
                'viewer_accession_param' => env('VIEWER_ACCESSION_PARAM', 'AccessionNumber'),
                'viewer_token' => env('VIEWER_TOKEN', ''),
                'patient_portal_url' => rtrim(env('PATIENT_PORTAL_URL', 'https://portal.healthticloud.cl'), '/'),
                'pacs_bridge_url' => env('PACS_BRIDGE_URL', 'http://localhost:8181/open-dicom'),
                'pacs_ip' => env('PACS_DEFAULT_IP', '172.16.66.11'),
                'pacs_port' => (int) env('PACS_DEFAULT_PORT', 4242),
                'pacs_aet' => env('PACS_DEFAULT_AET', 'HealthTICloud'),
                'orthanc_url' => rtrim(env('ORTHANC_URL', 'http://127.0.0.1:8042'), '/'),
            ],
        ]);
    }
}

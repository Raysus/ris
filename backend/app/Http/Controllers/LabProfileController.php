<?php

namespace App\Http\Controllers;

use App\Models\Laboratory;
use App\Services\LaboratoryProfileService;
use Illuminate\Http\Request;

class LabProfileController extends Controller
{
    public function __invoke(Request $request)
    {
        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');

        $laboratory = $labId
            ? Laboratory::with('type')->find($labId)
            : LaboratoryProfileService::currentLaboratory();

        $profile = LaboratoryProfileService::resolve($laboratory);

        return response()->json([
            'success' => true,
            'data' => array_merge($profile, [
                'laboratory_id' => $laboratory?->id,
                'laboratory_name' => $laboratory?->name,
            ]),
        ]);
    }
}

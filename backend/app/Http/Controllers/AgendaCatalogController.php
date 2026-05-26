<?php

namespace App\Http\Controllers;

use App\Models\ReferringDoctor;
use App\Models\User;
use App\Models\Insurance;
use App\Models\Exam;
use App\Models\Supply;
use App\Models\SupplyPack;
use App\Models\Machine;
use Illuminate\Http\Request;
use App\Services\KeycloakService;
use App\Services\LaboratoryProfileService;
use Illuminate\Support\Facades\Log;

class AgendaCatalogController extends Controller
{
    protected $keycloakService;

    public function __construct(KeycloakService $keycloakService)
    {
        $this->keycloakService = $keycloakService;
    }

    public function index(Request $request)
    {
        // ... (Mismo código index que ya tienes) ...
        $allowedLabs = config('app.allowed_lab_ids');

        $refDoctorsQuery = ReferringDoctor::select('id', 'rut', 'names', 'last_name_1', 'last_name_2');

        $destDoctorsQuery = User::with('persona')
            ->whereHas('tipoUsuario', fn ($q) => $q->where('name', '!=', 'sis_admin'))
            ->whereJsonContains('settings->roles', 'radiologo')
            ->where('is_active', true);

        $insurancesQuery = Insurance::with('plans')->where('is_active', true);
        LaboratoryProfileService::filterInsurancesForProfile($insurancesQuery);
        $examsQuery = Exam::with('subExams')->where('is_active', true);
        $suppliesQuery = Supply::where('stock', '>', 0)->where('is_active', true);
        $supplyPacksQuery = SupplyPack::with('items.supply')->where('is_active', true);
        $machinesQuery = Machine::where('is_active', true);

        if ($allowedLabs !== ['*']) {
            if (empty($allowedLabs)) {
                $destDoctorsQuery->whereRaw('1 = 0');
                $insurancesQuery->whereRaw('1 = 0');
                $examsQuery->whereRaw('1 = 0');
                $suppliesQuery->whereRaw('1 = 0');
                $supplyPacksQuery->whereRaw('1 = 0');
                $machinesQuery->whereRaw('1 = 0');
            } else {
                $examsQuery->whereIn('laboratory_id', $allowedLabs);
                $suppliesQuery->whereIn('laboratory_id', $allowedLabs);
                $supplyPacksQuery->whereIn('laboratory_id', $allowedLabs);
                $machinesQuery->whereIn('laboratory_id', $allowedLabs);

                $insurancesQuery->where(function ($query) use ($allowedLabs) {
                    $query->whereNull('laboratory_id')
                        ->orWhereIn('laboratory_id', $allowedLabs);
                });
                $destDoctorsQuery->whereHas('laboratories', function ($query) use ($allowedLabs) {
                    $query->whereIn('laboratories.id', $allowedLabs);
                });
            }
        }

        $labProfile = config('app.lab_profile') ?: LaboratoryProfileService::resolve();

        return response()->json([
            'success' => true,
            'data' => [
                'referring_doctors' => $refDoctorsQuery->get(),
                'destination_doctors' => $destDoctorsQuery->get(),
                'insurances' => $insurancesQuery->get(),
                'exams' => $examsQuery->get(),
                'supplies' => $suppliesQuery->get(),
                'supply_packs' => $supplyPacksQuery->get(),
                'machines' => $machinesQuery->get(),
                'lab_profile' => $labProfile,
            ],
        ]);
    }

    public function storeReferringDoctor(Request $request)
    {
        $request->validate([
            'rut' => 'required|string',
            'names' => 'required|string|max:255',
            'last_name_1' => 'nullable|string|max:255',
            'email' => 'nullable|email'
        ]);

        $cleanRut = strtoupper(str_replace(['.', ' '], '', $request->rut));

        // 1. Guardar en Base de Datos Local
        $doctor = ReferringDoctor::create([
            'rut' => $cleanRut,
            'names' => $request->names,
            'last_name_1' => $request->last_name_1,
            'last_name_2' => $request->last_name_2 ?? null,
            'email' => $request->email
        ]);

        // 2. Crear cuenta en Keycloak para que el médico vea sus pacientes
        try {
            $this->keycloakService->createUser([
                'username' => $cleanRut,
                'nombres' => $request->names,
                'apellidos' => $request->last_name_1,
                'password' => substr($cleanRut, 0, 4), // Contraseña temporal
                'rut' => $cleanRut,
                'email' => $request->email ?? null,
            ]);
            // Opcional: Podrías asignarle el rol 'medico_derivante' en Keycloak aquí
        } catch (\Exception $e) {
            Log::warning("No se pudo crear el médico en Keycloak: " . $e->getMessage());
        }

        // 3. Sincronizar con la nube
        \App\Jobs\SyncEntityToCloud::dispatch('App\Models\ReferringDoctor', 'created', $doctor->toArray());

        return response()->json([
            'success' => true,
            'data' => $doctor
        ]);
    }
}
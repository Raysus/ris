<?php

namespace App\Http\Controllers;

use App\Models\ReferringDoctor;
use App\Models\User;
use App\Models\Insurance;
use App\Models\Exam;
use App\Models\Supply;
use App\Models\SupplyPack;
use App\Models\Machine;
use App\Models\Laboratory;
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

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
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

        $labId = config('app.current_lab_id');
        $lab = $labId ? Laboratory::find($labId) : null;
        $scheduleDefaults = [
            'horaInicio' => '08:00:00',
            'horaFin' => '20:00:00',
            'intervalo' => '00:15:00',
        ];
        $schedule = array_merge(
            $scheduleDefaults,
            is_array($lab?->settings) ? $lab->settings : []
        );

        return response()->json([
            'success' => true,
            'data' => [
                'referring_doctors' => $refDoctorsQuery->get(),
                'destination_doctors' => $destDoctorsQuery->get(),
                'insurances' => $insurancesQuery->get(),
                'exams' => $this->serializeExamsForAgenda($examsQuery->get()),
                'supplies' => $suppliesQuery->get(),
                'supply_packs' => $supplyPacksQuery->get(),
                'machines' => $machinesQuery->get(),
                'lab_profile' => $labProfile,
                'schedule' => [
                    'horaInicio' => $schedule['horaInicio'] ?? $scheduleDefaults['horaInicio'],
                    'horaFin' => $schedule['horaFin'] ?? $scheduleDefaults['horaFin'],
                    'intervalo' => $schedule['intervalo'] ?? $scheduleDefaults['intervalo'],
                ],
            ],
        ]);
    }

    /**
     * Evita duplicados en el selector de agenda (mismo nombre+grupo en un laboratorio).
     *
     * @param  \Illuminate\Support\Collection<int, Exam>  $exams
     * @return \Illuminate\Support\Collection<int, Exam>
     */
    private function dedupeExamsForAgenda($exams)
    {
        return $exams
            ->sortByDesc('created_at')
            ->unique(function (Exam $exam) {
                $lab = (string) ($exam->laboratory_id ?? '');
                $group = strtoupper(trim((string) ($exam->group_code ?? '')));
                $name = mb_strtolower(trim((string) $exam->name));

                return "{$lab}|{$group}|{$name}";
            })
            ->values();
    }

    /**
     * La columna JSON sub_exams y la relación subExams comparten clave en JSON;
     * unificamos variantes para la agenda.
     *
     * @param  \Illuminate\Support\Collection<int, Exam>  $exams
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function serializeExamsForAgenda($exams)
    {
        return $this->dedupeExamsForAgenda($exams)->map(function (Exam $exam) {
            $data = $exam->toArray();

            $relationSubs = $exam->relationLoaded('subExams')
                ? $exam->subExams->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'fonasa_code' => $s->fonasa_code,
                    'additional_price' => (int) $s->additional_price,
                ])->values()->all()
                : [];

            $jsonSubs = is_array($exam->getAttributes()['sub_exams'] ?? null)
                ? array_values(array_filter($exam->getAttributes()['sub_exams']))
                : [];

            $data['sub_exams'] = ! empty($relationSubs) ? $relationSubs : $jsonSubs;

            return $data;
        })->values();
    }

    public function storeReferringDoctor(Request $request)
    {
        $request->validate([
            'rut' => 'required|string',
            'names' => 'required|string|max:255',
            'last_name_1' => 'nullable|string|max:255',
            'email' => 'nullable|email'
        ]);

        $cleanRut = ReferringDoctor::normalizeRut($request->rut);

        // 1. Guardar en Base de Datos Local (un médico por RUT)
        $doctor = ReferringDoctor::findByRut($cleanRut);
        if ($doctor) {
            $doctor->fill([
                'names' => $request->names,
                'last_name_1' => $request->last_name_1,
                'last_name_2' => $request->last_name_2 ?? null,
                'email' => $request->email,
            ])->save();
        } else {
            $doctor = ReferringDoctor::create([
                'rut' => $cleanRut,
                'names' => $request->names,
                'last_name_1' => $request->last_name_1,
                'last_name_2' => $request->last_name_2 ?? null,
                'email' => $request->email,
            ]);
        }

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
<?php

namespace App\Http\Controllers;

use App\Models\ReferringDoctor;
use App\Services\KeycloakService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReferringDoctorController extends Controller
{
    public function __construct(private KeycloakService $keycloakService)
    {
    }

    public function index(Request $request)
    {
        $this->assertCanManage($request);

        $search = trim((string) $request->query('q', ''));

        $query = ReferringDoctor::query()
            ->select('id', 'rut', 'names', 'last_name_1', 'last_name_2', 'phone', 'email', 'created_at', 'updated_at')
            ->orderBy('last_name_1')
            ->orderBy('names');

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('names', 'ilike', $like)
                    ->orWhere('last_name_1', 'ilike', $like)
                    ->orWhere('last_name_2', 'ilike', $like)
                    ->orWhere('rut', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like);
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCanCreate($request);

        $validated = $request->validate([
            'rut' => 'required|string|max:20',
            'names' => 'required|string|max:255',
            'last_name_1' => 'nullable|string|max:255',
            'last_name_2' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ]);

        $cleanRut = ReferringDoctor::normalizeRut($validated['rut']);
        $doctor = ReferringDoctor::findByRut($cleanRut);

        if ($doctor) {
            $doctor->fill([
                'names' => $validated['names'],
                'last_name_1' => $validated['last_name_1'] ?? null,
                'last_name_2' => $validated['last_name_2'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
            ])->save();
        } else {
            $doctor = ReferringDoctor::create([
                'rut' => $cleanRut,
                'names' => $validated['names'],
                'last_name_1' => $validated['last_name_1'] ?? null,
                'last_name_2' => $validated['last_name_2'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
            ]);
        }

        $this->tryCreateKeycloakAccount($doctor, $validated);

        return response()->json(['success' => true, 'data' => $doctor]);
    }

    public function update(Request $request, string $id)
    {
        $this->assertCanManage($request);

        $doctor = ReferringDoctor::findOrFail($id);

        $validated = $request->validate([
            'rut' => 'sometimes|required|string|max:20',
            'names' => 'required|string|max:255',
            'last_name_1' => 'nullable|string|max:255',
            'last_name_2' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ]);

        if (array_key_exists('rut', $validated)) {
            $cleanRut = ReferringDoctor::normalizeRut($validated['rut']);
            $existing = ReferringDoctor::findByRut($cleanRut);
            if ($existing && $existing->id !== $doctor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe otro médico referente con ese RUT.',
                ], 422);
            }
            $doctor->rut = $cleanRut;
        }

        $doctor->fill([
            'names' => $validated['names'],
            'last_name_1' => $validated['last_name_1'] ?? null,
            'last_name_2' => $validated['last_name_2'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ])->save();

        return response()->json(['success' => true, 'data' => $doctor->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->assertCanDelete($request);

        $doctor = ReferringDoctor::findOrFail($id);

        if ($doctor->appointments()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar: el médico tiene citas asociadas en el sistema.',
            ], 422);
        }

        $doctor->delete();

        return response()->json(['success' => true]);
    }

    private function assertCanManage(Request $request): void
    {
        $user = $request->user();
        $role = strtolower((string) ($user?->tipoUsuario?->name ?? ''));

        if (!in_array($role, ['admin', 'sis_admin'], true)) {
            abort(403, 'No tiene permisos para gestionar médicos referentes.');
        }
    }

    private function assertCanCreate(Request $request): void
    {
        $user = $request->user();
        $role = strtolower((string) ($user?->tipoUsuario?->name ?? ''));

        if (!in_array($role, ['admin', 'sis_admin', 'recepcion', 'secretaria', 'secretario', 'tecnologo'], true)) {
            abort(403, 'No tiene permisos para registrar médicos referentes.');
        }
    }

    private function assertCanDelete(Request $request): void
    {
        $user = $request->user();
        $role = strtolower((string) ($user?->tipoUsuario?->name ?? ''));

        if ($role !== 'sis_admin') {
            abort(403, 'Solo Sys. Admin puede eliminar médicos referentes.');
        }
    }

    private function tryCreateKeycloakAccount(ReferringDoctor $doctor, array $validated): void
    {
        if (!filled($doctor->rut)) {
            return;
        }

        try {
            $this->keycloakService->createUser([
                'username' => $doctor->rut,
                'nombres' => $validated['names'],
                'apellidos' => $validated['last_name_1'] ?? '',
                'password' => substr((string) $doctor->rut, 0, 4),
                'rut' => $doctor->rut,
                'email' => $validated['email'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo crear el médico referente en Keycloak: ' . $e->getMessage());
        }
    }
}

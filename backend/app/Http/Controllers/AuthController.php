<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Persona;
use App\Models\Laboratory;
use App\Services\LaboratoryProfileService;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $loginField = $request->login_field;
        $isEmail = filter_var($loginField, FILTER_VALIDATE_EMAIL);

        $user = User::query()
            ->when($isEmail, function ($q) use ($loginField) {
                $q->whereHas('persona', fn ($p) => $p->where(
                    'email_hash',
                    \App\Models\Persona::hashEmail($loginField)
                ));
            }, function ($q) use ($loginField) {
                $q->where('username', $loginField);
            })
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            \App\Services\AuditLogger::record('auth.login_failed', 'User', null, [
                'login_field' => $isEmail ? '[email]' : $loginField,
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Su cuenta está desactivada.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->load('persona', 'tipoUsuario', 'laboratories');

        \App\Services\AuditLogger::record('auth.login', 'User', $user->id, [
            'username' => $user->username,
        ], $request);

        $primaryLabId = null;
        $primaryLab = null;
        $isMainLab = false;
        $labTypeId = null;
        $labTypeCode = 'clinical';
        $perfilLaboratorio = LaboratoryProfileService::profileForCode('clinical');
        $laboratoriosPermitidos = [];

        if ($user->hasFullLabAccess()) {
            $laboratoriosPermitidos = ['*'];
            $isMainLab = true;
        } else {
            foreach ($user->laboratories as $lab) {
                if ($lab->pivot->is_primary) {
                    $primaryLabId = $lab->id;
                    $isMainLab = is_null($lab->parent_id);
                    $labTypeId = $lab->laboratory_type_id;
                }
            }
            $laboratoriosPermitidos = Laboratory::resolveAllowedLabIdsForUser($user);
        }

        if ($primaryLabId) {
            $primaryLab = Laboratory::with('type')->find($primaryLabId);
            if ($primaryLab) {
                $labTypeCode = LaboratoryProfileService::codeFromLaboratory($primaryLab);
                $perfilLaboratorio = LaboratoryProfileService::resolve($primaryLab);
            }
        }

        return response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'tipo_usuario_id' => $user->tipo_usuario_id,
                'settings' => $user->settings ?? [],
                'email' => $user->persona->email ?? null,
                'names' => $user->persona->names ?? null,
                'last_name_1' => $user->persona->last_name_1 ?? null,
                'last_name_2' => $user->persona->last_name_2 ?? null,
                'role' => $user->tipoUsuario->name ?? 'unknown',
                'persona' => [
                    'names' => $user->persona->names ?? null,
                    'last_name_1' => $user->persona->last_name_1 ?? null,
                    'last_name_2' => $user->persona->last_name_2 ?? null,
                    'email' => $user->persona->email ?? null,
                ],
                'tipo_usuario' => [
                    'name' => $user->tipoUsuario->name ?? 'unknown',
                    'permissions' => is_array($user->tipoUsuario->permissions ?? null)
                        ? $user->tipoUsuario->permissions
                        : json_decode($user->tipoUsuario->permissions ?? '{}', true),
                ],
            ],
            'contexto_laboratorio' => [
                'laboratorio_id' => $primaryLabId,
                'laboratorio_nombre' => $primaryLab?->name,
                'es_matriz' => $isMainLab,
                'tipo_laboratorio_id' => $labTypeId,
                'tipo_laboratorio_code' => $labTypeCode,
                'perfil_laboratorio' => $perfilLaboratorio,
                'laboratorios_permitidos' => $laboratoriosPermitidos,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $userId = $request->user()->id;
        $request->user()->currentAccessToken()->delete();

        \App\Services\AuditLogger::record('auth.logout', 'User', $userId, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::whereHas('persona', function ($q) use ($request) {
            $q->where('email_hash', Persona::hashEmail($request->email));
        })->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Correo no registrado.'], 404);
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $token, 'created_at' => now()]
        );

        $recoveryLink = rtrim(env('FRONTEND_URL', 'http://127.0.0.1:5500'), '/')
            . '/recovery.html?token=' . $token
            . '&email=' . urlencode($request->email);

        // Mail::to($request->email)->send(new ResetPasswordMail($recoveryLink));

        return response()->json([
            'success' => true,
            'message' => 'Instrucciones enviadas.'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json(['success' => false, 'message' => 'Token inválido o expirado.'], 400);
        }

        $user = User::whereHas('persona', function ($q) use ($request) {
            $q->where('email_hash', Persona::hashEmail($request->email));
        })->first();

        if ($user) {
            $user->password = Hash::make($request->password);
            $user->save();

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            \App\Services\AuditLogger::record('auth.password_reset', 'User', $user->id, [], $request);

            return response()->json(['success' => true, 'message' => 'Contraseña actualizada.']);
        }

        return response()->json(['success' => false, 'message' => 'Usuario no encontrado.'], 404);
    }
}
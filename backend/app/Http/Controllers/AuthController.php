<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login_field' => 'required',
            'password' => 'required'
        ]);

        $isEmail = filter_var($request->login_field, FILTER_VALIDATE_EMAIL);
        $fieldType = $isEmail ? 'email' : 'username';

        if (!Auth::attempt([$fieldType => $request->login_field, 'password' => $request->password])) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Su cuenta está desactivada.'
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->load('persona', 'tipoUsuario', 'laboratories');

        $primaryLabId = null;
        $isMainLab = false;
        $labTypeId = null;
        $laboratoriosPermitidos = [];

        if ($user->tipoUsuario->name == 'sis_admin') {
            $laboratoriosPermitidos = ['*'];

            $primaryLab = $user->laboratories->firstWhere('pivot.is_primary', 1)
                ?? $user->laboratories->first()
                ?? \App\Models\Laboratory::whereNull('parent_id')->first();
        } else {
            $laboratoriosPermitidos = $user->laboratories->pluck('id')->toArray();

            $primaryLab = $user->laboratories->firstWhere('pivot.is_primary', 1)
                ?? $user->laboratories->first();
        }

        if ($primaryLab) {
            $primaryLabId = $primaryLab->id;
            $isMainLab = is_null($primaryLab->parent_id);
            $labTypeId = $primaryLab->laboratory_type_id;
        }

        if (!$primaryLabId && $user->tipoUsuario->name != 'sis_admin') {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'No tiene ninguna clínica o sucursal asignada. Contacte a soporte.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'contexto_laboratorio' => [
                'laboratorio_id' => $primaryLabId,
                'es_principal' => $isMainLab,
                'tipo_laboratorio_id' => $labTypeId,
                'laboratorios_permitidos' => $laboratoriosPermitidos
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::whereHas('persona', function ($q) use ($request) {
            $q->where('email', $request->email);
        })->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Correo no registrado.'], 404);
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $token, 'created_at' => now()]
        );

        $recoveryLink = "https://tu-ris.cl/recovery.html?token=" . $token . "&email=" . urlencode($request->email);

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
            'password' => 'required|min:6|confirmed',
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$reset) {
            return response()->json(['success' => false, 'message' => 'Token inválido o expirado.'], 400);
        }

        $user = User::whereHas('persona', function ($q) use ($request) {
            $q->where('email', $request->email);
        })->first();

        if ($user) {
            $user->password = Hash::make($request->password);
            $user->save();

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return response()->json(['success' => true, 'message' => 'Contraseña actualizada.']);
        }

        return response()->json(['success' => false, 'message' => 'Usuario no encontrado.'], 404);
    }
}
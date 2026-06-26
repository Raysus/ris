<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Appointment;
use App\Models\Laboratory;
use App\Models\Payment;
use Illuminate\Http\Request;

trait ChecksRisAuthorization
{
    protected function assertAdmin(Request $request): void
    {
        $request->user()?->loadMissing('tipoUsuario');
        $role = strtolower((string) ($request->user()?->tipoUsuario?->name ?? ''));

        if (!in_array($role, ['admin', 'sis_admin'], true)) {
            abort(403, 'No tiene permisos de administrador.');
        }
    }

    /** @return list<string> */
    protected function userEffectiveRoles(Request $request): array
    {
        $user = $request->user();
        if (!$user) {
            return [];
        }

        $user->loadMissing('tipoUsuario');
        $roles = [];

        if ($user->tipoUsuario?->name) {
            $roles[] = strtolower((string) $user->tipoUsuario->name);
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        foreach (($settings['roles'] ?? []) as $role) {
            $roles[] = strtolower((string) $role);
        }

        return array_values(array_unique($roles));
    }

    protected function assertAnyRole(Request $request, array $allowedRoles): void
    {
        $allowed = array_map('strtolower', $allowedRoles);
        $userRoles = $this->userEffectiveRoles($request);

        if (!array_intersect($userRoles, $allowed)) {
            abort(403, 'No tiene permisos para esta operación.');
        }
    }

    protected function scopedAppointmentQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

        if (!Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('laboratory_id', $allowedLabs);
            }
        }

        return $query;
    }

    protected function scopedPaymentQuery()
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Payment::query();

        if (!Laboratory::allowsAllLabs($allowedLabs)) {
            if (empty($allowedLabs)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas(
                    'appointment',
                    fn ($q) => $q->whereIn('laboratory_id', $allowedLabs)
                );
            }
        }

        return $query;
    }

    protected function scopedPaymentsForLab(?string $labId = null)
    {
        $query = $this->scopedPaymentQuery();

        if ($labId) {
            $query->whereHas('appointment', fn ($q) => $q->where('laboratory_id', $labId));
        }

        return $query;
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Laboratory;
use Illuminate\Http\Request;

trait ChecksPatientMutationAccess
{
    protected function userCanMutatePatients(?Request $request = null): bool
    {
        $user = auth()->user();
        if (!$user || !$user->tipoUsuario) {
            return false;
        }

        $profile = strtolower((string) $user->tipoUsuario->name);
        if (!in_array($profile, ['secretaria', 'secretario'], true)) {
            return true;
        }

        return $this->isRdoxOsornoLabContext($request);
    }

    protected function isRdoxOsornoLabContext(?Request $request = null): bool
    {
        $labId = $request?->header('X-Lab-Id') ?: config('app.current_lab_id');
        if (!$labId) {
            return false;
        }

        $lab = Laboratory::query()->find($labId);
        if (!$lab) {
            return false;
        }

        $name = strtoupper((string) $lab->name);

        return str_contains($name, 'RDOX') && str_contains($name, 'OSORNO');
    }
}

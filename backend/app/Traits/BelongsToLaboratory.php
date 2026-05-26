<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Models\Laboratory;

trait BelongsToLaboratory
{
    protected static function bootBelongsToLaboratory()
    {
        static::addGlobalScope('laboratory', function (Builder $builder) {

            $user = Auth::user();

            if ($user) {
                $user->loadMissing('tipoUsuario');
                if ($user->hasFullLabAccess()) {
                    return;
                }
            }

            $currentLabId = config('app.current_lab_id');
            $allowedLabIds = config('app.allowed_lab_ids');

            if ($currentLabId) {
                $currentLab = Laboratory::find($currentLabId);

                if ($currentLab && is_null($currentLab->parent_id)) {

                    $hijosIds = Laboratory::where('parent_id', $currentLabId)->pluck('id')->toArray();

                    $todosLosIds = array_merge([$currentLabId], $hijosIds);

                    $builder->whereIn('laboratory_id', $todosLosIds);

                } else {
                    $builder->where('laboratory_id', $currentLabId);
                }
            } elseif (
                is_array($allowedLabIds)
                && !Laboratory::allowsAllLabs($allowedLabIds)
                && count($allowedLabIds) > 0
            ) {
                $builder->whereIn('laboratory_id', $allowedLabIds);
            }
        });
    }
}

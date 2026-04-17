<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        // En lugar de usar 'current_lab_id', usamos 'allowed_lab_ids'
        if (config()->has('app.allowed_lab_ids')) {
            $builder->whereIn(
                $model->getTable() . '.laboratory_id',
                config('app.allowed_lab_ids')
            );
        }
    }
}
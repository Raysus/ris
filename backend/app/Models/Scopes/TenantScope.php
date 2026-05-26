<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $allowedLabIds = config('app.allowed_lab_ids');

        if (!is_array($allowedLabIds) || $allowedLabIds === ['*'] || $allowedLabIds === []) {
            return;
        }

        $builder->whereIn($model->getTable() . '.laboratory_id', $allowedLabIds);
    }
}
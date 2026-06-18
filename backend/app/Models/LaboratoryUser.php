<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class LaboratoryUser extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'laboratory_user';

    protected $fillable = [
        'laboratory_id',
        'user_id',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];
}

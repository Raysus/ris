<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    protected $fillable = [
        'laboratory_id',
        'name',
        'group',
        'manufacturer',
        'model_name',
        'description',
        'event_color',
        'is_active'
    ];
}
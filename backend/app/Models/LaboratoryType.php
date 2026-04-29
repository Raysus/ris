<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // 1. Importar esto

class LaboratoryType extends Model
{
    use HasUuids; // 2. Usar el trait

    protected $guarded = [];
    protected $keyType = 'string';
    public $incrementing = false;
}
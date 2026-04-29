<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Laboratory extends Model
{
    use SoftDeletes, HasUuids;

    protected $fillable = [
        'laboratory_type_id',
        'parent_id',
        'rut',
        'name',
        'address',
        'city',
        'phone',
        'email',
        'settings',
        'is_active'
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    // Relación: Qué tipo de laboratorio es (Clínico, Dental...)
    public function type()
    {
        return $this->belongsTo(LaboratoryType::class, 'laboratory_type_id');
    }

    // Relación hacia ARRIBA: ¿Quién es su Casa Matriz?
    public function parent()
    {
        return $this->belongsTo(Laboratory::class, 'parent_id');
    }

    // Relación hacia ABAJO: ¿Cuáles son sus Sucursales?
    public function children()
    {
        return $this->hasMany(Laboratory::class, 'parent_id');
    }

    // Relación: Los pacientes de este laboratorio
    public function patients()
    {
        return $this->hasMany(Patient::class);
    }
}
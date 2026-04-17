<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Persona extends Model
{
    // Estos son los datos biográficos universales (El carnet de identidad)
    protected $fillable = [
        'rut',
        'names',
        'last_name_1',
        'last_name_2',
        'gender',
        'birth_date',
        'email',
        'phone',
        'address',
        'has_sso_account'
    ];

    // Relación: Una persona puede ser "Paciente" en muchos laboratorios
    public function patients()
    {
        return $this->hasMany(Paciente::class);
    }

    // Relación: Una persona puede ser un "Usuario/Funcionario" del sistema
    public function user()
    {
        return $this->hasOne(User::class);
    }
    protected $casts = [
        'has_sso_account' => 'boolean',
    ];
}
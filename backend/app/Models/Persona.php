<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Persona extends Model
{
    use HasUuids;
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

    public function patients()
    {
        return $this->hasMany(Paciente::class);
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }
    
    protected $casts = [
        'has_sso_account' => 'boolean',
        'rut' => 'encrypted',
        'email' => 'encrypted',
        'phone' => 'encrypted',
    ];
}
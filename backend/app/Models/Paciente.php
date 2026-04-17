<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;

class Paciente extends Model
{
    use BelongsToLaboratory;
    protected $table = 'patients';
    protected $fillable = ['persona_id', 'laboratory_id'];

    // Relación: Un paciente clínico es la representación de una Persona física
    public function persona()
    {
        return $this->belongsTo(Persona::class);
    }

    // Relación: Un paciente tiene muchas citas médicas
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
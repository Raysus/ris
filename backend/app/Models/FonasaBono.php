<?php

namespace App\Models;

use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FonasaBono extends Model
{
    use BelongsToLaboratory, HasUuids;

    protected $fillable = [
        'appointment_id',
        'laboratory_id',
        'folio',
        'tipo',
        'estado',
        'rut_beneficiario',
        'prestacion_codigo',
        'monto_bonificacion',
        'monto_copago',
        'monto_total',
        'validation_response',
        'validated_at',
        'registered_by',
    ];

    protected $casts = [
        'validation_response' => 'array',
        'validated_at' => 'datetime',
        'monto_bonificacion' => 'decimal:2',
        'monto_copago' => 'decimal:2',
        'monto_total' => 'decimal:2',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function registrar()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}

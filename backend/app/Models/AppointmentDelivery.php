<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // Magia UUID

class AppointmentDelivery extends Model
{
    use HasUuids;

    // Le decimos explícitamente el nombre de la tabla
    protected $table = 'appointment_deliveries';
    
    // Configuración de UUID
    protected $keyType = 'string';
    public $incrementing = false;

    // Los campos que permitimos guardar masivamente
    protected $fillable = [
        'appointment_id',
        'delivered_by',
        'receiver_rut',
        'receiver_name',
        'relationship',
        'delivery_method',
    ];

    // --- Relaciones ---
    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
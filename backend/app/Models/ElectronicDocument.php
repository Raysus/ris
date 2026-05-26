<?php

namespace App\Models;

use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ElectronicDocument extends Model
{
    use BelongsToLaboratory, HasUuids;

    protected $fillable = [
        'appointment_id',
        'laboratory_id',
        'document_type',
        'folio',
        'status',
        'receptor_rut',
        'receptor_name',
        'monto_neto',
        'monto_iva',
        'monto_exento',
        'monto_total',
        'provider_reference',
        'payload',
        'provider_response',
        'pdf_path',
        'emitted_at',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'provider_response' => 'array',
        'emitted_at' => 'datetime',
        'monto_neto' => 'decimal:2',
        'monto_iva' => 'decimal:2',
        'monto_exento' => 'decimal:2',
        'monto_total' => 'decimal:2',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}

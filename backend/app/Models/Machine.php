<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Machine extends Model
{
    use HasUuids;
    protected $fillable = [
        'laboratory_id',
        'name',
        'group',
        'manufacturer',
        'model_name',
        'description',
        'event_color',
        'is_active',
        // Datos DICOM del equipo. Sin estos, la asignación masiva los descartaba
        // en silencio y AE Title / IP / Puerto nunca se guardaban.
        'ae_title',
        'ip_address',
        'port',
    ];
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasUuids;

    protected $fillable = [
        'persona_id',
        'tipo_usuario_id',
        'username',
        'password',
        'settings',
        'is_active',
        'medical_title',
        'pacs_ae',
        'dragon_profile'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'settings' => 'array',
        'is_active' => 'boolean',
        'pacs_ae' => 'encrypted',
        'dragon_profile' => 'encrypted',
    ];

    // Relación: Este usuario le pertenece a una Persona física
    public function persona()
    {
        return $this->belongsTo(Persona::class);
    }

    // Relación: Este usuario tiene un Perfil (Tipo)
    public function tipoUsuario()
    {
        return $this->belongsTo(TipoUsuario::class);
    }

    // Relación: Un usuario puede trabajar en varios Laboratorios (N a N)
    public function laboratories()
    {
        return $this->belongsToMany(Laboratory::class)
            ->using(LaboratoryUser::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    // Relación: Informes que este usuario ha dictado (Radiólogo)
    public function reportsDictated()
    {
        return $this->hasMany(MedicalReport::class, 'radiologist_id');
    }

    // Relación: Informes que este usuario ha transcrito (Transcriptor/TM)
    public function reportsTranscribed()
    {
        return $this->hasMany(MedicalReport::class, 'transcriptionist_id');
    }

    /** Perfil con acceso a todos los laboratorios del sistema (sin asignación por sede). */
    public const SYSTEM_WIDE_ROLE_NAMES = ['sis_admin'];

    public function hasFullLabAccess(): bool
    {
        $this->loadMissing('tipoUsuario');

        if (in_array($this->tipoUsuario?->name, self::SYSTEM_WIDE_ROLE_NAMES, true)) {
            return true;
        }

        $roles = is_array($this->settings) ? ($this->settings['roles'] ?? []) : [];

        return in_array('sis_admin', array_map('strval', $roles), true);
    }

    public function canCreateLaboratoryMatrix(): bool
    {
        return $this->hasFullLabAccess();
    }
}
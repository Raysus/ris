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
        return $this->hasMany(Paciente::class);
    }

    /** Perfiles que pueden usar vista «Todas mis sucursales» (solo sedes permitidas). */
    public const MULTI_SITE_OPERATIONAL_ROLES = [
        'radiologo',
        'tecnologo',
        'recepcion',
        'transcriptor',
    ];

    public static function supportsMultiSiteView(?string $roleName): bool
    {
        return $roleName === 'admin'
            || in_array($roleName, self::MULTI_SITE_OPERATIONAL_ROLES, true);
    }

    /**
     * Si el usuario tiene asignada la matriz, incluye sus sucursales hijas.
     *
     * @param list<string> $assignedIds
     * @return list<string>
     */
    public static function expandMatrixChildrenForAssigned(array $assignedIds): array
    {
        $matrices = static::whereIn('id', $assignedIds)->whereNull('parent_id')->pluck('id')->toArray();

        if ($matrices === []) {
            return array_values(array_unique($assignedIds));
        }

        $children = static::whereIn('parent_id', $matrices)->pluck('id')->toArray();

        return array_values(array_unique(array_merge($assignedIds, $children)));
    }

    /**
     * IDs de laboratorios que el usuario puede ver.
     * Admin: matriz + todas sus sucursales. Operativos: asignados (+ hijas si tiene la matriz).
     *
     * @return list<string>|array{0: '*'}
     */
    public static function resolveAllowedLabIdsForUser(User $user): array
    {
        $user->loadMissing('tipoUsuario');
        $roleName = $user->tipoUsuario?->name;

        if ($roleName === 'sis_admin') {
            return ['*'];
        }

        $assignedIds = $user->laboratories()->pluck('laboratories.id')->toArray();

        if ($roleName === 'admin') {
            $matrices = static::whereIn('id', $assignedIds)->whereNull('parent_id')->pluck('id')->toArray();
            $padres = static::whereIn('id', $assignedIds)->whereNotNull('parent_id')->pluck('parent_id')->toArray();
            $todasLasMatrices = array_unique(array_merge($matrices, $padres));
            $sucursales = static::whereIn('parent_id', $todasLasMatrices)->pluck('id')->toArray();

            return array_values(array_unique(array_merge($todasLasMatrices, $sucursales)));
        }

        if (in_array($roleName, self::MULTI_SITE_OPERATIONAL_ROLES, true)) {
            return static::expandMatrixChildrenForAssigned($assignedIds);
        }

        return array_values(array_unique($assignedIds));
    }
}
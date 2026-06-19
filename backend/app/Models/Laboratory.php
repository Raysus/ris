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

    /** Visión global: allowed_lab_ids contiene el comodín «*» (no usar !== ['*'] en PHP). */
    public static function allowsAllLabs(?array $allowedLabIds = null): bool
    {
        $ids = $allowedLabIds ?? config('app.allowed_lab_ids');

        return is_array($ids) && in_array('*', $ids, true);
    }

    /**
     * Restringe un query Eloquent a los laboratorios permitidos en el tenant actual.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function scopeQueryToAllowedLabs($query, string $column = 'laboratory_id')
    {
        $allowedLabs = config('app.allowed_lab_ids');

        if (static::allowsAllLabs($allowedLabs)) {
            return $query;
        }

        if (empty($allowedLabs)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $allowedLabs);
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
    /**
     * Horario de agenda: valores propios o, en sucursales, heredados de la matriz.
     *
     * @return array{horaInicio: string, horaFin: string, intervalo: string}
     */
    public function resolveScheduleSettings(): array
    {
        $defaults = [
            'horaInicio' => '08:00:00',
            'horaFin' => '20:00:00',
            'intervalo' => '00:15:00',
        ];

        $keys = ['horaInicio', 'horaFin', 'intervalo'];
        $own = is_array($this->settings) ? $this->settings : [];
        $parent = [];

        if ($this->parent_id) {
            $this->loadMissing('parent');
            $parent = is_array($this->parent?->settings) ? $this->parent->settings : [];
        }

        $resolved = $defaults;
        foreach ($keys as $key) {
            $ownVal = $own[$key] ?? null;
            $parentVal = $parent[$key] ?? null;
            if (is_string($ownVal) && $ownVal !== '') {
                $resolved[$key] = $ownVal;
            } elseif (is_string($parentVal) && $parentVal !== '') {
                $resolved[$key] = $parentVal;
            }
        }

        return $resolved;
    }

    public static function resolveAllowedLabIdsForUser(User $user): array
    {
        $user->loadMissing('tipoUsuario');

        if ($user->hasFullLabAccess()) {
            return ['*'];
        }

        $roleName = $user->tipoUsuario?->name;
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
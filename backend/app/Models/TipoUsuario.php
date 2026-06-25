<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TipoUsuario extends Model
{
    use HasUuids;
    protected $table = 'tipo_usuarios';
    public $timestamps = false;

    protected $fillable = ['name', 'description'];

    /** Roles que no se ofrecen al crear/editar usuarios en Admin. */
    public const HIDDEN_FROM_ASSIGNMENT = ['sis_admin', 'auxiliar', 'secretaria'];

    public static function assignableQuery()
    {
        return static::query()->whereNotIn('name', self::HIDDEN_FROM_ASSIGNMENT);
    }
}
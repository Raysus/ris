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
}
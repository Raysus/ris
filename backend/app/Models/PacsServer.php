<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;

class PacsServer extends Model
{
    use BelongsToLaboratory;
    protected $fillable = ['laboratory_id', 'name', 'ae_title', 'ip_address', 'port', 'is_active'];
}
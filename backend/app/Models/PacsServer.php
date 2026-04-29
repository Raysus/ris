<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PacsServer extends Model
{
    use BelongsToLaboratory, HasUuids;
    protected $fillable = ['laboratory_id', 'name', 'ae_title', 'ip_address', 'port', 'is_active'];
}
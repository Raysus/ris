<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Service extends Model
{
    use BelongsToLaboratory, HasUuids;
    protected $fillable = ['laboratory_id', 'name', 'description', 'is_active'];
}
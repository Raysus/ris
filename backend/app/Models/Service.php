<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToLaboratory;

class Service extends Model
{
    use BelongsToLaboratory;
    protected $fillable = ['laboratory_id', 'name', 'description', 'is_active'];
}
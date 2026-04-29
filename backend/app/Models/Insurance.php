<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Insurance extends Model
{
    use HasUuids;
    protected $fillable = ['laboratory_id', 'code', 'name', 'type', 'is_active'];
    public function plans()
    {
        return $this->hasMany(InsurancePlan::class);
    }
}
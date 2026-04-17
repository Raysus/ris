<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Insurance extends Model
{
    protected $fillable = ['laboratory_id','code', 'name', 'type', 'is_active'];
    public function plans()
    {
        return $this->hasMany(InsurancePlan::class);
    }
}
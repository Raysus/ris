<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InsurancePlan extends Model
{
    use HasUuids;
    protected $fillable = ['laboratory_id', 'insurance_id', 'name', 'percentage'];
    public function insurance()
    {
        return $this->belongsTo(Insurance::class);
    }
    public function tariffs()
    {
        return $this->hasMany(Tariff::class);
    }
}
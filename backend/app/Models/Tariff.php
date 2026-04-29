<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Tariff extends Model
{
    use HasUuids;
    protected $fillable = ['exam_id', 'insurance_plan_id', 'price', 'copay'];
    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
    public function plan()
    {
        return $this->belongsTo(InsurancePlan::class, 'insurance_plan_id');
    }
}
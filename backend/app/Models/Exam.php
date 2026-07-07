<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToLaboratory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Exam extends Model
{
    use SoftDeletes, BelongsToLaboratory, HasUuids;

    protected $fillable = [
        'laboratory_id',
        'group_code',
        'name',
        'sub_exams',
        'fonasa_code',
        'price',
        'fonasa_price',
        'estimated_duration',
        'is_active'
    ];

    protected $casts = [
        'sub_exams' => 'array',
        'is_active' => 'boolean',
    ];

    public function tariffs()
    {
        return $this->hasMany(Tariff::class);
    }

    public function subExams()
    {
        return $this->hasMany(SubExam::class);
    }

    public function instruction()
    {
        return $this->hasOne(ExamInstruction::class);
    }
}
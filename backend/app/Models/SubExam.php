<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SubExam extends Model
{
    use HasUuids;

    protected $fillable = [
        'exam_id',
        'name',
        'fonasa_code',
        'additional_price',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}

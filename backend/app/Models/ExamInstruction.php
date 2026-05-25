<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ExamInstruction extends Model
{
    use HasUuids;

    protected $fillable = [
        'exam_id',
        'subject',
        'body',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}

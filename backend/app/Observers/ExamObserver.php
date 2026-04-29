<?php

namespace App\Observers;

use App\Models\Exam;
use App\Jobs\SyncEntityToCloud;

class ExamObserver
{
    public function created(Exam $exam)
    {
        SyncEntityToCloud::dispatch('Exam', $exam->toArray(), 'created');
    }

    public function updated(Exam $exam)
    {
        SyncEntityToCloud::dispatch('Exam', $exam->toArray(), 'updated');
    }

    public function deleted(Exam $exam)
    {
        SyncEntityToCloud::dispatch('Exam', ['id' => $exam->id], 'deleted');
    }
}
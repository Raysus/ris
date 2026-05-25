<?php

namespace App\Observers;

use App\Models\Exam;
use App\Jobs\SyncEntityToCloud;

class ExamObserver
{
    public function created(Exam $exam)
    {
        SyncEntityToCloud::dispatch('Exam', 'created', $exam->toArray());
    }

    public function updated(Exam $exam)
    {
        SyncEntityToCloud::dispatch('Exam', 'updated', $exam->toArray());
    }

    public function deleted(Exam $exam)
    {
        SyncEntityToCloud::dispatch('Exam', 'deleted', ['id' => $exam->id]);
    }
}

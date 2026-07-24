<?php

namespace App\Observers;

use App\Models\Exam;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class ExamObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Exam $exam): void
    {
        $this->dispatchBidirectionalSync('Exam', 'created', $exam);
    }

    public function updated(Exam $exam): void
    {
        $this->dispatchBidirectionalSync('Exam', 'updated', $exam);
    }

    public function deleted(Exam $exam): void
    {
        $this->dispatchBidirectionalSync('Exam', 'deleted', $exam);
    }
}

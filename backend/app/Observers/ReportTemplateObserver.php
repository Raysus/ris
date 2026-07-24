<?php

namespace App\Observers;

use App\Models\ReportTemplate;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class ReportTemplateObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(ReportTemplate $template): void
    {
        $this->dispatchBidirectionalSync('ReportTemplate', 'created', $template);
    }

    public function updated(ReportTemplate $template): void
    {
        $this->dispatchBidirectionalSync('ReportTemplate', 'updated', $template);
    }

    public function deleted(ReportTemplate $template): void
    {
        $this->dispatchBidirectionalSync('ReportTemplate', 'deleted', $template);
    }
}

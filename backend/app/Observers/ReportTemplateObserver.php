<?php

namespace App\Observers;

use App\Models\ReportTemplate;
use App\Jobs\SyncEntityToCloud;

class ReportTemplateObserver
{
    public function created(ReportTemplate $reportTemplate)
    {
        SyncEntityToCloud::dispatch('ReportTemplate', 'created', $reportTemplate->toArray());
    }

    public function updated(ReportTemplate $reportTemplate)
    {
        SyncEntityToCloud::dispatch('ReportTemplate', 'updated', $reportTemplate->toArray());
    }

    public function deleted(ReportTemplate $reportTemplate)
    {
        SyncEntityToCloud::dispatch('ReportTemplate', 'deleted', ['id' => $reportTemplate->id]);
    }
}

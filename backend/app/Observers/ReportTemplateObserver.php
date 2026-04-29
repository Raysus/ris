<?php

namespace App\Observers;

use App\Models\ReportTemplate;
use App\Jobs\SyncEntityToCloud;

class ReportTemplateObserver
{
    public function created(ReportTemplate $reportTemplate)
    {
        SyncEntityToCloud::dispatch('ReportTemplate', $reportTemplate->toArray(), 'created');
    }

    public function updated(ReportTemplate $reportTemplate)
    {
        SyncEntityToCloud::dispatch('ReportTemplate', $reportTemplate->toArray(), 'updated');
    }

    public function deleted(ReportTemplate $reportTemplate)
    {
        SyncEntityToCloud::dispatch('ReportTemplate', ['id' => $reportTemplate->id], 'deleted');
    }
}
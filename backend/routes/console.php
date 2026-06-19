<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ris:backup')
    ->dailyAt('02:30')
    ->environments(['production'])
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup.log'));

Schedule::command('ris:send-appointment-reminders')
    ->dailyAt('18:00')
    ->environments(['production'])
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/reminders.log'));

Schedule::command('ris:sync-orthanc-status')
    ->everyFiveMinutes()
    ->environments(['production'])
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/orthanc-sync.log'));

Schedule::command('ris:flush-cloud-sync-pending')
    ->everyTwoMinutes()
    ->environments(['production'])
    ->when(fn () => \App\Support\CloudSyncMode::isLocal() && \App\Support\CloudSyncMode::canPushToCloud())
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cloud-sync-pending.log'));

<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Observers\AppointmentObserver;
use App\Models\Appointment;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Appointment::observe(AppointmentObserver::class);
    }
}

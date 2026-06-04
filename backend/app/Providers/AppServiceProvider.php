<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;
use App\Models\ReferringDoctor;
use App\Models\Persona;
use App\Models\Paciente;
use App\Models\Appointment;
use App\Models\Exam;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Machine;
use App\Models\Service;
use App\Models\Laboratory;
use App\Models\Supply;
use App\Models\ReportTemplate;
use App\Models\User;

use App\Observers\ReferringDoctorObserver;
use App\Observers\PersonaObserver;
use App\Observers\PacienteObserver;
use App\Observers\AppointmentObserver;
use App\Observers\ExamObserver;
use App\Observers\InsuranceObserver;
use App\Observers\InsurancePlanObserver;
use App\Observers\MachineObserver;
use App\Observers\ServiceObserver;
use App\Observers\LaboratoryObserver;
use App\Observers\SupplyObserver;
use App\Observers\ReportTemplateObserver;
use App\Observers\UserObserver;

use App\Models\AppointmentLog;
use App\Observers\AppointmentLogObserver;

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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Appointment::observe(AppointmentObserver::class);
        ReferringDoctor::observe(ReferringDoctorObserver::class);
        Persona::observe(PersonaObserver::class);
        Paciente::observe(PacienteObserver::class);
        Exam::observe(ExamObserver::class);
        Insurance::observe(InsuranceObserver::class);
        InsurancePlan::observe(InsurancePlanObserver::class);
        Machine::observe(MachineObserver::class);
        Service::observe(ServiceObserver::class);
        Laboratory::observe(LaboratoryObserver::class);
        Supply::observe(SupplyObserver::class);
        ReportTemplate::observe(ReportTemplateObserver::class);
        User::observe(UserObserver::class);
        AppointmentLog::observe(AppointmentLogObserver::class);
    }
}

<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Services\WorklistService;

class AppointmentObserver
{
    public function saved(Appointment $appointment)
    {
        // Si la cita está programada, generamos la Worklist
        if ($appointment->status === 'scheduled' || $appointment->status === 'programada') {
            $service = new WorklistService();
            $service->generateWL($appointment);
        }
    }

    public function deleted(Appointment $appointment)
    {
        // Si cancelan la cita, borramos el archivo para que desaparezca de la máquina
        $wlPath = storage_path("app/worklists/{$appointment->id}.wl");
        if (file_exists($wlPath)) {
            unlink($wlPath);
        }
    }
}
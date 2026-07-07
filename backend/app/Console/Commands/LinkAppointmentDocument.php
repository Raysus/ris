<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class LinkAppointmentDocument extends Command
{
    protected $signature = 'ris:link-appointment-document
        {appointment : UUID de la cita}
        {path : Ruta local /storage/documents/... o ruta relativa documents/...}
        {--type=order : order o survey}';

    protected $description = 'Vincula un PDF/imagen huérfano importado a una cita (orden médica o encuesta)';

    public function handle(): int
    {
        $appointmentId = (string) $this->argument('appointment');
        $path = trim((string) $this->argument('path'));
        $type = strtolower((string) $this->option('type'));

        if (!in_array($type, ['order', 'survey'], true)) {
            $this->error('Tipo inválido. Use --type=order o --type=survey');

            return self::FAILURE;
        }

        $normalized = ltrim($path, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/'));
        }

        if (!Storage::disk('public')->exists($normalized)) {
            $this->error("Archivo no encontrado en storage público: {$normalized}");

            return self::FAILURE;
        }

        $appointment = Appointment::find($appointmentId);
        if (!$appointment) {
            $this->error("Cita no encontrada: {$appointmentId}");

            return self::FAILURE;
        }

        $publicPath = '/storage/' . $normalized;
        if ($type === 'survey') {
            $appointment->survey_path = $publicPath;
        } else {
            $appointment->medical_order_path = $publicPath;
        }
        $appointment->save();

        $this->info("Vinculado {$publicPath} a cita {$appointmentId} (" . ($type === 'survey' ? 'encuesta' : 'orden') . ').');

        return self::SUCCESS;
    }
}

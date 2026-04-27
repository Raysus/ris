<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Facades\Log;

class WorklistService
{
    public function generateWL(Appointment $appointment)
    {
        try {
            // 1. Leer el template
            $templatePath = storage_path('app/templates/template.dump');
            if (!file_exists($templatePath)) {
                throw new \Exception("Template DICOM no encontrado.");
            }
            $template = file_get_contents($templatePath);

            // 2. Extraer datos (Asegúrate de que las relaciones existan)
            $patient = $appointment->patient->persona;
            $nombreDicom = strtoupper($patient->last_name_1 . '^' . $patient->names);
            $rutDicom = str_replace(['.', '-'], '', $patient->rut);

            // Reemplazar variables
            $search = [
                '[#AccessionNumber#]',
                '[#PatientName#]',
                '[#PatientID#]',
                '[#PatientBirthDate#]',
                '[#PatientSex#]',
                '[#StudyInstanceUID#]',
                '[#RequestedProcedureDescription#]',
                '[#Modality#]',
                '[#StationAE#]',
                '[#Date#]',
                '[#Time#]'
            ];

            $replace = [
                '[' . $appointment->accession_number . ']',
                '[' . $nombreDicom . ']',
                '[' . $rutDicom . ']',
                '[' . ($patient->birth_date ? \Carbon\Carbon::parse($patient->birth_date)->format('Ymd') : '') . ']',
                '[' . ($patient->gender ?? 'O') . ']',
                '[' . ($appointment->study_instance_uid ?? '1.2.3.4.5.' . time()) . ']',
                '[' . strtoupper($appointment->exam_name ?? 'ESTUDIO') . ']',
                '[' . ($appointment->modality ?? 'DX') . ']', // DX (Rayos), CT (Scanner), etc.
                '[ORTHANC]', // AE Title (debe coincidir con la config del equipo)
                '[' . \Carbon\Carbon::parse($appointment->start_time)->format('Ymd') . ']',
                '[' . \Carbon\Carbon::parse($appointment->start_time)->format('His') . ']'
            ];

            $dumpContent = str_replace($search, $replace, $template);

            // 3. Rutas de archivos temporales y finales
            $tempDumpPath = storage_path("app/worklists/{$appointment->id}.dump");
            // Nota: Aquí guardamos en la carpeta compartida que mapeamos a Orthanc
            $finalWlPath = storage_path("app/worklists/{$appointment->id}.wl");

            file_put_contents($tempDumpPath, $dumpContent);

            // 4. Ejecutar el comando del sistema operativo para compilar el binario DICOM
            $output = [];
            $returnVar = 0;
            exec("dump2dcm {$tempDumpPath} {$finalWlPath} 2>&1", $output, $returnVar);

            // 5. Limpiar archivo temporal
            if (file_exists($tempDumpPath)) {
                unlink($tempDumpPath);
            }

            if ($returnVar !== 0) {
                Log::error("Error al generar DICOM Worklist", ['output' => $output]);
                return false;
            }

            Log::info("Worklist generada con éxito para cita ID: {$appointment->id}");
            return true;

        } catch (\Exception $e) {
            Log::error("Excepción en WorklistService: " . $e->getMessage());
            return false;
        }
    }
}
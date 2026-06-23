<?php

$backend = is_file(__DIR__ . '/../vendor/autoload.php')
    ? dirname(__DIR__)
    : getcwd();

require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Appointment;
use App\Models\Paciente;
use App\Models\Persona;
use App\Services\LocalMwlFileWriter;
use App\Support\OrthancUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$terms = array_slice($argv, 1);
if ($terms === []) {
    $terms = ['alan', 'raul'];
}
$terms = array_map('strtolower', $terms);

$personas = Persona::query()->get()->filter(function (Persona $persona) use ($terms): bool {
    $blob = strtolower(trim(implode(' ', array_filter([
        (string) $persona->names,
        (string) $persona->last_name_1,
        (string) $persona->last_name_2,
    ]))));

    foreach ($terms as $term) {
        if ($term !== '' && str_contains($blob, $term)) {
            return true;
        }
    }

    return false;
});

if ($personas->isEmpty()) {
    echo "No se encontraron personas para: " . implode(', ', $terms) . PHP_EOL;
    exit(0);
}

foreach ($personas as $persona) {
    echo 'Persona: ' . trim("{$persona->names} {$persona->last_name_1} {$persona->last_name_2}") . " ({$persona->id})" . PHP_EOL;
}

$patientIds = Paciente::query()->whereIn('persona_id', $personas->pluck('id'))->pluck('id');
$appointments = Appointment::withTrashed()->whereIn('patient_id', $patientIds)->get();

if ($appointments->isEmpty()) {
    echo 'Sin citas para borrar.' . PHP_EOL;
} else {
    $accessions = [];
    DB::transaction(function () use ($appointments, &$accessions): void {
        foreach ($appointments as $appointment) {
            $accessions[] = (string) $appointment->accession_number;
            echo 'Borrando cita ' . $appointment->id . ' accession=' . $appointment->accession_number . PHP_EOL;

            $studyIds = DB::table('appointment_studies')->where('appointment_id', $appointment->id)->pluck('id');
            if ($studyIds->isNotEmpty()) {
                DB::table('medical_reports')->whereIn('appointment_study_id', $studyIds)->delete();
                DB::table('appointment_studies')->whereIn('id', $studyIds)->delete();
            }

            DB::table('appointment_supplies')->where('appointment_id', $appointment->id)->delete();
            DB::table('appointment_logs')->where('appointment_id', $appointment->id)->delete();
            DB::table('appointment_deliveries')->where('appointment_id', $appointment->id)->delete();
            DB::table('payments')->where('appointment_id', $appointment->id)->delete();
            DB::table('fonasa_bonos')->where('appointment_id', $appointment->id)->delete();
            DB::table('electronic_documents')->where('appointment_id', $appointment->id)->delete();
            DB::table('hl7_messages')->where('appointment_id', $appointment->id)->delete();

            $appointment->forceDelete();
        }
    });

    $accessions = array_values(array_unique(array_filter($accessions)));
    purgeWorklists($accessions);
}

$remainingPatients = Paciente::query()->whereIn('persona_id', $personas->pluck('id'))->get();
foreach ($remainingPatients as $patient) {
    if (Appointment::withTrashed()->where('patient_id', $patient->id)->exists()) {
        continue;
    }
    echo 'Borrando paciente ' . $patient->id . PHP_EOL;
    $patient->delete();
}

echo 'Listo.' . PHP_EOL;

function purgeWorklists(array $accessions): void
{
    if ($accessions === []) {
        return;
    }

    if (OrthancUrl::usesLocalWorklist() && OrthancUrl::orthancUsesFiles()) {
        $dir = app(LocalMwlFileWriter::class)->storageAreaDirectory();
        foreach (glob($dir . '/*.wl') ?: [] as $file) {
            foreach ($accessions as $accession) {
                $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $accession) ?: 'worklist';
                if (str_contains(basename($file), $safe)) {
                    unlink($file);
                    echo 'MWL local borrada: ' . basename($file) . PHP_EOL;
                }
            }
        }
    }

    purgeOrthancWorklistsByAccession(OrthancUrl::usesLocalWorklist() ? OrthancUrl::worklistBase() : OrthancUrl::base(), $accessions);

    if (OrthancUrl::usesLocalWorklist() && rtrim(OrthancUrl::base(), '/') !== rtrim(OrthancUrl::worklistBase(), '/')) {
        purgeOrthancWorklistsByAccession(OrthancUrl::base(), $accessions);
    }
}

function purgeOrthancWorklistsByAccession(string $base, array $accessions): void
{
    try {
        $response = Http::timeout(15)->acceptJson()->get(rtrim($base, '/') . '/worklists');
        if (!$response->successful()) {
            echo 'No se pudo listar worklists en ' . $base . PHP_EOL;

            return;
        }

        foreach ($response->json() ?? [] as $item) {
            $itemAccession = (string) ($item['Tags']['AccessionNumber'] ?? '');
            foreach ($accessions as $accession) {
                if (accessionMatches($itemAccession, $accession)) {
                    Http::timeout(10)->acceptJson()->delete(rtrim($base, '/') . '/worklists/' . $item['ID']);
                    echo 'Worklist Orthanc borrada (' . $base . '): ' . $itemAccession . PHP_EOL;
                }
            }
        }
    } catch (Throwable $e) {
        echo 'Aviso worklist Orthanc (' . $base . '): ' . $e->getMessage() . PHP_EOL;
    }
}

function accessionMatches(string $existing, string $needle): bool
{
    $a = strtoupper(preg_replace('/[^A-Z0-9]/', '', $existing));
    $b = strtoupper(preg_replace('/[^A-Z0-9]/', '', $needle));

    return $a !== '' && ($a === $b || str_starts_with($a, $b) || str_starts_with($b, $a));
}

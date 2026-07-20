<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = '019f4c57-f6dd-7166-8789-9708c78a5dbb';
$row = DB::connection('ris_db')->table('appointment_studies')
  ->join('appointments', 'appointments.id', '=', 'appointment_studies.appointment_id')
  ->where('appointment_studies.id', $id)
  ->select(
    'appointment_studies.id',
    'appointment_studies.report',
    'appointment_studies.report_document_path',
    'appointments.accession_number',
    'appointments.study_instance_uid',
    'appointments.status',
    'appointments.patient_id'
  )->first();
echo json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;

$doc = $row->report_document_path ?? null;
if ($doc) {
  $rel = ltrim(preg_replace('#^storage/#', '', $doc), '/');
  $url = rtrim(env('RIS_PUBLIC_URL', 'https://api.healthticloud.cl'), '/') . '/storage/' . $rel;
  echo "PDF_URL=$url\n";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  curl_exec($ch);
  echo 'PDF_HTTP=' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
  curl_close($ch);
}

// Simular lógica showReport: ¿redirigiría?
$docPath = trim((string) ($row->report_document_path ?? ''));
$reportText = trim((string) ($row->report ?? ''));
$isPlaceholder = $reportText === '' || str_starts_with(mb_strtolower($reportText), 'informe adjunto');
echo "WOULD_REDIRECT=" . (($docPath !== '' && $isPlaceholder) ? 'yes' : 'no') . "\n";
echo "REPORT_TEXT=" . substr($reportText, 0, 80) . "\n";

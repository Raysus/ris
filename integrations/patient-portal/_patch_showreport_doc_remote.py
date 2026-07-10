#!/usr/bin/env python3
"""Parche en el servidor portal: showReport abre PDF adjunto del RIS."""
from pathlib import Path

CTRL = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
ENV = Path("/home/debuser/infra/portal-nuevo/.env")

text = CTRL.read_text(encoding="utf-8")
orig = text

old_select = """                ->select(
                    'appointment_studies.id',
                    'appointment_studies.exam_name',
                    'appointment_studies.report',
                    'appointments.start_time',
                    'appointments.accession_number',
                    'appointments.status'
                )"""
new_select = """                ->select(
                    'appointment_studies.id',
                    'appointment_studies.exam_name',
                    'appointment_studies.report',
                    'appointment_studies.report_document_path',
                    'appointments.start_time',
                    'appointments.accession_number',
                    'appointments.status'
                )"""
if old_select in text:
    text = text.replace(old_select, new_select, 1)
    print("OK select +report_document_path")
else:
    print("select: sin cambio")

redirect_block = """
            // PDF/documento adjunto desde RIS: abrir archivo real (no el placeholder de texto).
            $docPath = trim((string) ($study->report_document_path ?? ''));
            $reportText = trim((string) ($study->report ?? ''));
            $isPlaceholder = $reportText === '' || str_starts_with(mb_strtolower($reportText), 'informe adjunto');
            if ($docPath !== '' && $isPlaceholder) {
                $risPublic = rtrim((string) config('services.ris.public_url', env('RIS_PUBLIC_URL', 'https://api.healthticloud.cl')), '/');
                $rel = ltrim($docPath, '/');
                if (str_starts_with($rel, 'storage/')) {
                    $rel = substr($rel, strlen('storage/'));
                }
                return redirect($risPublic . '/storage/' . $rel);
            }

"""

needle = "$document = \\App\\Services\\ReportDocumentFormatter::buildStudyDocument("
if "PDF/documento adjunto desde RIS" not in text:
    if needle in text:
        text = text.replace(needle, redirect_block + "            " + needle, 1)
        print("OK redirect PDF insertado")
    else:
        # fallback: buscar sin asumir escapes exactos
        idx = text.find("ReportDocumentFormatter::buildStudyDocument(")
        if idx == -1:
            raise SystemExit("No se encontró ReportDocumentFormatter::buildStudyDocument")
        # retroceder al inicio de la línea del $document =
        line_start = text.rfind("\n", 0, idx) + 1
        text = text[:line_start] + redirect_block + text[line_start:]
        print("OK redirect PDF insertado (fallback)")
else:
    print("redirect: ya presente")

# Normalizar accession Orthanc↔RIS en buildRisReportMap
old_map_loop = """            $map = [];
            foreach ($query->get() as $row) {
                if (!empty($row->accession_number)) {
                    $map['acc:' . $row->accession_number] = (string) $row->study_row_id;
                }
                if (!empty($row->study_instance_uid)) {
                    $map['uid:' . $row->study_instance_uid] = (string) $row->study_row_id;
                }
            }"""
new_map_loop = """            $map = [];
            foreach ($query->get() as $row) {
                if (!empty($row->accession_number)) {
                    $acc = (string) $row->accession_number;
                    $map['acc:' . $acc] = (string) $row->study_row_id;
                    $norm = strtoupper(str_replace(['-', ' ', '.'], '', preg_replace('/^ACC-/i', '', $acc)));
                    if ($norm !== '') {
                        $map['acc:' . $norm] = (string) $row->study_row_id;
                    }
                }
                if (!empty($row->study_instance_uid)) {
                    $map['uid:' . $row->study_instance_uid] = (string) $row->study_row_id;
                }
            }"""
if old_map_loop in text:
    text = text.replace(old_map_loop, new_map_loop, 1)
    print("OK accession normalizado en map")
else:
    print("map loop: sin cambio")

old_resolve = """    private function resolveRisStudyId(?string $accession, ?string $studyUid, array $risMap): ?string
    {
        if ($accession && isset($risMap['acc:' . $accession])) {
            return $risMap['acc:' . $accession];
        }
        if ($studyUid && isset($risMap['uid:' . $studyUid])) {
            return $risMap['uid:' . $studyUid];
        }

        return null;
    }"""
new_resolve = """    private function resolveRisStudyId(?string $accession, ?string $studyUid, array $risMap): ?string
    {
        if ($accession) {
            if (isset($risMap['acc:' . $accession])) {
                return $risMap['acc:' . $accession];
            }
            $norm = strtoupper(str_replace(['-', ' ', '.'], '', preg_replace('/^ACC-/i', '', $accession)));
            if ($norm !== '' && isset($risMap['acc:' . $norm])) {
                return $risMap['acc:' . $norm];
            }
        }
        if ($studyUid && isset($risMap['uid:' . $studyUid])) {
            return $risMap['uid:' . $studyUid];
        }

        return null;
    }"""
if old_resolve in text:
    text = text.replace(old_resolve, new_resolve, 1)
    print("OK resolveRisStudyId normalizado")
else:
    print("resolve: sin cambio")

if text == orig:
    print("Sin cambios en StudyController")
else:
    CTRL.write_text(text, encoding="utf-8")
    print("StudyController guardado")

# RIS_PUBLIC_URL
env = ENV.read_text(encoding="utf-8") if ENV.exists() else ""
if "RIS_PUBLIC_URL=" not in env:
    ENV.write_text(env.rstrip() + "\n\nRIS_PUBLIC_URL=https://api.healthticloud.cl\n", encoding="utf-8")
    print("OK RIS_PUBLIC_URL agregado a .env")
else:
    print("RIS_PUBLIC_URL ya existe en .env")
    for line in env.splitlines():
        if line.startswith("RIS_PUBLIC_URL="):
            print(" ", line)

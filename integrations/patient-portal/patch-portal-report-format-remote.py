#!/usr/bin/env python3
"""Ejecutar en servidor portal: aplica formatter + blade + StudyController."""
import shutil
from pathlib import Path

PORTAL = Path("/home/debuser/infra/portal-nuevo")
FORMATTER_DST = PORTAL / "app/Services/ReportDocumentFormatter.php"
REPORT_BLADE_DST = PORTAL / "resources/views/report.blade.php"
CONTROLLER = PORTAL / "app/Http/Controllers/StudyController.php"

OLD_RETURN = """            return view('report', compact('study'));"""

NEW_RETURN = """            // PDF/documento adjunto desde RIS: abrir archivo real (no el placeholder de texto).
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

            $labRow = DB::connection('ris_db')
                ->table('appointments')
                ->join('laboratories', 'appointments.laboratory_id', '=', 'laboratories.id')
                ->where('appointments.id', DB::connection('ris_db')
                    ->table('appointment_studies')
                    ->where('appointment_studies.id', $id)
                    ->value('appointment_id'))
                ->select('laboratories.name', 'laboratories.address', 'laboratories.city', 'laboratories.settings')
                ->first();

            $lab = $labRow ? (object) [
                'name' => $labRow->name,
                'address' => $labRow->address,
                'city' => $labRow->city,
                'settings' => json_decode($labRow->settings ?? '{}', true) ?: [],
            ] : null;

            $destDoctor = DB::connection('ris_db')
                ->table('appointments')
                ->join('users', 'appointments.destination_doctor_id', '=', 'users.id')
                ->leftJoin('personas', 'users.persona_id', '=', 'personas.id')
                ->where('appointments.id', DB::connection('ris_db')
                    ->table('appointment_studies')
                    ->where('appointment_studies.id', $id)
                    ->value('appointment_id'))
                ->select('users.*', 'personas.names', 'personas.last_name_1', 'personas.last_name_2')
                ->first();

            $destUser = $destDoctor ? (object) [
                'username' => $destDoctor->username,
                'settings' => json_decode($destDoctor->settings ?? '{}', true) ?: [],
                'dragon_profile' => $destDoctor->dragon_profile ?? null,
                'signature_path' => $destDoctor->signature_path ?? null,
                'persona' => (object) [
                    'names' => $destDoctor->names,
                    'last_name_1' => $destDoctor->last_name_1,
                    'last_name_2' => $destDoctor->last_name_2,
                    'signature_path' => null,
                ],
            ] : null;

            $document = \\App\\Services\\ReportDocumentFormatter::buildStudyDocument(
                (object) ['start_time' => \\Carbon\\Carbon::parse($study->start_time ?? now())],
                [
                    'exam' => $study->exam_name ?? '',
                    'reportText' => $study->report ?? '',
                    'patient' => [
                        'name' => $study->first_name ?? ($user->name ?? 'Paciente'),
                        'lastName' => $study->last_name ?? '',
                    ],
                ],
                $lab,
                $destUser
            );
            $reportHtml = \\App\\Services\\ReportDocumentFormatter::buildHtml($document);

            return view('report', compact('reportHtml'));"""

DOC_SELECT_OLD = """                    'appointment_studies.report',
                    'appointments.start_time',"""
DOC_SELECT_NEW = """                    'appointment_studies.report',
                    'appointment_studies.report_document_path',
                    'appointments.start_time',"""

PDF_REDIRECT = """            // PDF/documento adjunto desde RIS: abrir archivo real (no el placeholder de texto).
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


def ensure_pdf_redirect(content: str) -> str:
    if "PDF/documento adjunto desde RIS" not in content:
        needle = "$document = \\App\\Services\\ReportDocumentFormatter::buildStudyDocument("
        if needle in content:
            content = content.replace(needle, PDF_REDIRECT + "            " + needle, 1)
            print("OK redirect PDF insertado")
        else:
            idx = content.find("ReportDocumentFormatter::buildStudyDocument(")
            if idx != -1:
                line_start = content.rfind("\n", 0, idx) + 1
                content = content[:line_start] + PDF_REDIRECT + content[line_start:]
                print("OK redirect PDF insertado (fallback)")
    if DOC_SELECT_OLD in content and "'appointment_studies.report_document_path'" not in content:
        content = content.replace(DOC_SELECT_OLD, DOC_SELECT_NEW, 1)
        print("OK select +report_document_path")
    return content


def main() -> None:
    FORMATTER_DST.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2("/tmp/ReportDocumentFormatter.php", FORMATTER_DST)
    shutil.copy2("/tmp/report.blade.php", REPORT_BLADE_DST)
    print("OK formatter + report.blade.php")

    content = CONTROLLER.read_text()
    if "ReportDocumentFormatter::buildHtml" in content and "compact('reportHtml')" in content:
        print("StudyController ya usa ReportDocumentFormatter")
        updated = ensure_pdf_redirect(content)
        if updated != content:
            CONTROLLER.write_text(updated)
            print("OK StudyController PDF redirect reforzado")
        return

    if OLD_RETURN not in content:
        raise SystemExit("No se encontró return view('report', compact('study'))")

    content = content.replace(OLD_RETURN, NEW_RETURN, 1)
    content = ensure_pdf_redirect(content)
    CONTROLLER.write_text(content)
    print("OK StudyController showReport")


if __name__ == "__main__":
    main()

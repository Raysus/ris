#!/usr/bin/env python3
"""Copia ReportDocumentFormatter al portal-nuevo y actualiza showReport + report.blade.php."""
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PORTAL = Path("/home/debuser/infra/portal-nuevo")
FORMATTER_SRC = ROOT.parent.parent / "backend/app/Services/ReportDocumentFormatter.php"
FORMATTER_DST = PORTAL / "app/Services/ReportDocumentFormatter.php"
REPORT_BLADE_SRC = ROOT / "laravel/report.blade.php"
REPORT_BLADE_DST = PORTAL / "resources/views/report.blade.php"
CONTROLLER = PORTAL / "app/Http/Controllers/StudyController.php"

OLD_RETURN = """            return view('report', compact('study'));"""

NEW_RETURN = """            $labRow = DB::connection('ris_db')
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
                ->select('users.*', 'personas.names', 'personas.last_name_1', 'personas.last_name_2', 'personas.signature_path as persona_signature')
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
                    'signature_path' => $destDoctor->persona_signature,
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


def main() -> None:
    FORMATTER_DST.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(FORMATTER_SRC, FORMATTER_DST)
    shutil.copy2(REPORT_BLADE_SRC, REPORT_BLADE_DST)
    print("OK formatter + report.blade.php")

    content = CONTROLLER.read_text()
    if "ReportDocumentFormatter::buildHtml" in content and "compact('reportHtml')" in content:
        print("StudyController ya usa ReportDocumentFormatter")
        return

    if OLD_RETURN not in content:
        raise SystemExit("No se encontró return view('report', compact('study'))")

    CONTROLLER.write_text(content.replace(OLD_RETURN, NEW_RETURN, 1))
    print("OK StudyController showReport")


if __name__ == "__main__":
    main()

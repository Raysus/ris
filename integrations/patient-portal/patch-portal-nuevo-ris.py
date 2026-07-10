#!/usr/bin/env python3
"""Aplica integración RIS en portal-nuevo StudyController + dashboard."""
from pathlib import Path

CONTROLLER = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
DASHBOARD = Path("/home/debuser/infra/portal-nuevo/resources/views/dashboard.blade.php")

RIS_HELPERS = '''
    private function rutHash(?string $rut): string
    {
        // Debe coincidir con App\\Models\\Persona::normalizeRut / hashRut del RIS
        // (mantiene el guión: 12345678-9).
        $normalized = strtoupper(str_replace(['.', ' '], '', (string) $rut));

        return hash('sha256', $normalized);
    }

    /** Map accession / StudyInstanceUID → appointment_studies.id con informe RIS listo. */
    private function buildRisReportMap(array $accessions, array $studyUids): array
    {
        $accessions = array_values(array_unique(array_filter(array_map('strval', $accessions))));
        $studyUids = array_values(array_unique(array_filter(array_map('strval', $studyUids))));

        if ($accessions === [] && $studyUids === []) {
            return [];
        }

        try {
            $query = DB::connection('ris_db')
                ->table('appointment_studies')
                ->join('appointments', 'appointment_studies.appointment_id', '=', 'appointments.id')
                ->whereIn('appointments.status', ['entregable', 'entregado'])
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report')
                            ->where('appointment_studies.report', '!=', '');
                    });
                    if (\\Illuminate\\Support\\Facades\\Schema::connection('ris_db')->hasColumn('appointment_studies', 'report_document_path')) {
                        $q->orWhere(function ($q2) {
                            $q2->whereNotNull('appointment_studies.report_document_path')
                                ->where('appointment_studies.report_document_path', '!=', '');
                        });
                    }
                })
                ->select(
                    'appointment_studies.id as study_row_id',
                    'appointments.accession_number',
                    'appointments.study_instance_uid'
                );

            $query->where(function ($q) use ($accessions, $studyUids) {
                $hasAcc = $accessions !== [];
                $hasUid = $studyUids !== [];
                if ($hasAcc) {
                    $q->whereIn('appointments.accession_number', $accessions);
                }
                if ($hasUid) {
                    if ($hasAcc) {
                        $q->orWhereIn('appointments.study_instance_uid', $studyUids);
                    } else {
                        $q->whereIn('appointments.study_instance_uid', $studyUids);
                    }
                }
            });

            $map = [];
            foreach ($query->get() as $row) {
                if (!empty($row->accession_number)) {
                    $map['acc:' . $row->accession_number] = (string) $row->study_row_id;
                }
                if (!empty($row->study_instance_uid)) {
                    $map['uid:' . $row->study_instance_uid] = (string) $row->study_row_id;
                }
            }

            return $map;
        } catch (\\Throwable $e) {
            Log::warning('buildRisReportMap: ' . $e->getMessage());

            return [];
        }
    }

    private function resolveRisStudyId(?string $accession, ?string $studyUid, array $risMap): ?string
    {
        if ($accession && isset($risMap['acc:' . $accession])) {
            return $risMap['acc:' . $accession];
        }
        if ($studyUid && isset($risMap['uid:' . $studyUid])) {
            return $risMap['uid:' . $studyUid];
        }

        return null;
    }

'''

INDEX_BLOCK_OLD = """                $reportIndex = $this->buildReportExistsIndex();

                foreach ($estudiosRaw as $estudio) {"""

INDEX_BLOCK_NEW = """                $reportIndex = $this->buildReportExistsIndex();
                $risAccList = [];
                $risUidList = [];
                foreach ($estudiosRaw as $estudio) {
                    if (!is_array($estudio)) {
                        continue;
                    }
                    $mainTags = $estudio['MainDicomTags'] ?? [];
                    if (!empty($mainTags['AccessionNumber'])) {
                        $risAccList[] = (string) $mainTags['AccessionNumber'];
                    }
                    if (!empty($mainTags['StudyInstanceUID'])) {
                        $risUidList[] = (string) $mainTags['StudyInstanceUID'];
                    }
                }
                $risReportMap = $this->buildRisReportMap($risAccList, $risUidList);

                foreach ($estudiosRaw as $estudio) {"""

HAS_REPORT_OLD = """                        'has_report' => isset($reportIndex[$id]),
                        'medico_solicitante' => $this->cleanDicomName($tags['ReferringPhysicianName'] ?? 'Sin Médico'),"""

HAS_REPORT_NEW = """                        'ris_study_id' => $this->resolveRisStudyId($tags['AccessionNumber'] ?? null, $tags['StudyInstanceUID'] ?? null, $risReportMap),
                        'has_report' => isset($reportIndex[$id]) || $this->resolveRisStudyId($tags['AccessionNumber'] ?? null, $tags['StudyInstanceUID'] ?? null, $risReportMap) !== null,
                        'medico_solicitante' => $this->cleanDicomName($tags['ReferringPhysicianName'] ?? 'Sin Médico'),"""

SHOW_REPORT_OLD = """    public function showReport($id)
    {
        try {
            $user = Auth::user();
            $rut = strtoupper(str_replace(['.', '-', ' '], '', $user->rut));

            $studyQuery = DB::connection('ris_db')
                ->table('appointment_studies')
                ->join('appointments', 'appointment_studies.appointment_id', '=', 'appointments.id')
                ->join('patients', 'appointments.patient_id', '=', 'patients.id')
                ->join('personas', 'patients.persona_id', '=', 'personas.id')
                ->select(
                    'appointment_studies.exam_name',
                    'appointment_studies.report',
                    'appointments.start_time',
                    'personas.first_name',
                    'personas.last_name',
                    'personas.rut'
                )
                ->where('appointment_studies.id', $id);

            if (!(method_exists($user, 'hasRole') && ($user->hasRole('super_admin') || $user->hasRole('admin_siresa') || $user->hasRole('medico_solicitante')))) {
                $studyQuery->where('personas.rut', 'LIKE', '%' . $rut . '%');
            }

            $study = $studyQuery->first();

            if (!$study || empty($study->report)) {
                abort(404, 'El informe no está disponible o no tienes permisos.');
            }

            return view('report', compact('study'));

        } catch (\\Exception $e) {
            return back()->with('error', 'No se pudo cargar el informe.');
        }
    }"""

SHOW_REPORT_NEW = """    public function showReport($id)
    {
        try {
            $user = Auth::user();
            $rutHash = $this->rutHash($user->rut ?? '');

            $studyQuery = DB::connection('ris_db')
                ->table('appointment_studies')
                ->join('appointments', 'appointment_studies.appointment_id', '=', 'appointments.id')
                ->join('patients', 'appointments.patient_id', '=', 'patients.id')
                ->join('personas', 'patients.persona_id', '=', 'personas.id')
                ->select(
                    'appointment_studies.id',
                    'appointment_studies.exam_name',
                    'appointment_studies.report',
                    'appointment_studies.report_document_path',
                    'appointments.start_time',
                    'appointments.accession_number',
                    'appointments.status'
                )
                ->where('appointment_studies.id', $id)
                ->whereIn('appointments.status', ['entregable', 'entregado'])
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report')
                            ->where('appointment_studies.report', '!=', '');
                    })->orWhere(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report_document_path')
                            ->where('appointment_studies.report_document_path', '!=', '');
                    });
                });

            if (!(method_exists($user, 'hasRole') && ($user->hasRole('super_admin') || $user->hasRole('admin_siresa') || $user->hasRole('medico_solicitante') || $user->hasRole('admin') || $user->hasRole('medico')))) {
                $studyQuery->where('personas.rut_hash', $rutHash);
            }

            $study = $studyQuery->first();

            if (!$study) {
                abort(404, 'El informe no está disponible o no tienes permisos.');
            }

            $nameParts = preg_split('/\\s+/', trim((string) ($user->name ?? 'Paciente')), 2);
            $study->first_name = $nameParts[0] ?? 'Paciente';
            $study->last_name = $nameParts[1] ?? '';
            $study->rut = $user->rut ?? '';

            // PDF/imagen adjunto: redirigir al archivo público del RIS si está configurado.
            $docPath = trim((string) ($study->report_document_path ?? ''));
            if ($docPath !== '') {
                $risPublic = rtrim((string) config('services.ris.public_url', env('RIS_PUBLIC_URL', '')), '/');
                if ($risPublic !== '') {
                    $rel = ltrim($docPath, '/');
                    if (!str_starts_with($rel, 'storage/')) {
                        $rel = 'storage/' . $rel;
                    }

                    return redirect($risPublic . '/' . $rel);
                }
            }

            return view('report', compact('study'));

        } catch (\\Exception $e) {
            Log::error('showReport RIS: ' . $e->getMessage());

            return back()->with('error', 'No se pudo cargar el informe.');
        }
    }"""

DASHBOARD_OLD = """                                                    @if(isset($study->has_report) && $study->has_report)
                                                        <a href="{{ route('reports.view', $study->study_id) }}" target="_blank"
                                                            class="inline-flex items-center gap-1.5 bg-primary-light text-primary border border-line hover:bg-primary-light/80 font-bold text-xs px-3 py-1.5 rounded-lg transition shadow-sm">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                            Informe
                                                        </a>"""

DASHBOARD_NEW = """                                                    @if(!empty($study->ris_study_id))
                                                        <a href="{{ route('report.show', $study->ris_study_id) }}" target="_blank"
                                                            class="inline-flex items-center gap-1.5 bg-primary-light text-primary border border-line hover:bg-primary-light/80 font-bold text-xs px-3 py-1.5 rounded-lg transition shadow-sm">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                            Informe RIS
                                                        </a>
                                                    @elseif(isset($study->has_report) && $study->has_report)
                                                        <a href="{{ route('reports.view', $study->study_id) }}" target="_blank"
                                                            class="inline-flex items-center gap-1.5 bg-primary-light text-primary border border-line hover:bg-primary-light/80 font-bold text-xs px-3 py-1.5 rounded-lg transition shadow-sm">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                            Informe
                                                        </a>"""


def patch_file(path: Path, old: str, new: str, label: str) -> None:
    content = path.read_text()
    if old not in content:
        raise SystemExit(f"No se encontró bloque {label} en {path}")
    path.write_text(content.replace(old, new, 1))
    print(f"OK {label}")


def main() -> None:
    ctrl = CONTROLLER.read_text()

    marker = "    private function orthancDashboardCacheVersion(): int"
    if "buildRisReportMap" not in ctrl:
        if marker not in ctrl:
            raise SystemExit("Marcador orthancDashboardCacheVersion no encontrado")
        ctrl = ctrl.replace(marker, RIS_HELPERS + "\n" + marker, 1)
        CONTROLLER.write_text(ctrl)
        print("OK ris helpers")

    patch_file(CONTROLLER, INDEX_BLOCK_OLD, INDEX_BLOCK_NEW, "index ris map prep")
    patch_file(CONTROLLER, HAS_REPORT_OLD, HAS_REPORT_NEW, "has_report + ris_study_id")
    patch_file(CONTROLLER, SHOW_REPORT_OLD, SHOW_REPORT_NEW, "showReport")
    patch_file(DASHBOARD, DASHBOARD_OLD, DASHBOARD_NEW, "dashboard link")

    print("Patch completo.")


if __name__ == "__main__":
    main()

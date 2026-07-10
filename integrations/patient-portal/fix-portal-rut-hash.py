#!/usr/bin/env python3
"""
Corrige en portal-nuevo el hash de RUT (debe coincidir con RIS Persona::hashRut)
y permite informes con documento adjunto.
Ejecutar en el servidor portal o vía deploy-portal-fix-rut-hash.py
"""
from pathlib import Path
import re

CONTROLLER = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")


def main() -> None:
    if not CONTROLLER.exists():
        raise SystemExit(f"No existe {CONTROLLER}")

    content = CONTROLLER.read_text(encoding="utf-8")
    original = content

    # 1) rutHash: no quitar el guión (alineado a RIS)
    content, n1 = re.subn(
        r"str_replace\(\['\.', ' ', '-'\]",
        "str_replace(['.', ' ']",
        content,
        count=2,
    )
    # Variante ya parcialmente corregida o con comillas dobles
    content, n1b = re.subn(
        r'str_replace\(\["\.", " ", "-"\]',
        'str_replace([".", " "]',
        content,
        count=2,
    )

    # 2) Comentario aclaratorio si existe la función rutHash antigua
    if "mantiene el guión" not in content and "private function rutHash" in content:
        content = content.replace(
            "private function rutHash(?string $rut): string\n    {\n        $normalized = strtoupper(str_replace(['.', ' '], '', (string) $rut));",
            "private function rutHash(?string $rut): string\n    {\n        // Alineado a RIS Persona::normalizeRut (mantiene el guión).\n        $normalized = strtoupper(str_replace(['.', ' '], '', (string) $rut));",
            1,
        )

    # 3) showReport: aceptar documento adjunto además de texto
    old_filter = """                ->whereIn('appointments.status', ['entregable', 'entregado'])
                ->whereNotNull('appointment_studies.report')
                ->where('appointment_studies.report', '!=', '');"""
    new_filter = """                ->whereIn('appointments.status', ['entregable', 'entregado'])
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report')
                            ->where('appointment_studies.report', '!=', '');
                    })->orWhere(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report_document_path')
                            ->where('appointment_studies.report_document_path', '!=', '');
                    });
                });"""
    n2 = 0
    if old_filter in content:
        content = content.replace(old_filter, new_filter, 1)
        n2 = 1

    # 4) buildRisReportMap: mismo criterio de documento
    old_map = """                ->whereIn('appointments.status', ['entregable', 'entregado'])
                ->whereNotNull('appointment_studies.report')
                ->where('appointment_studies.report', '!=', '')
                ->select("""
    new_map = """                ->whereIn('appointments.status', ['entregable', 'entregado'])
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report')
                            ->where('appointment_studies.report', '!=', '');
                    })->orWhere(function ($q2) {
                        $q2->whereNotNull('appointment_studies.report_document_path')
                            ->where('appointment_studies.report_document_path', '!=', '');
                    });
                })
                ->select("""
    n3 = 0
    if old_map in content:
        content = content.replace(old_map, new_map, 1)
        n3 = 1

    # 5) Incluir report_document_path en el select de showReport
    if "'appointment_studies.report'," in content and "report_document_path" not in content:
        content = content.replace(
            "'appointment_studies.report',\n                    'appointments.start_time',",
            "'appointment_studies.report',\n                    'appointment_studies.report_document_path',\n                    'appointments.start_time',",
            1,
        )

    if content == original:
        print("Sin cambios (¿ya corregido?). n1=%s n1b=%s n2=%s n3=%s" % (n1, n1b, n2, n3))
    else:
        CONTROLLER.write_text(content, encoding="utf-8")
        print("OK StudyController actualizado. rutHash=%s/%s showFilter=%s mapFilter=%s" % (n1, n1b, n2, n3))

    # Verificación
    text = CONTROLLER.read_text(encoding="utf-8")
    if "str_replace(['.', ' ', '-']" in text or 'str_replace([".", " ", "-"]' in text:
        raise SystemExit("Aún queda rutHash que elimina el guión")
    print("Verificado: rutHash ya no elimina el guión")


if __name__ == "__main__":
    main()

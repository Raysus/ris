#!/usr/bin/env python3
"""Arregla showReport: PDF antes de queries frágiles + sin signature_path."""
from pathlib import Path

CTRL = Path("/home/debuser/infra/portal-nuevo/app/Http/Controllers/StudyController.php")
text = CTRL.read_text(encoding="utf-8")
orig = text

# 1) Quitar signature_path de la query del médico (columna no existe en ris_db)
old_sig = "'personas.names', 'personas.last_name_1', 'personas.last_name_2', 'personas.signature_path as persona_signature'"
new_sig = "'personas.names', 'personas.last_name_1', 'personas.last_name_2'"
if old_sig in text:
    text = text.replace(old_sig, new_sig)
    print("OK quitado personas.signature_path del select")
else:
    print("select signature: sin cambio")

# Ajustar uso de persona_signature si quedó
text2 = text.replace(
    "'signature_path' => $destDoctor->persona_signature,",
    "'signature_path' => null,",
)
if text2 != text:
    text = text2
    print("OK persona_signature -> null")

# 2) Mover bloque PDF justo después de asignar rut al study (antes de labRow)
pdf_block = """
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

# Eliminar bloque PDF actual (está después de destUser)
marker = "            // PDF/documento adjunto desde RIS: abrir archivo real (no el placeholder de texto)."
while marker in text:
    start = text.find(marker)
    # hasta justo antes de $document = o return view
    end_candidates = [
        text.find("            $document = \\App\\Services\\ReportDocumentFormatter", start),
        text.find("            $document = \\App\\Services\\ReportDocumentFormatter", start),
    ]
    # find with single backslash as in file
    end = text.find("            $document = ", start)
    if end == -1:
        end = text.find("            return view('report'", start)
    if end == -1:
        raise SystemExit("No se pudo ubicar fin del bloque PDF")
    text = text[:start] + text[end:]
    print("OK bloque PDF antiguo eliminado")

anchor = "$study->rut = $user->rut ?? '';"
idx = text.find(anchor)
if idx == -1:
    raise SystemExit("No se encontró anchor rut")
insert_at = idx + len(anchor)
# Evitar duplicar
if "PDF/documento adjunto desde RIS" not in text:
    text = text[:insert_at] + "\n" + pdf_block + text[insert_at:]
    print("OK bloque PDF movido antes de lab/doctor")
else:
    print("PDF ya presente tras mover")

# 3) Asegurar report_document_path en select
if "'appointment_studies.report_document_path'" not in text.split("public function showReport", 1)[-1][:1200]:
    text = text.replace(
        "'appointment_studies.report',\n                    'appointments.start_time',",
        "'appointment_studies.report',\n                    'appointment_studies.report_document_path',\n                    'appointments.start_time',",
        1,
    )
    print("OK select report_document_path")

if text == orig:
    print("Sin cambios netos")
else:
    CTRL.write_text(text, encoding="utf-8")
    print("StudyController guardado")

# Verificar orden
chunk = text.split("public function showReport", 1)[-1][:3500]
for label in ["report_document_path", "PDF/documento", "labRow", "destDoctor", "signature_path", "ReportDocumentFormatter"]:
    pos = chunk.find(label)
    print(f"  pos {label}: {pos}")

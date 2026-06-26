#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Genera instructivo PDF: instalacion escaner (Node + NAPS2 + ris-local-bridge)."""

from __future__ import annotations

import subprocess
import zipfile
from datetime import date
from pathlib import Path
from xml.sax.saxutils import escape

ROOT = Path(__file__).resolve().parent
DOCX = ROOT / "INSTRUCTIVO_Escaner_RIS_Bridge.docx"
PDF = ROOT / "INSTRUCTIVO_Escaner_RIS_Bridge.pdf"

_MESES = {
    "January": "enero", "February": "febrero", "March": "marzo", "April": "abril",
    "May": "mayo", "June": "junio", "July": "julio", "August": "agosto",
    "September": "septiembre", "October": "octubre", "November": "noviembre", "December": "diciembre",
}
_raw = date.today().strftime("%d de %B de %Y")
FECHA = _raw.replace(date.today().strftime("%B"), _MESES.get(date.today().strftime("%B"), date.today().strftime("%B")))


def p(text, bold=False, size=22):
    t = escape(text)
    if bold:
        return f'<w:p><w:r><w:rPr><w:b/><w:sz w:val="{size}"/></w:rPr><w:t xml:space="preserve">{t}</w:t></w:r></w:p>'
    return f'<w:p><w:r><w:rPr><w:sz w:val="{size}"/></w:rPr><w:t xml:space="preserve">{t}</w:t></w:r></w:p>'


def h1(text):
    return p(text, bold=True, size=32)


def h2(text):
    return p(text, bold=True, size=26)


def bullet(text):
    t = escape(text)
    return (
        '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>'
        f'<w:r><w:t xml:space="preserve">{t}</w:t></w:r></w:p>'
    )


def table_row(cells, header=False):
    rows = []
    for cell in cells:
        t = escape(cell)
        rpr = "<w:rPr><w:b/></w:rPr>" if header else ""
        rows.append(
            f'<w:tc><w:p><w:r>{rpr}<w:t xml:space="preserve">{t}</w:t></w:r></w:p></w:tc>'
        )
    return "<w:tr>" + "".join(rows) + "</w:tr>"


def build_document_xml():
    parts = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">',
        "<w:body>",
        h1("HealthTiCloud RIS"),
        h2("Instructivo de instalacion — Escaner documental + RIS Local Bridge"),
        p(f"Fecha: {FECHA}"),
        p("Para recepcion / secretaria — Windows 10 u 11"),
        p(""),
        h2("1. Para que sirve"),
        p(
            "El boton Escanear de la Agenda del RIS digitaliza ordenes medicas y encuestas en PDF. "
            "La PC conectada al escaner USB debe tener el RIS Local Bridge, que controla el escaner via NAPS2."
        ),
        p(
            "El servidor RIS (ECOTEMUCO u otro laboratorio) NO necesita Node.js ni el escaner. "
            "Solo la PC donde esta el escaner USB necesita esta instalacion."
        ),
        bullet("Bridge: http://127.0.0.1:8181 (solo en esa PC)."),
        bullet("NAPS2: controla el escaner (drivers TWAIN/WIA)."),
        bullet("Node.js: ejecuta el bridge."),
        bullet("Las demas PCs del centro solo usan el navegador."),
        p(""),
        h2("2. Requisitos"),
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>',
        table_row(["Requisito", "Detalle"], header=True),
        table_row(["Sistema operativo", "Windows 10 o 11 (64 bits recomendado)"]),
        table_row(["Escaner", "USB conectado a ESTA PC (no al servidor remoto)"]),
        table_row(["Usuario", "Permiso para instalar programas"]),
        table_row(["Internet", "Solo durante la instalacion (descarga Node y NAPS2)"]),
        table_row(["Carpeta RIS", "Debe existir tools\\ris-local-bridge en el proyecto"]),
        table_row(["Navegador", "Chrome o Edge con acceso al RIS del centro"]),
        "</w:tbl>",
        p(""),
        h2("3. Instalacion automatica (recomendada)"),
        p(
            "Un solo script instala Node.js, NAPS2, dependencias, configura inicio automatico "
            "al iniciar sesion y verifica el servicio."
        ),
        p("Pasos:", bold=True),
        bullet("Conecte el escaner por USB y enciendalo."),
        bullet("Vaya a la carpeta: RIS\\tools\\ris-local-bridge"),
        bullet("Clic derecho en setup-scanner-windows.bat → Ejecutar como administrador."),
        bullet("Espere a que termine (varios minutos la primera vez)."),
        bullet("Configure NAPS2 segun la seccion 4."),
        p(""),
        p("Que hace el script:", bold=True),
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>',
        table_row(["Paso", "Accion"], header=True),
        table_row(["1", "Instala Node.js LTS con winget (si falta)"]),
        table_row(["2", "Instala NAPS2 con winget (si falta)"]),
        table_row(["3", "Crea config.json con ruta del escaner"]),
        table_row(["4", "Ejecuta npm install"]),
        table_row(["5", "Registra tarea programada (inicio oculto al iniciar sesion)"]),
        table_row(["6", "Inicia el bridge y comprueba http://127.0.0.1:8181/health"]),
        "</w:tbl>",
        p("Log: tools\\ris-local-bridge\\setup-scanner.log"),
        p(""),
        h2("4. Configurar el escaner en NAPS2 (obligatorio, una vez)"),
        p('El perfil debe llamarse exactamente Default (como en config.json).'),
        bullet("Abra NAPS2 desde el menu Inicio."),
        bullet('Perfil → Nuevo perfil → nombre: Default'),
        bullet("Seleccione su escaner USB."),
        bullet("Fuente: cristal o alimentador segun el equipo."),
        bullet("Formato: PDF (recomendado). Guarde."),
        bullet("Prueba: Escanear en NAPS2 y confirme que genera PDF."),
        p('Si usa otro nombre de perfil, edite config.json y cambie "profile".'),
        p(""),
        h2("5. Verificacion"),
        p("5.1 Bridge activo — abra en el navegador de la misma PC:", bold=True),
        p("http://127.0.0.1:8181/health"),
        p("Debe mostrar JSON con el servicio ris-local-bridge."),
        p("5.2 Escanear desde el RIS:", bold=True),
        bullet("Inicie sesion en el RIS desde esta PC."),
        bullet("Agenda → Nueva cita → Escanear orden."),
        bullet("Al terminar, el PDF queda adjunto a la cita."),
        p("Alternativa: Subir archivo (PDF o foto) sin escaner."),
        p(""),
        h2("6. Inicio automatico"),
        p(
            "Tarea programada: HealthTiCloud-RIS-Local-Bridge. Arranca oculto al iniciar sesion "
            "y se reinicia si se cae."
        ),
        p("Arranque manual (prueba): doble clic en start-bridge.bat"),
        p("Quitar inicio automatico (PowerShell):", bold=True),
        p("Unregister-ScheduledTask -TaskName 'HealthTiCloud-RIS-Local-Bridge' -Confirm:$false"),
        p(""),
        h2("7. Instalacion manual (si winget falla)"),
        bullet("Node.js LTS: https://nodejs.org"),
        bullet("NAPS2: https://www.naps2.com/download"),
        bullet("CMD en ris-local-bridge: npm install"),
        bullet("Copie config.example.json a config.json y edite rutas."),
        bullet("PowerShell: .\\install-windows-startup.ps1"),
        p(""),
        h2("8. Solucion de problemas"),
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>',
        table_row(["Sintoma", "Solucion"], header=True),
        table_row(["Toast: No se detecto el Escaner", "Verifique http://127.0.0.1:8181/health. Reejecute setup-scanner-windows.bat"]),
        table_row(["Bridge no responde", "Reinicie la tarea programada o ejecute start-bridge.bat"]),
        table_row(["NAPS2 fallo", "Pruebe escanear en NAPS2 a mano. Revise USB y drivers"]),
        table_row(["Perfil no encontrado", 'Cree perfil Default en NAPS2 o edite config.json']),
        table_row(["No funciona desde otra PC", "El bridge debe estar en la PC con el escaner"]),
        table_row(["winget no disponible", "Instale Node y NAPS2 manualmente (seccion 7)"]),
        "</w:tbl>",
        p(""),
        h2("9. Desinstalar"),
        bullet("Unregister-ScheduledTask (ver seccion 6)."),
        bullet("Desinstalar NAPS2 y Node.js desde Panel de control (opcional)."),
        p(""),
        p("— Fin del documento — HealthTiCloud RIS / soporte sistemas —"),
        '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>',
        "</w:body></w:document>",
    ]
    return "".join(parts)


CONTENT_TYPES = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
</Types>"""

RELS = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>"""

DOC_RELS = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
</Relationships>"""

NUMBERING = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:abstractNum w:abstractNumId="0">
    <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/>
    <w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>
  </w:abstractNum>
  <w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>
</w:numbering>"""


def write_docx(path: Path) -> None:
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", CONTENT_TYPES)
        z.writestr("_rels/.rels", RELS)
        z.writestr("word/_rels/document.xml.rels", DOC_RELS)
        z.writestr("word/numbering.xml", NUMBERING)
        z.writestr("word/document.xml", build_document_xml())


def convert_to_pdf(docx: Path, pdf: Path) -> None:
    for cmd in ("libreoffice", "soffice"):
        exe = subprocess.run(["which", cmd], capture_output=True, text=True)
        if exe.returncode != 0:
            continue
        lo = exe.stdout.strip()
        subprocess.run(
            [lo, "--headless", "--convert-to", "pdf", "--outdir", str(docx.parent), str(docx)],
            check=True,
            capture_output=True,
        )
        generated = docx.with_suffix(".pdf")
        if generated.exists() and generated != pdf:
            generated.replace(pdf)
        return
    raise RuntimeError("LibreOffice no encontrado. Abra el .docx y exporte a PDF manualmente.")


def main() -> None:
    write_docx(DOCX)
    print(f"DOCX generado: {DOCX}")
    try:
        convert_to_pdf(DOCX, PDF)
        print(f"PDF generado: {PDF}")
    except RuntimeError as e:
        print(str(e))
        print(f"Entregue el DOCX: {DOCX}")


if __name__ == "__main__":
    main()

#!/usr/bin/env python3
"""Genera propuesta comercial en formato .docx (sin dependencias externas)."""

import zipfile
from datetime import date
from xml.sax.saxutils import escape

OUTPUT = "/home/raul/Escritorio/RIS/docs/Propuesta_Agenda_Por_Medico.docx"

PRECIO_NIVEL_B = 300_000
PRECIO_COMPLETO = 500_000

TITLE = "Propuesta técnica y comercial"
SUBTITLE = "Agenda por médico / radiólogo (HealthTiCloud RIS)"
_MESES_ES = {
    "January": "enero", "February": "febrero", "March": "marzo", "April": "abril",
    "May": "mayo", "June": "junio", "July": "julio", "August": "agosto",
    "September": "septiembre", "October": "octubre", "November": "noviembre", "December": "diciembre",
}
_raw_fecha = date.today().strftime("%d de %B de %Y")
FECHA = _raw_fecha.replace(date.today().strftime("%B"), _MESES_ES.get(date.today().strftime("%B"), date.today().strftime("%B")))


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
        h1(TITLE),
        h2(SUBTITLE),
        p(f"Fecha: {FECHA}"),
        p("Documento preparado por: HealthTiCloud / equipo RIS"),
        p(""),
        h2("1. Resumen ejecutivo"),
        p(
            "El RIS actual cuenta con una Agenda Central orientada a recepción y agendamiento por sala "
            "(máquina/equipo DICOM). La solicitud es evaluar una versión alternativa —o complementaria— "
            "organizada por médico o radiólogo, para visualizar y planificar la carga de trabajo clínica "
            "por profesional."
        ),
        p(
            "Conclusión: el desarrollo es FACTIBLE. La plataforma ya registra médico tratante y médico "
            "destinado en cada cita, dispone de catálogo de radiólogos y usa FullCalendar Scheduler, "
            "que soporta recursos arbitrarios (filas por médico). No obstante, la lógica de negocio "
            "actual (colisiones, disponibilidad, arrastre de citas y worklist DICOM) está diseñada "
            "alrededor de salas, por lo que el alcance y el esfuerzo dependen del nivel de madurez "
            "que se requiera."
        ),
        p(""),
        h2("2. Situación actual del sistema"),
        bullet("Vista Día / Semana / Mes: cada fila del calendario = una sala (tabla machines)."),
        bullet("Las citas se ubican por machine_id; los exámenes pueden usar salas distintas en secuencia."),
        bullet("Validación de choque de horarios: solo por sala (assertNoScheduleOverlap en backend)."),
        bullet("Búsqueda de disponibilidad: recorre slots libres por sala, en frontend."),
        bullet("Médico tratante (referring_doctor_id) y médico destinado (destination_doctor_id) existen en el formulario, pero son metadatos; no definen la grilla."),
        bullet("Worklist técnica y envío DICOM siguen siendo por sala/AE Title — no cambiarían con una vista por médico de solo lectura."),
        p(""),
        h2("3. Niveles de solución propuestos"),
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>',
        table_row(["Nivel", "Descripción", "Esfuerzo", "Riesgo"], header=True),
        table_row([
            "A — Vista por médico (lectura)",
            "Misma agenda; filas = radiólogos; eventos según médico destinado. Sin nueva BD.",
            "1–1,5 semanas",
            "Bajo",
        ]),
        table_row([
            "B — Agenda operativa por médico",
            "Nivel A + asignación obligatoria, filtros, colisión por médico, API con rango de fechas.",
            "2–2,5 semanas",
            "Medio",
        ]),
        table_row([
            "C — Planificación clínica completa",
            "Nivel B + horarios/turnos por médico, disponibilidad, radiólogo por estudio, reportes.",
            "3–4 semanas adicionales",
            "Alto",
        ]),
        "</w:tbl>",
        p(""),
        p(
            f"Recomendación: se ofrecen dos alternativas cerradas — Nivel B hasta ${PRECIO_NIVEL_B:,} CLP "
            f"(3 semanas) o desarrollo completo (Niveles A+B+C) hasta ${PRECIO_COMPLETO:,} CLP.",
            bold=True,
        ).replace(",", "."),
        p(""),
        h2("4. Factibilidad técnica"),
        p("A favor:", bold=True),
        bullet("FullCalendar Scheduler ya implementado; cambiar resources de machines a médicos es viable."),
        bullet("Catálogo destination_doctors (usuarios con rol radiologo) ya expuesto en /agenda-catalogs."),
        bullet("destination_doctor_id ya se guarda en appointments y se usa en informes/validación."),
        bullet("Arquitectura modular: agenda.js concentrado; cambios acotados si se hace como vista alternativa."),
        p("Complejidades:", bold=True),
        bullet("Tres conceptos de médico (tratante, destinado, radiólogo por estudio) — hay que definir cuál manda en la grilla."),
        bullet("Citas multi-sala: hoy se calculan bloques secuenciales por sala; equivalente por médico requiere reglas nuevas."),
        bullet("Sin tabla de disponibilidad médica ni validación de doble reserva por profesional."),
        bullet("GET /appointments trae todo el historial; escala mal sin filtro por fechas."),
        bullet("Worklist y DICOM permanecen por sala; la agenda médica es paralela, no sustituto del flujo técnico."),
        p(""),
        h2("5. Alcance funcional sugerido (Nivel B — recomendado)"),
        bullet("Conmutador Salas | Médicos en la agenda."),
        bullet("Grilla por radiólogo (médico destinado) con vistas Día / Semana / Mes."),
        bullet("Colores/leyenda por estado; clic para abrir cita existente."),
        bullet("Al agendar/editar: médico destinado obligatorio en modalidades que lo requieran."),
        bullet("Validación backend: no permitir dos citas activas al mismo médico en horario solapado."),
        bullet("Filtro API por rango de fechas + laboratorio + médico."),
        bullet("Corrección filtro 'Mis pacientes' en módulo radiólogo (hoy el frontend lo ofrece pero el backend no filtra)."),
        bullet("Pruebas, documentación breve y despliegue en nube + laboratorio piloto."),
        p("Incluido solo en desarrollo completo (Nivel C):", bold=True),
        bullet("Turnos semanales por médico (lunes 08:00–14:00, etc.)."),
        bullet("Agendamiento automático óptimo médico+sala."),
        bullet("Portal para que el médico autogestione su agenda."),
        p(""),
        h2("6. Estimación de esfuerzo y plazos"),
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>',
        table_row(["Fase", "Actividades", "Días hábiles"], header=True),
        table_row(["Análisis y UX", "Reglas de negocio, definición de grilla y criterios médico", "2"]),
        table_row(["Backend", "Filtros API, overlap médico, ajustes catálogo", "4"]),
        table_row(["Frontend", "Vista médico, mapeo eventos, modal, conmutador salas/médicos", "5"]),
        table_row(["QA y UAT", "Pruebas regresión agenda salas + médicos", "2"]),
        table_row(["Despliegue", "Nube, capacitación breve, ajustes post-go-live", "2"]),
        table_row(["TOTAL", "", "15 días hábiles (3 semanas calendario)"]),
        "</w:tbl>",
        p(""),
        p("Plazo opción Nivel B: 3 semanas calendario desde el kick-off."),
        p("Plazo desarrollo completo (Niveles A+B+C): 5 a 6 semanas calendario desde el kick-off."),
        p(""),
        h2("7. Propuesta económica"),
        p(
            "Valores en pesos chilenos (CLP), IVA no incluido. Se presentan dos presupuestos cerrados "
            "según el alcance elegido por el cliente."
        ),
        '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/></w:tblPr>',
        table_row(["Opción", "Alcance", "Plazo", "Precio (CLP)"], header=True),
        table_row([
            "Estándar (recomendada)",
            "Niveles A + B — agenda operativa por médico",
            "3 semanas",
            f"${PRECIO_NIVEL_B:,}".replace(",", "."),
        ]),
        table_row([
            "Desarrollo completo",
            "Niveles A + B + C — planificación clínica completa",
            "5–6 semanas",
            f"${PRECIO_COMPLETO:,}".replace(",", "."),
        ]),
        table_row([
            "Nivel A aislado",
            "Solo visualización por médico (sin validaciones operativas)",
            "1,5 semanas",
            "Incluido en ambas opciones",
        ]),
        "</w:tbl>",
        p(
            f"Opción Nivel B: ${PRECIO_NIVEL_B:,} CLP (trescientos mil pesos chilenos).".replace(",", "."),
            bold=True,
        ),
        p(
            f"Desarrollo completo: ${PRECIO_COMPLETO:,} CLP (quinientos mil pesos chilenos).".replace(",", "."),
            bold=True,
        ),
        p(""),
        p("Incluye:", bold=True),
        bullet("Desarrollo, pruebas y despliegue en ambiente nube acordado."),
        bullet("1 ronda de ajustes post-UAT (hasta 8 h)."),
        bullet("Manual de usuario breve (PDF o ayuda en pantalla)."),
        p("No incluye:", bold=True),
        bullet("Horas de capacitación presencial extensa (cotizable aparte)."),
        bullet("Integraciones con sistemas externos no RIS."),
        bullet("Soporte mensual posterior al período de garantía (30 días)."),
        p(""),
        h2("8. Riesgos y supuestos"),
        bullet("El cliente define si la grilla usa médico destinado, tratante u otro criterio."),
        bullet("La agenda por sala actual se mantiene; no se reemplaza el flujo de recepción/salas."),
        bullet("Un laboratorio piloto para UAT (ej. Siresa o ECOTEMUCO)."),
        bullet("Cambios de alcance durante el proyecto se cotizan como adicional."),
        p(""),
        h2("9. Conclusión"),
        p(
            "Implementar una agenda por médico es técnicamente viable y se apoya en componentes ya "
            "existentes del RIS. La opción estándar (Nivel B) se entrega en 3 semanas por "
            f"${PRECIO_NIVEL_B:,} CLP; el desarrollo completo (Niveles A+B+C) en 5–6 semanas por "
            f"${PRECIO_COMPLETO:,} CLP, ambos con despliegue en producción.".replace(",", "."),
        ),
        p("— Fin del documento —"),
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


def main():
    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", CONTENT_TYPES)
        z.writestr("_rels/.rels", RELS)
        z.writestr("word/_rels/document.xml.rels", DOC_RELS)
        z.writestr("word/numbering.xml", NUMBERING)
        z.writestr("word/document.xml", build_document_xml())
    print(OUTPUT)


if __name__ == "__main__":
    main()

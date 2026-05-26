#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Contenido compartido del instructivo (PDF y DOCX)."""

from __future__ import annotations

from pathlib import Path
from typing import Protocol


TOC = [
    "1. Introducción al sistema",
    "2. Flujo clínico completo",
    "3. Acceso, interfaz y menú según su perfil",
    "4. Módulo Agenda (Recepción)",
    "5. Tecnología: Worklist y Atención en salas",
    "6. Módulo Radiólogo",
    "7. Módulo Transcripción",
    "8. Módulo Validación",
    "9. Módulo Entrega y retiro",
    "10. Módulo Administración",
    "11. Módulo Dashboard",
    "12. Anexo: centros dental y veterinarios",
    "13. Anexo técnico",
]


class InstructivoRenderer(Protocol):
    def cover(self, banner: Path | None) -> None: ...
    def toc(self, items: list[str]) -> None: ...
    def chapter(self, number: str, title: str) -> None: ...
    def h2(self, text: str) -> None: ...
    def p(self, text: str) -> None: ...
    def bullets(self, items: list[str]) -> None: ...
    def steps(self, items: list[str]) -> None: ...
    def figure(
        self,
        path: Path,
        caption: str,
        max_height: float = 88,
        caption_below: bool = True,
    ) -> None: ...
    def render_table(
        self,
        headers: list[str],
        rows: list[list[str]],
        col_widths: tuple[float, ...] | None = None,
    ) -> None: ...


def render_instructivo(
    doc: InstructivoRenderer,
    shots: dict[str, Path],
    flujo: Path,
) -> None:
    def s(name: str) -> Path | None:
        return shots.get(name)

    doc.cover(s("02_layout_agenda") or s("03_modulo_agenda"))
    doc.toc(TOC)

    doc.chapter("1", "Introducción al sistema")
    doc.p(
        "HealthTiCloud RIS es la aplicación web del centro para gestionar el recorrido del paciente: "
        "desde la cita en recepción hasta la entrega del informe. Cada persona del equipo accede "
        "con su usuario y ve solo los módulos que le corresponden según su rol."
    )
    if s("02_layout_agenda"):
        doc.figure(
            s("02_layout_agenda"),
            "Figura 1. Vista principal del sistema (ejemplo: Agenda).",
            max_height=76,
        )
    doc.h2("Módulos del sistema")
    doc.render_table(
        ["Módulo", "Para qué sirve", "Perfiles que suelen usarlo"],
        [
            ["Agenda", "Citación, calendario, pagos y confirmación de pacientes.", "Recepción, Secretaria, Administrador"],
            ["Worklist", "Atención en sala con worklist DICOM (centros clínicos).", "Tecnólogo, Administrador"],
            ["Atención en salas", "Atención técnica y subida manual de imágenes (dental / vet).", "Tecnólogo, Administrador"],
            ["Radiólogo", "Lectura de imágenes, borrador y firma de informes.", "Médico radiólogo, Administrador"],
            ["Transcripción", "Redacción del informe a partir del audio dictado.", "Transcriptor, Administrador"],
            ["Validación", "Revisión y firma final del informe transcrito.", "Médico radiólogo, Administrador"],
            ["Entrega/Retiro", "Entrega de resultados al paciente o autorizado.", "Recepción, Secretaria, Administrador"],
            ["Administración", "Usuarios, catálogos, salas, reportes y configuración.", "Administrador, Sys. Admin"],
            ["Dashboard", "Indicadores del día: citas, ingresos, flujo clínico.", "Administrador y supervisión"],
        ],
        (38, 72, 80),
    )

    doc.chapter("2", "Flujo clínico completo")
    doc.p(
        "Un paciente típico recorre estas etapas. Varios módulos intervienen en distintos momentos; "
        "no es necesario que un mismo usuario vea todos los pasos."
    )
    if flujo.exists():
        doc.figure(flujo, "Figura 2. Diagrama del flujo clínico.", max_height=46)
    doc.steps([
        "Recepción crea la cita en Agenda (paciente, examen, pago).",
        "Recepción confirma la llegada del paciente al centro.",
        "Tecnología atiende en Worklist (clínico) o en Atención en salas (dental/vet) y deja las imágenes en PACS.",
        "El radiólogo interpreta el estudio y produce el informe.",
        "Si hubo dictado por audio, transcripción redacta el texto.",
        "El radiólogo valida y firma el informe final.",
        "Recepción registra la entrega del resultado al paciente.",
    ])
    doc.render_table(
        ["Estado de la cita", "Quién actúa", "Módulo"],
        [
            ["Agendado / Confirmado / En espera", "Recepcionista", "Agenda"],
            ["DICOM enviado / imágenes en PACS", "Tecnólogo", "Worklist o Atención en salas"],
            ["En informe / En transcripción", "Radiólogo / Transcriptor", "Radiólogo / Transcripción"],
            ["Entregable", "—", "Entrega"],
            ["Entregado", "Recepcionista", "Entrega"],
        ],
        (52, 42, 96),
    )

    doc.chapter("3", "Acceso, interfaz y menú según su perfil")
    if s("01_login"):
        doc.figure(s("01_login"), "Figura 3. Pantalla de inicio de sesión.", max_height=58)
    doc.h2("Inicio de sesión")
    doc.steps([
        "Abra la URL del sistema e ingrese el usuario y contraseña entregados por su administrador.",
        "Tras ingresar, seleccione el laboratorio o sucursal en la barra superior (si aplica).",
        "El menú lateral muestra los módulos disponibles para usted.",
    ])
    doc.h2("¿Por qué veo menos módulos que un compañero?")
    doc.p(
        "Es normal. El menú lateral se adapta automáticamente al rol asignado a su usuario. "
        "Solo aparecen los módulos que necesita para su trabajo diario."
    )
    doc.bullets([
        "Recepción o Secretaria: Agenda y Entrega/Retiro (la secretaria solo edita pacientes en sede RDOX Osorno).",
        "Tecnólogo en centro clínico: Worklist; en dental o veterinario: Atención en salas (no Worklist).",
        "Radiólogo: Radiólogo y Validación.",
        "Transcriptor: solo Transcripción.",
        "Administrador (admin): todos los módulos operativos y Administración, pero solo en sus laboratorios asignados.",
        "Sys. Admin (sis_admin): mismos módulos con selector «Visión Global» y acceso a todas las sedes del sistema.",
        "Un usuario puede tener varios roles en settings; el menú muestra la unión de módulos permitidos.",
    ])
    doc.h2("Qué hacer si falta un módulo")
    doc.bullets([
        "Verifique que seleccionó el laboratorio correcto en la barra superior.",
        "Cierre sesión y vuelva a entrar (por si hubo un cambio reciente de permisos).",
        "Contacte al administrador del centro para revisar sus roles asignados.",
        "No intente acceder a pantallas ocultas: la API también valida permisos y puede rechazar operaciones.",
    ])
    doc.h2("Elementos comunes de la interfaz")
    doc.bullets([
        "Menú lateral izquierdo: cambio entre módulos visibles para usted.",
        "Barra superior: laboratorio activo, nombre de usuario y rol.",
        "Toasts (avisos abajo a la derecha): confirmaciones y errores.",
        "Modales de confirmación: acciones importantes piden confirmación antes de ejecutarse.",
    ])
    doc.render_table(
        ["Módulo en menú", "Roles con acceso"],
        [
            ["Agenda", "recepcion, secretaria, secretario, admin, sis_admin"],
            ["Worklist", "tecnologo, admin, sis_admin (clínico con MWL)"],
            ["Atención en salas", "tecnologo, admin, sis_admin (dental / vet / sin MWL)"],
            ["Radiólogo", "radiologo, admin, sis_admin"],
            ["Transcripción", "transcriptor, admin, sis_admin"],
            ["Validación", "radiologo, admin, sis_admin"],
            ["Entrega/Retiro", "recepcion, secretaria, secretario, admin, sis_admin"],
            ["Administración", "admin, sis_admin"],
            ["Dashboard", "admin, recepcion, tecnologo, radiologo, transcriptor, sis_admin"],
        ],
        (70, 120),
    )
    doc.h2("Administrador vs Sys. Admin")
    doc.render_table(
        ["Aspecto", "admin (centro)", "sis_admin (sistema)"],
        [
            ["Laboratorios visibles", "Solo asignados (matriz + sucursales)", "Todos (Visión Global o cualquier sede)"],
            ["Crear matrices nuevas", "No", "Sí (Administración)"],
            ["Crear usuarios secretaria", "Sí", "Sí"],
            ["Datos en módulos", "Filtrados a sus sedes", "Todos los del sistema o sede elegida"],
        ],
        (52, 74, 74),
    )

    doc.chapter("4", "Módulo Agenda (Recepción)")
    doc.p("Acceso: recepcionistas y administradores del centro.")
    if s("03_modulo_agenda"):
        doc.figure(
            s("03_modulo_agenda"),
            "Figura 4. Calendario de agenda por sala y horario.",
            max_height=78,
        )
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Ver el calendario de citas por sala/equipo con códigos de color por estado.",
        "Buscar pacientes por RUT o apellido en la barra superior.",
        "Crear una cita nueva (clic en horario libre o botón equivalente).",
        "Editar una cita existente (clic sobre el evento en el calendario).",
        "Registrar o actualizar datos del paciente, previsión y médicos.",
        "Agregar uno o más exámenes, insumos y adjuntar orden médica si corresponde.",
        "Registrar pago (pendiente, parcial o pagado) y método de cobro.",
        "Cambiar estado de la cita: confirmar llegada, marcar en espera, anular, etc.",
        "Si la cita queda Confirmada y el centro es dental o veterinario, aparece un aviso con "
        "botón para ir al módulo Atención en salas.",
    ])
    if s("11_wizard_agenda"):
        doc.figure(s("11_wizard_agenda"), "Figura 5. Formulario de cita por pasos.", max_height=88)
    doc.h2("Wizard de nueva cita (4 pasos)")
    doc.steps([
        "Paciente: RUT, nombres, contacto, previsión. Puede recuperar un paciente ya registrado.",
        "Cita: médico tratante/destinador, sala, fecha, procedencia (Ambulatorio, Hospitalizado, etc.) y prioridad.",
        "Exámenes: prestaciones del catálogo; puede ajustar precio con justificación.",
        "Pago: método, montos y estado de cobro antes de guardar.",
    ])
    doc.h2("Correo de instrucciones al paciente")
    doc.p(
        "Al guardar una cita cuya procedencia NO sea Ambulatorio, el sistema puede enviar por correo "
        "las instrucciones de preparación configuradas en Administración para cada examen. "
        "Requiere email válido del paciente y correo SMTP configurado en el servidor."
    )
    doc.h2("Colores del calendario")
    doc.bullets([
        "Violeta: pre-agendado.",
        "Verde: agendado.",
        "Azul: confirmado.",
        "Ámbar: en espera / recepcionado.",
        "Rojo: anulado o no asiste.",
        "Gris: ya atendido.",
    ])

    doc.chapter("5", "Tecnología: Worklist y Atención en salas")
    doc.p(
        "Acceso: tecnólogos y administradores. El menú muestra Worklist o Atención en salas "
        "según el tipo de laboratorio activo (no ambos a la vez)."
    )
    if s("04_modulo_worklist"):
        doc.figure(
            s("04_modulo_worklist"),
            "Figura 6. Lista de pacientes en espera de atención técnica (ejemplo Worklist).",
            max_height=78,
        )
    doc.h2("Flujo común (todos los centros)")
    doc.steps([
        "Recepción agenda y confirma la cita en el módulo Agenda.",
        "El tecnólogo abre la lista del día y pulsa Atender sobre el paciente.",
        "Registra anamnesis, insumos y deja las imágenes disponibles en PACS.",
        "Finaliza la atención para derivar el caso al radiólogo.",
    ])
    doc.h2("Worklist — centros clínicos con MWL")
    doc.bullets([
        "Ver pacientes confirmados, agrupados por cadena de estudios.",
        "Filtrar por sala/equipo o buscar por RUT o nombre.",
        "Crear accession y enviar la orden DICOM al equipo (worklist Orthanc).",
        "El equipo adquiere el estudio; el estado pasa a «En modalidad».",
        "Completar la atención cuando las imágenes estén en PACS.",
        "Devolver a recepción con motivo si corresponde.",
    ])
    doc.h2("Atención en salas — dental, veterinario o sin worklist")
    doc.p(
        "Para CBCT, sensores intraorales u otros equipos que exportan DICOM por archivo "
        "(sin licencia MWL o sin integración worklist), use este módulo en lugar de Worklist."
    )
    doc.bullets([
        "Misma lista de pacientes confirmados desde Agenda.",
        "En el modal de atención: subir archivo .dcm o .zip exportado del equipo.",
        "El sistema envía las imágenes a Orthanc y etiqueta RUT, nombre y accession de la cita.",
        "Estado «Imágenes en PACS» cuando la subida fue exitosa.",
        "Luego registre anamnesis e insumos y finalice hacia el radiólogo.",
        "En la barra superior seleccione el laboratorio dental o veterinario correcto antes de operar.",
    ])
    doc.p(
        "Entornos de prueba tras db:seed incluyen «Dental Demo — CBCT Temuco», "
        "«Dental Demo — Sucursal Centro», «Veterinaria Demo Sur» y «Veterinaria Demo — Urgencias 24h». "
        "El usuario tecnólogo de prueba (friquelme) tiene acceso a esas sedes."
    )

    doc.chapter("6", "Módulo Radiólogo")
    doc.p("Acceso: médicos radiólogos y administradores.")
    if s("05_modulo_radiologo"):
        doc.figure(
            s("05_modulo_radiologo"),
            "Figura 7. Estudios pendientes de informe.",
            max_height=78,
        )
    doc.h2("Visor OHIF (imágenes)")
    doc.p(
        "Desde Radiólogo o Validación, el botón del visor abre el OHIF en una pestaña nueva. "
        "La URL usa el formato del visor centralizado:"
    )
    doc.bullets([
        "Base: https://viewer.healthticloud.cl/viewer",
        "Parámetro: StudyInstanceUIDs=<UID del estudio en PACS>",
        "Ejemplo: …/viewer?StudyInstanceUIDs=1.2.620.54321.0.27187.0.0.20260526.104437",
        "El RIS resuelve el UID consultando Orthanc/PACS a partir del accession de la cita si hace falta.",
        "Si no hay bridge local (RadiAnt/Weasis), se usa OHIF como respaldo automático.",
    ])
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Ver estudios listos para lectura (cadena de atención completa en worklist).",
        "Abrir visor DICOM web (OHIF) o visor local si el bridge está instalado en la PC.",
        "Redactar borrador con hallazgos y conclusión.",
        "Guardar borrador y continuar más tarde.",
        "Firmar y liberar el informe (si redactó directamente en pantalla).",
        "Enviar a Transcripción si dictó el informe por audio.",
        "Devolver al tecnólogo con motivo si la imagen tiene problemas técnicos.",
    ])

    doc.chapter("7", "Módulo Transcripción")
    doc.p("Acceso: transcriptores y administradores.")
    if s("06_modulo_transcripcion"):
        doc.figure(
            s("06_modulo_transcripcion"),
            "Figura 8. Cola de audios pendientes de transcribir.",
            max_height=78,
        )
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Ver estudios con audio dictado pendiente de redacción.",
        "Escuchar el audio y transcribir al editor de informe.",
        "Guardar borrador sin cerrar el caso.",
        "Enviar a Validación cuando el texto esté completo.",
        "Devolver el audio al radiólogo si está inaudible, cortado o vacío (indicando motivo).",
    ])

    doc.chapter("8", "Módulo Validación")
    doc.p("Acceso: radiólogos (validación de informes transcritos) y administradores.")
    if s("07_modulo_validacion"):
        doc.figure(
            s("07_modulo_validacion"),
            "Figura 9. Informes pendientes de validación.",
            max_height=78,
        )
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Revisar informes transcritos antes de la firma definitiva.",
        "Comparar texto con datos del paciente y estándares del centro.",
        "Firmar y liberar: la cita pasa a estado entregable.",
        "Devolver a secretaría/transcripción con observaciones si requiere corrección.",
    ])

    doc.chapter("9", "Módulo Entrega y retiro")
    doc.p("Acceso: recepcionistas y administradores.")
    if s("08_modulo_entrega"):
        doc.figure(
            s("08_modulo_entrega"),
            "Figura 10. Resultados listos para retiro.",
            max_height=78,
        )
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Buscar pacientes con informes firmados (estado entregable).",
        "Filtrar pendientes de retiro o ya entregados.",
        "Registrar quién retira (RUT), relación con el paciente y método.",
        "Confirmar entrega presencial, envío por correo o registro de impresión.",
        "Revertir una entrega si hubo error (según permisos del centro).",
    ])

    doc.chapter("10", "Módulo Administración")
    doc.p(
        "Acceso: administradores del centro. "
        "No visible para recepción, tecnología ni radiología en su día a día."
    )
    if s("09_modulo_admin"):
        doc.figure(
            s("09_modulo_admin"),
            "Figura 11. Panel de administración del centro.",
            max_height=78,
        )
    doc.h2("Usuarios y rol Secretaria")
    doc.steps([
        "Administración → Usuarios → Nuevo usuario.",
        "Complete datos personales, usuario y contraseña.",
        "Marque el rol Secretaria (u otros roles operativos necesarios).",
        "Asigne al menos un laboratorio/sucursal y guarde.",
        "La secretaria verá Agenda y Entrega; la edición de fichas de pacientes solo aplica en RDOX Osorno.",
    ])
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Gestionar usuarios: crear, editar roles (incluye secretaria), asignar laboratorios.",
        "Configurar salas/equipos: modalidad, AE Title, IP DICOM.",
        "Mantener catálogo de exámenes, precios e instrucciones por correo.",
        "Administrar insumos, previsiones, planes y convenios.",
        "Plantillas de informes y parámetros del centro.",
        "Reportes de producción, honorarios y nómina.",
        "Exportar datos a Excel donde esté disponible.",
    ])

    doc.chapter("11", "Módulo Dashboard")
    doc.p("Acceso: administradores y perfiles operativos con permiso de visualización.")
    if s("10_modulo_dashboard"):
        doc.figure(
            s("10_modulo_dashboard"),
            "Figura 12. Indicadores operativos del día.",
            max_height=78,
        )
    doc.h2("Qué puede hacer aquí")
    doc.bullets([
        "Consultar KPIs del día: pacientes, exámenes realizados, ingresos estimados.",
        "Ver tiempo promedio de entrega (TAT) y tendencia respecto al día anterior.",
        "Revisar producción por modalidad y distribución por previsión.",
        "Monitorear en qué etapa del flujo clínico están las citas del día.",
        "Usar el selector de laboratorio para filtrar sucursales (administradores multi-sede).",
    ])

    doc.chapter("12", "Anexo: centros dental y veterinarios")
    doc.p(
        "Este manual describe el flujo clínico estándar (centro de diagnóstico por imágenes humano). "
        "Si su centro es dental o veterinario, el mismo HealthTiCloud RIS aplica, pero la interfaz "
        "y algunas opciones de facturación se adaptan automáticamente al tipo de laboratorio. "
        "No necesita otro manual: use los capítulos 1 a 11 y consulte aquí las diferencias."
    )
    doc.h2("Configuración inicial (administrador)")
    doc.steps([
        "Ingrese a Administración con un usuario administrador.",
        "Al crear o editar la matriz o una sucursal, seleccione el Tipo de laboratorio correcto: "
        "Clínico Humano, Centro Dental o Veterinario.",
        "Guarde los cambios. Los usuarios deben cerrar sesión y volver a entrar, o cambiar de "
        "laboratorio en la barra superior, para que el perfil se actualice.",
        "Verifique en Agenda que no aparecen bonos FONASA ni previsión clínica si corresponde a dental.",
    ])
    doc.p(
        "El tipo queda guardado en el registro del laboratorio. Si el tipo no coincide con la realidad "
        "del centro (por ejemplo, un dental configurado como clínico), verá pantallas de FONASA que "
        "no debería usar; corrija el tipo en Administración."
    )
    doc.h2("Resumen por tipo de centro")
    doc.render_table(
        ["Aspecto", "Clínico humano", "Centro dental", "Veterinario"],
        [
            ["Bonos y panel FONASA", "Sí", "No (oculto)", "No (oculto)"],
            ["Previsión en cita (FONASA/ISAPRE)", "Sí", "Oculta en agenda", "Solo Particular / Convenios"],
            ["Etiqueta del sujeto de atención", "Paciente", "Paciente", "Mascota"],
            ["Identificación", "RUT / Documento", "RUT / Documento", "ID mascota / microchip"],
            ["Código de prestación en exámenes", "Cód. FONASA", "Cód. prestación", "Cód. prestación"],
            ["Módulo de tecnología", "Worklist (MWL)", "Atención en salas", "Atención en salas"],
            ["Subida de imágenes", "Equipo + worklist", "Archivo .dcm/.zip manual", "Archivo .dcm/.zip manual"],
            ["Flujo → Informe → Entrega", "Igual", "Igual", "Igual"],
        ],
        (42, 48, 48, 50),
    )
    doc.h2("Recepción y Agenda")
    doc.bullets([
        "El calendario, wizard de cita, pagos y estados funcionan igual que en un centro clínico.",
        "En dental y veterinario no se ofrecen tipos de bono Manual ni Electrónico; el cobro es "
        "particular, convenio u otro método configurado en su centro.",
        "Si intenta registrar un bono FONASA por integración externa, el sistema responderá que "
        "no aplica a ese tipo de laboratorio.",
        "En dental no verá los campos de previsión ni plan en el paso Paciente de la cita.",
        "En veterinario sí puede elegir previsión, pero solo las opciones Particular o Convenios "
        "(no listas FONASA/ISAPRE chilenas).",
        "Las etiquetas en pantalla cambian en veterinario (por ejemplo «Mascota» en lugar de «Paciente»).",
    ])
    doc.h2("Centro dental: puntos clave")
    doc.bullets([
        "Ideal para radiología intraoral, panorámicas, CBCT u otros estudios odontológicos.",
        "Flujo: Agenda (confirmar) → Atención en salas (subir DICOM) → Radiólogo → Entrega.",
        "No aparece el menú Worklist; solo Atención en salas.",
        "Catálogo de exámenes e insumos se administra igual; use códigos de prestación internos.",
        "Médico derivante y sala/equipo (CBCT, sensor) se configuran en Administración.",
        "Correos de preparación al paciente (capítulo 4) siguen disponibles si tiene SMTP configurado.",
    ])
    doc.h2("Centro veterinario: puntos clave")
    doc.bullets([
        "El dueño o responsable se registra en los datos de contacto del paciente (mascota).",
        "Use el identificador de la mascota (microchip, ficha interna) en el campo de documento.",
        "Previsión limitada a Particular o Convenios; copago clínico no se calcula automáticamente.",
        "Atención en salas, radiólogo, transcripción, validación y entrega operan igual que en clínico.",
        "Subida manual de imágenes cuando el equipo no usa worklist DICOM.",
    ])
    doc.h2("Laboratorios de demostración (desarrollo)")
    doc.render_table(
        ["Nombre en selector", "Tipo", "Uso"],
        [
            ["Dental Demo — CBCT Temuco", "Centro Dental", "Matriz dental de prueba"],
            ["Dental Demo — Sucursal Centro", "Centro Dental", "Sucursal hija dental"],
            ["Veterinaria Demo Sur", "Veterinario", "Matriz veterinaria de prueba"],
            ["Veterinaria Demo — Urgencias 24h", "Veterinario", "Sucursal veterinaria"],
            ["Centro de Diagnóstico RIS PRO", "Clínico", "Flujo worklist clásico"],
        ],
        (58, 38, 94),
    )
    doc.p(
        "Se crean al ejecutar php artisan db:seed. Para forzar modo manual en un centro clínico, "
        "el administrador puede guardar en settings del laboratorio: "
        '{"uses_dicom_worklist": false}.'
    )
    doc.h2("Qué no cambia")
    doc.bullets([
        "Usuarios, roles, salas, modalidades DICOM, Orthanc/PACS y visor OHIF (https://viewer.healthticloud.cl).",
        "Dashboard, reportes de producción, sync nube, HL7 y documentos tributarios (DTE), si su plan los incluye.",
        "Portal del paciente: https://portal.healthticloud.cl (servicio aparte).",
    ])
    doc.p(
        "Documentación técnica de instalación: docs/INSTALACION.md"
    )

    doc.chapter("13", "Anexo técnico")
    doc.p("Información para soporte TI o regeneración de este manual.")
    doc.bullets([
        "URL producción RIS: https://ris.healthticloud.cl — API en /api (config.js con detección automática).",
        "Visor OHIF: VIEWER_URL + VIEWER_PATH=/viewer + VIEWER_QUERY_PARAM=StudyInstanceUIDs.",
        "Resolución UID: GET /api/viewer-study-uid?accession=… (consulta Orthanc).",
        "Regenerar PDF: python docs/generate_instructivo.py",
        "Regenerar DOCX: python docs/generate_instructivo_docx.py",
        "Regenerar capturas: node docs/capture_screenshots.mjs (frontend en puerto 8765, API en 8000).",
        "Correo en desarrollo: MAIL_MAILER=log escribe en storage/logs/laravel.log.",
        "Perfil de laboratorio API: GET /api/lab-profile (header X-Lab-Id).",
        "Demos operativos: php artisan db:seed --class=DemoModulesSeeder (sedes DEMO-L*, sin tocar SIRESA).",
    ])

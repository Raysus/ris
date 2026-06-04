#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Genera ilustraciones PNG para el instructivo PDF."""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ASSETS = Path(__file__).resolve().parent / "assets"
# Marca accesible (#5b4a82), alineada con frontend y portal
COLOR_PRIMARY = (91, 74, 130)
COLOR_DARK = (33, 37, 41)
COLOR_MUTED = (108, 117, 125)
COLOR_LIGHT = (240, 248, 247)
COLOR_WHITE = (255, 255, 255)


def _font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        "C:/Windows/Fonts/segoeuib.ttf" if bold else "C:/Windows/Fonts/segoeui.ttf",
        "C:/Windows/Fonts/arialbd.ttf" if bold else "C:/Windows/Fonts/arial.ttf",
    ]
    for path in candidates:
        if Path(path).exists():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def _center_text(draw: ImageDraw.ImageDraw, xy: tuple[int, int, int, int], text: str, font, fill) -> None:
    x0, y0, x1, y1 = xy
    bbox = draw.multiline_textbbox((0, 0), text, font=font, align="center")
    tw, th = bbox[2] - bbox[0], bbox[3] - bbox[1]
    draw.multiline_text((x0 + (x1 - x0 - tw) / 2, y0 + (y1 - y0 - th) / 2), text, font=font, fill=fill, align="center")


def _rounded_box(draw, xy, fill, outline=None, radius=12):
    draw.rounded_rectangle(xy, radius=radius, fill=fill, outline=outline or COLOR_PRIMARY, width=2)


def draw_flujo_clinico(path: Path) -> None:
    w, h = 1400, 340
    img = Image.new("RGB", (w, h), COLOR_WHITE)
    draw = ImageDraw.Draw(img)
    title_f = _font(34, True)
    box_f = _font(24, True)
    small_f = _font(18)

    draw.text((36, 18), "Flujo clínico — HealthTiCloud RIS", font=title_f, fill=COLOR_DARK)

    steps = [
        "Agenda", "Confirmación", "Worklist", "DICOM",
        "Radiólogo", "Transcripción", "Validación", "Entrega",
    ]
    roles = [
        "Recepción", "Recepción", "Tecnólogo", "Tecnólogo",
        "Radiólogo", "Transcriptor", "Radiólogo", "Recepción",
    ]

    x, y = 36, 100
    box_w, box_h, gap = 148, 78, 16
    for i, (step, role) in enumerate(zip(steps, roles)):
        bx0, by0 = x, y
        bx1, by1 = bx0 + box_w, by0 + box_h
        fill = COLOR_PRIMARY if i % 2 == 0 else COLOR_LIGHT
        text_color = COLOR_WHITE if i % 2 == 0 else COLOR_DARK
        _rounded_box(draw, (bx0, by0, bx1, by1), fill=fill, radius=14)
        _center_text(draw, (bx0, by0 + 4, bx1, by1 - 22), step, box_f, text_color)
        draw.text((bx0 + 10, by1 - 26), role, font=small_f, fill=text_color if i % 2 == 0 else COLOR_MUTED)
        if i < len(steps) - 1:
            ay = (by0 + by1) // 2
            draw.line((bx1 + 4, ay, bx1 + gap - 4, ay), fill=COLOR_MUTED, width=4)
            draw.polygon([(bx1 + gap - 4, ay), (bx1 + gap - 12, ay - 6), (bx1 + gap - 12, ay + 6)], fill=COLOR_MUTED)
        x += box_w + gap

    img.save(path, "PNG")


def draw_mapa_modulos(path: Path) -> None:
    w, h = 1200, 680
    img = Image.new("RGB", (w, h), COLOR_WHITE)
    draw = ImageDraw.Draw(img)
    title_f = _font(28, True)
    h_f = _font(20, True)
    b_f = _font(16)

    draw.text((40, 24), "Modulos y perfiles del sistema", font=title_f, fill=COLOR_DARK)

    modules = [
        ("Agenda", "Recepcionista", "Citacion, pagos, confirmacion"),
        ("Worklist", "Tecnologo", "Atencion en sala, insumos, DICOM"),
        ("Radiologo", "Medico radiologo", "Lectura, borrador, firma"),
        ("Transcripcion", "Transcriptor", "Audio dictado a informe"),
        ("Validacion", "Radiologo", "Revision y firma final"),
        ("Entrega/Retiro", "Recepcionista", "Retiro presencial o digital"),
        ("Administracion", "Administrador", "Usuarios, catalogo, reportes"),
        ("Dashboard", "Administrador", "KPIs operativos del dia"),
    ]

    cols, rows = 2, 4
    mw, mh, gx, gy = 540, 120, 30, 24
    x0, y0 = 40, 90
    for i, (mod, perfil, desc) in enumerate(modules):
        col, row = i % cols, i // cols
        x = x0 + col * (mw + gx)
        y = y0 + row * (mh + gy)
        _rounded_box(draw, (x, y, x + mw, y + mh), fill=COLOR_LIGHT)
        draw.text((x + 20, y + 16), mod, font=h_f, fill=COLOR_PRIMARY)
        draw.text((x + 20, y + 48), f"Perfil: {perfil}", font=b_f, fill=COLOR_DARK)
        draw.text((x + 20, y + 76), desc, font=b_f, fill=COLOR_MUTED)

    img.save(path, "PNG")


def draw_interfaz_shell(path: Path) -> None:
    w, h = 1200, 700
    img = Image.new("RGB", (w, h), COLOR_WHITE)
    draw = ImageDraw.Draw(img)
    title_f = _font(26, True)
    lbl = _font(15, True)
    small = _font(13)

    draw.text((40, 24), "Interfaz principal despues del login", font=title_f, fill=COLOR_DARK)

    # Browser frame
    draw.rounded_rectangle((40, 80, w - 40, h - 40), radius=18, outline=(200, 200, 200), width=2, fill=(250, 250, 250))
    draw.rectangle((40, 80, w - 40, 118), fill=(230, 230, 230))
    draw.text((60, 92), "HealthTiCloud RIS  |  Selector laboratorio  |  Usuario", font=small, fill=COLOR_DARK)

    # Sidebar
    draw.rectangle((40, 118, 230, h - 40), fill=(33, 37, 41))
    items = ["Agenda", "Worklist", "Radiologo", "Transcripcion", "Validacion", "Entrega", "Admin", "Dashboard"]
    yy = 150
    for item in items:
        color = COLOR_PRIMARY if item == "Agenda" else COLOR_WHITE
        draw.text((62, yy), item, font=small, fill=color)
        yy += 34

    # Content
    draw.rectangle((230, 118, w - 40, h - 40), fill=COLOR_WHITE)
    draw.text((260, 140), "Area de trabajo del modulo activo", font=lbl, fill=COLOR_DARK)
    draw.rounded_rectangle((260, 180, w - 80, 360), radius=12, outline=(222, 226, 230), width=2, fill=COLOR_LIGHT)
    draw.text((280, 210), "Calendario / tablas / formularios / modales", font=small, fill=COLOR_MUTED)
    draw.text((280, 240), "- Barra de busqueda y filtros", font=small, fill=COLOR_MUTED)
    draw.text((280, 268), "- Botones de accion (guardar, atender, firmar...)", font=small, fill=COLOR_MUTED)
    draw.text((280, 296), "- Notificaciones toast abajo a la derecha", font=small, fill=COLOR_MUTED)

    draw.text((260, 400), "Login: index.html  ->  layout.html + paginas en /pages", font=small, fill=COLOR_DARK)

    img.save(path, "PNG")


def draw_wizard_agenda(path: Path) -> None:
    w, h = 1200, 360
    img = Image.new("RGB", (w, h), COLOR_WHITE)
    draw = ImageDraw.Draw(img)
    title_f = _font(26, True)
    step_f = _font(18, True)
    desc_f = _font(14)

    draw.text((40, 24), "Wizard de agenda (4 pasos)", font=title_f, fill=COLOR_DARK)

    steps = [
        ("1. Paciente", "RUT, datos, prevision"),
        ("2. Cita", "Medicos, sala, procedencia"),
        ("3. Examenes", "Prestaciones e insumos"),
        ("4. Pago", "Metodo y montos"),
    ]
    x = 40
    for i, (title, desc) in enumerate(steps):
        bx1 = x + 260
        fill = COLOR_PRIMARY if i == 0 else COLOR_LIGHT
        txt = COLOR_WHITE if i == 0 else COLOR_DARK
        _rounded_box(draw, (x, 100, bx1, 250), fill=fill)
        _center_text(draw, (x, 120, bx1, 180), title, step_f, txt)
        _center_text(draw, (x, 180, bx1, 230), desc, desc_f, txt if i == 0 else COLOR_MUTED)
        if i < 3:
            draw.line((bx1 + 8, 175, bx1 + 28, 175), fill=COLOR_MUTED, width=3)
            draw.polygon([(bx1 + 28, 175), (bx1 + 20, 170), (bx1 + 20, 180)], fill=COLOR_MUTED)
        x += 280

    img.save(path, "PNG")


def draw_worklist(path: Path) -> None:
    w, h = 1200, 420
    img = Image.new("RGB", (w, h), COLOR_WHITE)
    draw = ImageDraw.Draw(img)
    title_f = _font(26, True)
    b_f = _font(16, True)
    s_f = _font(14)

    draw.text((40, 24), "Worklist - atencion tecnica", font=title_f, fill=COLOR_DARK)

    draw.rounded_rectangle((40, 90, w - 40, 160), radius=10, fill=COLOR_LIGHT, outline=(222, 226, 230))
    draw.text((60, 115), "Filtro sala  |  Buscar RUT  |  Actualizar", font=s_f, fill=COLOR_MUTED)

    headers = ["Hora", "Paciente", "Estudios", "Sala", "Estado", "Accion"]
    col_x = [60, 170, 420, 700, 860, 1020]
    y = 190
    for hx, head in zip(col_x, headers):
        draw.text((hx, y), head, font=b_f, fill=COLOR_DARK)
    draw.line((40, 220, w - 40, 220), fill=(222, 226, 230), width=2)

    rows = [
        ("09:00", "Juan Perez", "Rx Torax", "Sala 1", "Confirmado", "Atender"),
        ("09:30", "Maria Lopez", "Eco abdominal", "Sala 2", "DICOM enviado", "Atender"),
    ]
    y = 235
    for row in rows:
        for hx, val in zip(col_x, row):
            color = COLOR_PRIMARY if val == "Atender" else COLOR_DARK
            draw.text((hx, y), val, font=s_f, fill=color)
        y += 34

    draw.rounded_rectangle((40, 330, w - 40, 390), radius=10, fill=(255, 248, 230), outline=(255, 193, 7))
    draw.text((60, 348), "Modal Atencion: insumos -> Enviar DICOM -> Completar atencion", font=s_f, fill=COLOR_DARK)

    img.save(path, "PNG")


def draw_estados_agenda(path: Path) -> None:
    w, h = 1200, 280
    img = Image.new("RGB", (w, h), COLOR_WHITE)
    draw = ImageDraw.Draw(img)
    title_f = _font(24, True)
    s_f = _font(14, True)

    draw.text((40, 20), "Estados visuales en la agenda", font=title_f, fill=COLOR_DARK)

    states = [
        ("Pre-agendado", (139, 92, 246)),
        ("Agendado", (16, 185, 129)),
        ("Confirmado", (59, 130, 246)),
        ("En espera", (245, 158, 11)),
        ("Anulado", (239, 68, 68)),
        ("Atendido", (128, 128, 128)),
    ]
    x = 40
    for label, color in states:
        draw.rounded_rectangle((x, 90, x + 170, 170), radius=12, fill=color)
        _center_text(draw, (x, 90, x + 170, 170), label, s_f, COLOR_WHITE)
        x += 190

    img.save(path, "PNG")


def generate_all() -> dict[str, Path]:
    ASSETS.mkdir(parents=True, exist_ok=True)
    mapping = {
        "flujo_clinico": ASSETS / "flujo_clinico.png",
        "mapa_modulos": ASSETS / "mapa_modulos.png",
        "interfaz_shell": ASSETS / "interfaz_shell.png",
        "wizard_agenda": ASSETS / "wizard_agenda.png",
        "worklist": ASSETS / "worklist.png",
        "estados_agenda": ASSETS / "estados_agenda.png",
    }
    draw_flujo_clinico(mapping["flujo_clinico"])
    draw_mapa_modulos(mapping["mapa_modulos"])
    draw_interfaz_shell(mapping["interfaz_shell"])
    draw_wizard_agenda(mapping["wizard_agenda"])
    draw_worklist(mapping["worklist"])
    draw_estados_agenda(mapping["estados_agenda"])
    return mapping


if __name__ == "__main__":
    files = generate_all()
    for name, p in files.items():
        print(f"{name}: {p}")

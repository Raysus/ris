#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Genera el instructivo PDF con capturas reales del sistema."""

from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

from fpdf import FPDF
from fpdf.enums import TableCellFillMode
from fpdf.fonts import FontFace
from PIL import Image as PILImage

from instructivo_content import TOC, render_instructivo
from pdf_assets import draw_flujo_clinico

ROOT = Path(__file__).resolve().parent
PROJECT = ROOT.parent
FRONTEND = PROJECT / "frontend"
SCREENSHOTS = ROOT / "assets" / "screenshots"
ASSETS = ROOT / "assets"
OUTPUT = ROOT / "INSTRUCTIVO_HealthTiCloud_RIS.pdf"

FONT_DIR = Path(os.environ.get("WINDIR", "C:/Windows")) / "Fonts"
FONT_REGULAR = FONT_DIR / "arial.ttf"
FONT_BOLD = FONT_DIR / "arialbd.ttf"
FONT_ITALIC = FONT_DIR / "ariali.ttf"


class InstructivoPDF(FPDF):
    def __init__(self) -> None:
        super().__init__(format="A4", unit="mm")
        self.set_auto_page_break(auto=True, margin=18)
        self.set_margins(14, 16, 14)
        self._section_title = ""

        if FONT_REGULAR.exists():
            self.add_font("Arial", "", str(FONT_REGULAR))
            self.add_font("Arial", "B", str(FONT_BOLD))
            self.add_font("Arial", "I", str(FONT_ITALIC))
            self._font = "Arial"
        else:
            self._font = "Helvetica"

    def _set(self, style: str = "", size: int = 11) -> None:
        self.set_font(self._font, style, size)

    def header(self) -> None:
        if self.page_no() == 1:
            return
        self._set("I", 8)
        self.set_text_color(110, 110, 110)
        self.cell(self.epw / 2, 5, "HealthTiCloud RIS")
        self.cell(self.epw / 2, 5, self._section_title, align="R", new_x="LMARGIN", new_y="NEXT")
        self.set_draw_color(91, 74, 130)
        self.line(self.l_margin, self.get_y() + 1, self.w - self.r_margin, self.get_y() + 1)
        self.ln(4)
        self.set_text_color(0, 0, 0)

    def footer(self) -> None:
        self.set_y(-12)
        self._set("I", 8)
        self.set_text_color(130, 130, 130)
        self.cell(0, 6, f"Página {self.page_no()}", align="C")

    def cover(self, banner: Path | None = None) -> None:
        self.add_page()
        self.ln(16)
        self._set("B", 24)
        self.set_text_color(91, 74, 130)
        self.cell(0, 10, "HealthTiCloud RIS", align="C", new_x="LMARGIN", new_y="NEXT")
        self.ln(2)
        self._set("", 13)
        self.set_text_color(40, 40, 40)
        self.multi_cell(0, 7, "Manual de usuario\nInstructivo por perfiles y flujo clínico", align="C")
        self.ln(6)
        if banner and banner.exists():
            self.figure(banner, "", max_height=52, caption_below=False)
        self._set("", 10)
        self.set_text_color(100, 100, 100)
        self.cell(0, 6, "Sistema de Información Radiológica  ·  Junio 2026", align="C")

    def toc(self, items: list[str]) -> None:
        self.add_page()
        self._section_title = "Índice"
        self._set("B", 16)
        self.cell(0, 8, "Índice", new_x="LMARGIN", new_y="NEXT")
        self.ln(2)
        self._set("", 11)
        for title in items:
            self.cell(0, 6, title, new_x="LMARGIN", new_y="NEXT")

    def chapter(self, number: str, title: str) -> None:
        self.add_page()
        self._section_title = title
        self._set("B", 16)
        self.set_text_color(91, 74, 130)
        self.multi_cell(self.epw, 8, f"{number}. {title}")
        self.set_text_color(0, 0, 0)
        self.ln(2)

    def h2(self, text: str) -> None:
        self.ln(1)
        self._set("B", 12)
        self.multi_cell(self.epw, 6, text)
        self.ln(1)

    def h3(self, text: str) -> None:
        self._set("B", 11)
        self.multi_cell(self.epw, 5.5, text)

    def p(self, text: str) -> None:
        self._set("", 10.5)
        self.multi_cell(self.epw, 5, text)
        self.ln(1)

    def bullets(self, items: list[str]) -> None:
        self._set("", 10.5)
        for item in items:
            self.multi_cell(self.epw, 5, f"  •  {item}")
        self.ln(1)

    def steps(self, items: list[str]) -> None:
        self._set("", 10.5)
        for i, item in enumerate(items, 1):
            self.multi_cell(self.epw, 5, f"  {i}. {item}")
        self.ln(1)

    def figure(
        self,
        path: Path,
        caption: str,
        max_height: float = 88,
        caption_below: bool = True,
    ) -> None:
        if not path.exists():
            return

        with PILImage.open(path) as img:
            w_px, h_px = img.size
        aspect = h_px / w_px if w_px else 0.56

        img_w = self.epw
        img_h = img_w * aspect
        if img_h > max_height:
            img_h = max_height
            img_w = img_h / aspect

        needed = img_h + (8 if caption and caption_below else 2)
        if self.get_y() + needed > self.h - 16:
            self.add_page()

        x = self.l_margin + (self.epw - img_w) / 2
        y_before = self.get_y()
        self.image(str(path), x=x, y=y_before, w=img_w, h=img_h)
        self.set_y(y_before + img_h + 1)

        if caption and caption_below:
            self._set("I", 10)
            self.set_text_color(70, 70, 70)
            self.multi_cell(self.epw, 4.5, caption, align="C")
            self.set_text_color(0, 0, 0)
        self.ln(2)

    def render_table(
        self,
        headers: list[str],
        rows: list[list[str]],
        col_widths: tuple[float, ...] | None = None,
    ) -> None:
        if self.get_y() > self.h - 45:
            self.add_page()

        n = len(headers)
        if not col_widths:
            base = self.epw / n
            col_widths = tuple(base for _ in headers)

        head_style = FontFace(
            family=self._font,
            emphasis="BOLD",
            color=(255, 255, 255),
            fill_color=(91, 74, 130),
        )
        body_style = FontFace(family=self._font, size_pt=10)

        with self.table(
            width=self.epw,
            col_widths=col_widths,
            line_height=5.5,
            text_align=("LEFT",) * n,
            cell_fill_color=(248, 252, 251),
            cell_fill_mode=TableCellFillMode.ROWS,
        ) as table:
            hdr = table.row()
            for h in headers:
                hdr.cell(h, style=head_style)
            for row in rows:
                data_row = table.row()
                for cell in row:
                    data_row.cell(cell, style=body_style)
        self.ln(2)


def ensure_screenshots() -> dict[str, Path]:
    """Intenta capturar pantallas reales; devuelve mapa nombre -> path."""
    SCREENSHOTS.mkdir(parents=True, exist_ok=True)
    config_js = FRONTEND / "js" / "config.js"
    if not config_js.exists():
        config_js.write_text("const API_URL = 'http://127.0.0.1:8000/api';\n", encoding="utf-8")

    expected = list(SCREENSHOTS.glob("*.png"))
    if len(expected) < 8:
        print("Capturando pantallas reales con Playwright...")
        try:
            subprocess.run(
                ["npx", "--yes", "playwright", "install", "chromium"],
                cwd=str(PROJECT),
                check=False,
                capture_output=True,
            )
            subprocess.run(
                ["node", str(ROOT / "capture_screenshots.mjs")],
                cwd=str(PROJECT),
                check=True,
            )
        except (subprocess.CalledProcessError, FileNotFoundError) as exc:
            print(f"Advertencia: no se pudieron capturar pantallas ({exc}).", file=sys.stderr)

    mapping: dict[str, Path] = {}
    for p in sorted(SCREENSHOTS.glob("*.png")):
        mapping[p.stem] = p
    return mapping


def build_pdf(shots: dict[str, Path], flujo: Path) -> InstructivoPDF:
    pdf = InstructivoPDF()
    pdf.cover(shots.get("02_layout_agenda") or shots.get("03_modulo_agenda"))
    pdf.toc(TOC)
    render_instructivo(pdf, shots, flujo)
    return pdf


def main() -> int:
    ASSETS.mkdir(parents=True, exist_ok=True)
    flujo = ASSETS / "flujo_clinico.png"
    draw_flujo_clinico(flujo)

    shots = ensure_screenshots()
    if not shots:
        print("Error: sin capturas. Sirva el frontend en :5500 y el backend en :8000.", file=sys.stderr)
        return 1

    print(f"Usando {len(shots)} capturas reales.")
    pdf = build_pdf(shots, flujo)
    pdf.output(str(OUTPUT))
    print(f"PDF generado: {OUTPUT} ({pdf.page_no()} páginas)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

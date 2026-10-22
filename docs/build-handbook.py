#!/usr/bin/env python3
"""Render docs/HANDBOOK.md to docs/Campus-Handbook.pdf.

The handbook is written in a deliberately small Markdown subset so this
renderer stays readable:

  # Title                cover title (first block only)
  ## Section             numbered section, starts a new page
  ### Subsection         numbered subsection
  - bullet               1. numbered item
  > callout              tinted box with an ember rule
  ``` fenced code ```    monospace block, kept verbatim
  | a | b |              pipe table with a |---|---| separator row
  <!-- page -->          explicit page break
  `code` and **bold**    inline formatting

Usage:  python3 docs/build-handbook.py
Needs:  pip install reportlab
"""

from __future__ import annotations

import html
import re
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    NextPageTemplate,
    PageBreak,
    PageTemplate,
    Paragraph,
    Preformatted,
    Spacer,
    Table,
    TableStyle,
)

ROOT = Path(__file__).resolve().parent
SOURCE = ROOT / "HANDBOOK.md"
TARGET = ROOT / "Campus-Handbook.pdf"

INK = colors.HexColor("#24201D")
MUTED = colors.HexColor("#6A605A")
EMBER = colors.HexColor("#C96F2D")
EMBER_SOFT = colors.HexColor("#F59A55")
LINE = colors.HexColor("#E4D8CC")
WASH = colors.HexColor("#FFF0E3")
CODE_BG = colors.HexColor("#FAF4EE")
NIGHT = colors.HexColor("#0B0C0E")
NIGHT_SOFT = colors.HexColor("#16181B")

PAGE_W, PAGE_H = A4
MARGIN_X = 20 * mm
MARGIN_TOP = 20 * mm
MARGIN_BOTTOM = 18 * mm
CONTENT_W = PAGE_W - 2 * MARGIN_X

# Characters reportlab's built-in fonts cannot draw; map them to plain ASCII.
SUBSTITUTIONS = {
    "\u2014": " - ",
    "\u2013": "-",
    "\u2018": "'",
    "\u2019": "'",
    "\u201c": '"',
    "\u201d": '"',
    "\u2192": "->",
    "\u2190": "<-",
    "\u2022": "-",
    "\u2026": "...",
    "\u00a0": " ",
    "\u2265": ">=",
    "\u2264": "<=",
    "\u2248": "about ",
    "\u00d7": "x",
    "\u2713": "yes",
    "\u2717": "no",
}


def clean(text: str) -> str:
    for bad, good in SUBSTITUTIONS.items():
        text = text.replace(bad, good)
    return text


def inline(text: str) -> str:
    """Escape for reportlab paragraphs, then apply `code` and **bold**."""
    text = clean(text.strip())
    text = html.escape(text, quote=False)
    text = re.sub(r"`([^`]+)`", r'<font face="Courier" size="8.4" color="#8A4B1E">\1</font>', text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", text)
    return text


styles = getSampleStyleSheet()
body = ParagraphStyle(
    "body", parent=styles["BodyText"], fontName="Helvetica", fontSize=9.7, leading=14.2,
    textColor=INK, alignment=TA_LEFT, spaceAfter=6.5,
)
bullet = ParagraphStyle("bullet", parent=body, leftIndent=13, bulletIndent=2, spaceAfter=3.2)
h2 = ParagraphStyle(
    "h2", parent=styles["Heading1"], fontName="Helvetica-Bold", fontSize=17, leading=21,
    textColor=INK, spaceBefore=2, spaceAfter=10,
)
h3 = ParagraphStyle(
    "h3", parent=styles["Heading2"], fontName="Helvetica-Bold", fontSize=11.6, leading=15,
    textColor=EMBER, spaceBefore=11, spaceAfter=4,
)
code = ParagraphStyle("code", parent=styles["Code"], fontName="Courier", fontSize=7.9, leading=10.4,
                      textColor=INK, leftIndent=0, firstLineIndent=0, spaceBefore=0, spaceAfter=0)
cell = ParagraphStyle("cell", parent=body, fontSize=8.5, leading=11.2, spaceAfter=0)
cell_head = ParagraphStyle("cell_head", parent=cell, fontName="Helvetica-Bold", textColor=colors.HexColor("#8A3F12"))
cover_title = ParagraphStyle("cover_title", parent=body, fontName="Helvetica-Bold", fontSize=34, leading=38,
                             textColor=colors.white, spaceAfter=6)
cover_sub = ParagraphStyle("cover_sub", parent=body, fontSize=13.5, leading=19, textColor=EMBER_SOFT)
cover_meta = ParagraphStyle("cover_meta", parent=body, fontSize=9.2, leading=15, textColor=colors.HexColor("#C9C2BB"))
toc_item = ParagraphStyle("toc", parent=body, fontSize=9.6, leading=16, textColor=colors.HexColor("#E8E2DC"))


def footer(canvas, doc):
    canvas.saveState()
    canvas.setStrokeColor(LINE)
    canvas.setLineWidth(0.6)
    canvas.line(MARGIN_X, MARGIN_BOTTOM - 6, PAGE_W - MARGIN_X, MARGIN_BOTTOM - 6)
    canvas.setFont("Helvetica", 7.8)
    canvas.setFillColor(MUTED)
    canvas.drawString(MARGIN_X, MARGIN_BOTTOM - 16, "Campus Placement Portal - User handbook")
    canvas.drawRightString(PAGE_W - MARGIN_X, MARGIN_BOTTOM - 16, str(doc.page))
    canvas.restoreState()


def cover(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(NIGHT)
    canvas.rect(0, 0, PAGE_W, PAGE_H, stroke=0, fill=1)
    canvas.setFillColor(NIGHT_SOFT)
    canvas.rect(0, PAGE_H - 150, PAGE_W, 150, stroke=0, fill=1)
    canvas.setFillColor(EMBER)
    canvas.rect(MARGIN_X, PAGE_H - 152, 74, 3.4, stroke=0, fill=1)
    canvas.restoreState()


def cover_page(sections: list[str]):
    story = [Spacer(1, 92 * mm)]
    story.append(Paragraph("Campus", cover_title))
    story.append(Paragraph("Placement Portal", cover_title))
    story.append(Spacer(1, 6))
    story.append(Paragraph("User handbook - install, run and use every workspace", cover_sub))
    story.append(Spacer(1, 14))
    story.append(Paragraph(
        "Version 2.0.0 &nbsp;|&nbsp; 22 October 2026 &nbsp;|&nbsp; PHP 8.0+ (8.2 recommended), MySQL/MariaDB",
        cover_meta,
    ))
    story.append(Paragraph("github.com/shradhayjoshi1921-dotcom/Campus", cover_meta))
    story.append(Spacer(1, 20))
    story.append(Paragraph("Contents", ParagraphStyle(
        "cover_contents", parent=cover_meta, fontName="Helvetica-Bold", fontSize=8.6,
        textColor=EMBER_SOFT, spaceAfter=6,
    )))
    for number, title in enumerate(sections, start=1):
        story.append(Paragraph(f"{number:02d}&nbsp;&nbsp;&nbsp;{inline(title)}", toc_item))
    return story


def table_block(rows: list[list[str]]):
    header, body_rows = rows[0], rows[1:]
    widths = [0.0] * len(header)
    for row in rows:
        for index, value in enumerate(row):
            if index >= len(widths):
                continue
            plain = re.sub(r"[`*]", "", value)
            longest_word = max((len(word) for word in plain.split()), default=4)
            widths[index] = max(widths[index], min(longest_word + 4, 30), min(len(plain) / 3.4, 30))
    widths = [max(9.0, value) for value in widths]
    total = sum(widths) or 1.0
    col_widths = [CONTENT_W * value / total for value in widths]
    # Do not let a wide last column starve the first one.
    col_widths = [max(50.0, width) for width in col_widths]
    scale = CONTENT_W / sum(col_widths)
    col_widths = [width * scale for width in col_widths]

    data = [[Paragraph(inline(value), cell_head) for value in header]]
    data += [[Paragraph(inline(value), cell) for value in row] for row in body_rows]
    table = Table(data, colWidths=col_widths, repeatRows=1, hAlign="LEFT")
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), WASH),
        ("TEXTCOLOR", (0, 0), (-1, 0), colors.HexColor("#8A3F12")),
        ("GRID", (0, 0), (-1, -1), 0.5, LINE),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("TOPPADDING", (0, 0), (-1, -1), 3.6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3.6),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#FCFAF7")]),
    ]))
    return table


def wrap_code(lines: list[str], width: int = 96) -> list[str]:
    """Soft-wrap long code lines so nothing is clipped in the PDF.

    Breaks only at spaces, which keeps SQL, PHP and shell meaningful. A wrapped
    comment keeps its marker so the printed snippet still runs if copied.
    """
    out: list[str] = []
    for line in lines:
        if len(line) <= width:
            out.append(line)
            continue
        for marker in (":: ", "# ", "-- "):
            if line.startswith(marker):
                prefix, body = marker, line[len(marker):]
                break
        else:
            prefix, body = "", line
        words = body.split(" ")
        current = prefix
        for word in words:
            candidate = (current + " " + word) if current.strip() else (prefix + word if prefix else word)
            if len(candidate) > width and current.strip() not in ("", prefix.strip()):
                out.append(current.rstrip())
                current = " " * len(prefix) + word
            else:
                current = candidate
        out.append(current.rstrip())
    return out


def code_block(lines: list[str]):
    lines = wrap_code(lines)
    text = "\n".join(lines) if lines else " "
    pre = Preformatted(clean(text), code)
    box = Table([[pre]], colWidths=[CONTENT_W], hAlign="LEFT")
    box.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), CODE_BG),
        ("LINEBEFORE", (0, 0), (0, -1), 2.2, EMBER_SOFT),
        ("BOX", (0, 0), (-1, -1), 0.5, LINE),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return KeepTogether([Spacer(1, 3), box, Spacer(1, 7)])


def callout(text: str):
    box = Table([[Paragraph(inline(text), ParagraphStyle("callout", parent=body, fontSize=9.2, leading=13))]],
                colWidths=[CONTENT_W])
    box.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), WASH),
        ("LINEBEFORE", (0, 0), (0, -1), 2.4, EMBER),
        ("LEFTPADDING", (0, 0), (-1, -1), 9),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return KeepTogether([Spacer(1, 2), box, Spacer(1, 7)])


def parse(markdown: str):
    lines = markdown.splitlines()
    blocks = []
    index = 0
    while index < len(lines):
        line = lines[index]
        stripped = line.strip()

        if stripped == "<!-- page -->":
            blocks.append(("page", None))
            index += 1
            continue
        if stripped.startswith("```"):
            index += 1
            buffer = []
            while index < len(lines) and not lines[index].strip().startswith("```"):
                buffer.append(lines[index])
                index += 1
            index += 1
            blocks.append(("code", buffer))
            continue
        if stripped.startswith("# "):
            blocks.append(("title", stripped[2:]))
            index += 1
            continue
        if stripped.startswith("## "):
            blocks.append(("h2", stripped[3:]))
            index += 1
            continue
        if stripped.startswith("### "):
            blocks.append(("h3", stripped[4:]))
            index += 1
            continue
        if stripped.startswith("> "):
            blocks.append(("callout", stripped[2:]))
            index += 1
            continue
        if stripped.startswith("|"):
            rows = []
            while index < len(lines) and lines[index].strip().startswith("|"):
                row = lines[index].strip()
                index += 1
                if re.fullmatch(r"\|[-:\s|]+\|", row):
                    continue
                cells = [value.strip() for value in row.strip("|").split("|")]
                rows.append(cells)
            if rows:
                blocks.append(("table", rows))
            continue
        if re.match(r"^[-*] ", stripped):
            blocks.append(("bullet", stripped[2:]))
            index += 1
            continue
        if re.match(r"^\d+\. ", stripped):
            blocks.append(("number", stripped))
            index += 1
            continue
        if stripped == "":
            index += 1
            continue
        blocks.append(("para", stripped))
        index += 1
    return blocks


def build():
    blocks = parse(clean(SOURCE.read_text(encoding="utf-8")))
    titles = [value for kind, value in blocks if kind == "h2"]
    cover_title_text = next((value for kind, value in blocks if kind == "title"), "Campus")

    doc = BaseDocTemplate(
        str(TARGET), pagesize=A4,
        leftMargin=MARGIN_X, rightMargin=MARGIN_X, topMargin=MARGIN_TOP, bottomMargin=MARGIN_BOTTOM,
        title="Campus Placement Portal - User handbook", author="Campus project",
        subject="Install, run and use the Campus placement portal, with example data",
    )
    frame = Frame(MARGIN_X, MARGIN_BOTTOM, CONTENT_W, PAGE_H - MARGIN_TOP - MARGIN_BOTTOM, id="body")
    cover_frame = Frame(MARGIN_X, 0, PAGE_W - 2 * MARGIN_X, PAGE_H, id="cover",
                        leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
    doc.addPageTemplates([
        PageTemplate(id="cover", frames=[cover_frame], onPage=cover),
        PageTemplate(id="body", frames=[frame], onPage=footer),
    ])

    story = cover_page(titles)
    story.append(NextPageTemplate("body"))
    story.append(PageBreak())

    section = 0
    subsection = 0
    seen_any_section = False
    for kind, value in blocks:
        if kind == "title":
            continue
        if kind == "h2":
            section += 1
            subsection = 0
            if seen_any_section:
                story.append(PageBreak())
            seen_any_section = True
            story.append(Paragraph(f"{section}. {inline(value)}", h2))
            continue
        if kind == "h3":
            subsection += 1
            story.append(Paragraph(f"{section}.{subsection} {inline(value)}", h3))
            continue
        if kind == "page":
            story.append(PageBreak())
            continue
        if kind == "code":
            story.append(code_block(value))
            continue
        if kind == "callout":
            story.append(callout(value))
            continue
        if kind == "table":
            story.append(Spacer(1, 2))
            story.append(table_block(value))
            story.append(Spacer(1, 9))
            continue
        if kind == "bullet":
            story.append(Paragraph(inline(value), bullet, bulletText="\u2022"))
            continue
        if kind == "number":
            number, text = value.split(" ", 1)
            story.append(Paragraph(inline(text), bullet, bulletText=number))
            continue
        story.append(Paragraph(inline(value), body))

    doc.build(story)
    print(f"wrote {TARGET.relative_to(ROOT.parent)} ({TARGET.stat().st_size // 1024} KB)")
    print(f"cover title: {cover_title_text} | sections: {len(titles)}")


if __name__ == "__main__":
    build()

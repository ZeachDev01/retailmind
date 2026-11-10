from pathlib import Path
import re
import json
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

BASE = Path(__file__).resolve().parent
OUT = Path('C:/Users/Mike/Documents/Capstone 2 Docu/RetailMind Capstone Adjustment Guide.docx')
text = (BASE / 'adjustments.md').read_text(encoding='utf8')
pages = text.split('[[PAGE]]')
assert len(pages) == 17
assert len(re.findall(r'^## A\d\d ', text, re.M)) == 45

doc = Document()
sec = doc.sections[0]
sec.page_width, sec.page_height = Inches(8.27), Inches(11.69)
sec.top_margin, sec.bottom_margin = Inches(.67), Inches(.67)
sec.left_margin, sec.right_margin = Inches(.75), Inches(.75)
sec.footer_distance = Inches(.30)
normal = doc.styles['Normal']
normal.font.name = 'Calibri'
normal.font.size = Pt(10.5)
normal.font.color.rgb = RGBColor(0, 0, 0)
normal.paragraph_format.space_after = Pt(6)
normal.paragraph_format.line_spacing = 1.10
for name, size in [('Title', 24), ('Heading 1', 19), ('Heading 2', 12)]:
    sty = doc.styles[name]
    sty.font.name = 'Calibri'
    sty.font.size = Pt(size)
    sty.font.color.rgb = RGBColor(0, 0, 0)
    sty.font.bold = True
    sty.font.underline = False
    sty.paragraph_format.space_before = Pt(10 if name == 'Heading 2' else 0)
    sty.paragraph_format.space_after = Pt(6 if name == 'Heading 2' else 11)
    sty.paragraph_format.keep_with_next = True
    sty.paragraph_format.page_break_before = False
    for child in list(sty.element):
        if child.tag == qn('w:pPr'):
            borders = child.find(qn('w:pBdr'))
            if borders is not None:
                child.remove(borders)
for name in ['List Bullet', 'List Paragraph']:
    doc.styles[name].font.name = 'Calibri'
    doc.styles[name].font.size = Pt(10.5)
    doc.styles[name].paragraph_format.space_after = Pt(4)
    doc.styles[name].paragraph_format.line_spacing = 1.08

def inline(p, content):
    for n, part in enumerate(re.split(r'\*\*(.*?)\*\*', content)):
        if not part:
            continue
        run = p.add_run(part)
        run.bold = bool(n % 2)

def table(rows):
    cols = len(rows[0])
    t = doc.add_table(rows=0, cols=cols)
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    t.autofit = False
    widths = {
        2: [2.30, 4.47],
        3: [0.65, 3.55, 2.57],
        4: [0.50, 1.37, 1.55, 3.35],
        5: [1.0, 1.45, 1.45, 1.70, 1.17],
        6: [1.00, 1.10, 1.10, 1.27, 1.10, 1.20],
    }[cols]
    # Text-heavy comparisons need room for their first column.
    if cols == 3 and rows[0][0] == 'Horizon':
        widths = [1.1, 2.0, 3.67] if rows[0][-1] == 'Lower error' else [1.15, 2.45, 3.17]
    if cols == 4 and rows[0][0] == 'Horizon':
        widths = [1.05, 1.30, 1.60, 2.82]
    if cols == 4 and rows[0][0] == 'Fold':
        widths = [.5, 1.55, 1.65, 3.07]
    for c, w in zip(t.columns, widths):
        c.width = Inches(w)
    pr = t._tbl.tblPr
    borders = OxmlElement('w:tblBorders')
    for edge in ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']:
        node = OxmlElement('w:' + edge)
        node.set(qn('w:val'), 'single')
        node.set(qn('w:sz'), '4')
        node.set(qn('w:color'), 'D9D9D9')
        borders.append(node)
    pr.append(borders)
    for ri, row in enumerate(rows):
        cells = t.add_row().cells
        trpr = t.rows[-1]._tr.get_or_add_trPr()
        no_split = OxmlElement('w:cantSplit')
        trpr.append(no_split)
        if ri == 0:
            repeat = OxmlElement('w:tblHeader')
            trpr.append(repeat)
        for ci, value in enumerate(row):
            cell = cells[ci]
            cell.width = Inches(widths[ci])
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            tcpr = cell._tc.get_or_add_tcPr()
            margins = OxmlElement('w:tcMar')
            for edge in ['top', 'left', 'bottom', 'right']:
                node = OxmlElement('w:' + edge)
                node.set(qn('w:w'), '80' if edge in ['left', 'right'] else '65')
                node.set(qn('w:type'), 'dxa')
                margins.append(node)
            tcpr.append(margins)
            shade = OxmlElement('w:shd')
            shade.set(qn('w:fill'), '203C56' if ri == 0 else ('F1F5F8' if ri % 2 == 0 else 'FFFFFF'))
            tcpr.append(shade)
            p = cell.paragraphs[0]
            p.paragraph_format.space_after = Pt(0)
            p.paragraph_format.space_before = Pt(0)
            p.paragraph_format.line_spacing = 1.06
            if rows[0][0] in ['Horizon', 'Fold'] or (cols == 3 and ci == 0):
                p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            inline(p, value)
            for run in p.runs:
                run.font.size = Pt(9.25)
                if ri == 0:
                    run.bold = True
                    run.font.color.rgb = RGBColor(255, 255, 255)
    spacer = doc.add_paragraph()
    spacer.paragraph_format.space_after = Pt(1)
    spacer.paragraph_format.line_spacing = Pt(3)
    spacer.paragraph_format.space_before = Pt(0)
    spacer.add_run().font.size = Pt(3)

for page_index, page in enumerate(pages):
    if page_index:
        doc.add_page_break()
    lines = page.strip().splitlines()
    i = 0
    while i < len(lines):
        line = lines[i].strip()
        if not line:
            i += 1
            continue
        if line.startswith('|'):
            rows = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                row = [x.strip() for x in lines[i].strip().strip('|').split('|')]
                if not all(re.fullmatch(r'[-: ]+', x) for x in row):
                    rows.append(row)
                i += 1
            table(rows)
            continue
        if line.startswith('# '):
            p = doc.add_paragraph(style='Title' if page_index == 0 else 'Heading 1')
            inline(p, line[2:])
        elif line.startswith('## '):
            p = doc.add_paragraph(style='Heading 2')
            inline(p, line[3:])
        elif line.startswith('- '):
            inline(doc.add_paragraph(style='List Bullet'), line[2:])
        else:
            p = doc.add_paragraph()
            inline(p, line)
            if page_index >= 15:
                p.paragraph_format.space_after = Pt(4)
                p.paragraph_format.line_spacing = 1.04
                for run in p.runs:
                    run.font.size = Pt(9.5)
        i += 1

footer = sec.footer.paragraphs[0]
footer.alignment = WD_ALIGN_PARAGRAPH.RIGHT
run = footer.add_run('RetailMind Capstone Adjustment Guide   |   ')
run.font.size = Pt(8)
field = OxmlElement('w:fldSimple')
field.set(qn('w:instr'), 'PAGE')
footer._p.append(field)
doc.core_properties.title = 'RetailMind Capstone Adjustment Guide'
doc.core_properties.subject = 'Required manuscript adjustments and agreed forecasting UI decisions'
doc.core_properties.author = 'RetailMind'
doc.core_properties.keywords = 'capstone, forecasting, manuscript revision'
OUT.parent.mkdir(parents=True, exist_ok=True)
doc.save(OUT)
assert OUT.is_file() and OUT.stat().st_size > 20000
check = Document(OUT)
assert len(check.tables) == 8, len(check.tables)
assert any('A45 Apply' in p.text for p in check.paragraphs)
summary = {'output': str(OUT), 'planned_pages': len(pages), 'tables': len(check.tables), 'words_by_page': [len(p.split()) for p in pages], 'paragraphs': len(check.paragraphs)}
(BASE/'build-check.json').write_text(json.dumps(summary, indent=2), encoding='utf8')
print(json.dumps(summary, indent=2))

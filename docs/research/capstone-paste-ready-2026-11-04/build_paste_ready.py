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
ROOT = BASE.parents[2]
OUT = Path('C:/Users/Mike/Documents/Capstone 2 Docu/RetailMind Ready to Paste Capstone Revisions.docx')
text = (BASE / 'paste-ready.md').read_text(encoding='utf8')
text = text.replace('| Chapter 2 and technical tools | 08 to 12 |', '| Chapter 2 and technical tools | 08 to 13 |')
text = text.replace('| Chapter 3 requirements and diagrams | 13 to 19 |', '| Chapter 3 requirements and diagrams | 14 to 19 |')

schema = (ROOT / 'src/backend/database/sql/schema.sql').read_text(encoding='utf8')
groups = [
    ('Account and Product fields', [
        ('roles', ['role_id','role_name']),
        ('user_roles', ['user_id','role_id','is_primary']),
        ('users', ['user_id','first_name','last_name','display_name','password_hash','status']),
        ('categories', ['category_id','category_name']),
        ('products', ['product_id','sku','product_name','category_id','unit_price','cost_price','reorder_level','safety_stock','base_unit','receiving_unit']),
    ]),
    ('Inventory and receiving fields', [
        ('inventory', ['inventory_id','product_id','quantity_on_hand']),
        ('product_batches', ['batch_id','product_id','receiving_id','remaining_quantity','expiration_date']),
        ('stock_receiving_groups', ['group_id','received_by','received_at']),
        ('stock_receiving', ['receiving_id','group_id','product_id','accepted_qty','cost_price']),
        ('inventory_counts', ['count_id','product_id','system_quantity','physical_quantity','status']),
        ('stock_movements', ['movement_id','product_id','change_qty','moved_at']),
    ]),
    ('Sales and review fields', [
        ('registers', ['register_id','name','paper_width_mm']),
        ('cashier_shifts', ['shift_id','cashier_id','register_id','opened_at','cash_variance']),
        ('sales', ['sale_id','cashier_id','shift_id','total_amount','sale_date']),
        ('sale_items', ['sale_item_id','sale_id','product_id','quantity','unit_price']),
        ('cash_refunds', ['refund_id','sale_id','refund_amount','created_at']),
        ('inventory_adjustments', ['adjustment_id','product_id','adjustment_qty','status']),
    ]),
    ('Forecasting fields', [
        ('ml_jobs', ['job_id','trigger_type','status','requester_id','queued_at']),
        ('model_training_runs', ['training_run_id','model_version','status']),
        ('ml_model_evaluations', ['evaluation_id','model_version','horizon_days','wape','baseline_wape','candidate_gate_outcome']),
        ('demand_forecasts', ['forecast_id','job_id','product_id','forecast_origin','target_date','horizon_day','predicted_units','model_version']),
        ('forecast_sales_imports', ['product_id','sale_date','quantity']),
        ('store_calendar', ['calendar_date','status']),
    ]),
    ('Audit settings and GCash fields', [
        ('activity_log', ['log_id','user_id','action','created_at']),
        ('store_settings', ['setting_key','setting_value']),
        ('platform_settings', ['setting_key','setting_value']),
        ('ml_settings', ['setting_key','setting_value']),
        ('gcash_service_sessions', ['session_id','shift_id','cashier_id','opening_service_cash','opening_wallet_balance']),
        ('gcash_transactions', ['transaction_id','session_id','transaction_type','reference_number','principal_amount','fee_amount']),
    ]),
]
row_total = 0
for index, (heading, entities) in enumerate(groups, 1):
    lines = ['[[PAGE]]', '# Selected Core Data Dictionary', f'## {heading}',
             '@Asa ibutang: Human sa Figure 12 ERD narrative, ipuli sa outdated Table 5 data dictionary. Kopyaha ang tanan nga lima ka dictionary parts isip usa ka selected dictionary; ayaw apila ang placement labels.',
             '@Text to paste']
    if index == 1:
        lines.append('The following selected fields describe core entities in the implemented database schema. PK denotes a declared primary key and FK denotes a declared foreign key. A dash indicates that neither designation is shown for that selected field. Types are reproduced from the schema; fields omitted from this selected view remain part of the implementation. Password hashes are not recoverable encrypted passwords.')
    lines += ['| Entity | Field | SQL type | Key |']
    for entity, fields in entities:
        m = re.search(r'CREATE TABLE (?:IF NOT EXISTS )?`?' + re.escape(entity) + r'`?\s*\((.*?)\) ENGINE', schema, re.S)
        assert m, entity
        body = m.group(1)
        pm = re.search(r'PRIMARY KEY\s*\(([^)]+)\)', body, re.I)
        primary = set(re.findall(r'`?(\w+)`?', pm.group(1))) if pm else set()
        foreign = set()
        for fm in re.finditer(r'FOREIGN KEY\s*\(([^)]+)\)', body, re.I):
            foreign.update(re.findall(r'`?(\w+)`?', fm.group(1)))
        for field in fields:
            cm = re.search(r'^\s*`?' + re.escape(field) + r'`?\s+([a-zA-Z]+(?:\([^)]*\))?(?:\s+unsigned)?)([^\n]*)', body, re.M | re.I)
            assert cm, (entity, field)
            sqltype, suffix = cm.groups()
            ispk = field in primary or 'PRIMARY KEY' in suffix.upper()
            key = '/'.join(k for k, test in [('PK', ispk), ('FK', field in foreign)] if test) or '-'
            lines.append(f'| {entity} | {field} | {sqltype.strip()} | {key} |')
            row_total += 1
    text += '\n\n' + '\n'.join(lines)

pages = text.split('[[PAGE]]')
assert len(re.findall(r'^## \d\d ', text, re.M)) == 52
doc = Document()
sec = doc.sections[0]
sec.page_width, sec.page_height = Inches(8.27), Inches(11.69)
sec.top_margin, sec.bottom_margin = Inches(.65), Inches(.65)
sec.left_margin, sec.right_margin = Inches(.72), Inches(.72)
sec.footer_distance = Inches(.28)
for name in ['Normal', 'List Bullet', 'List Number']:
    st = doc.styles[name]
    st.font.name = 'Times New Roman'
    st.font.size = Pt(11)
    st.font.color.rgb = RGBColor(0, 0, 0)
    st.paragraph_format.line_spacing = 1.08
    st.paragraph_format.space_after = Pt(6 if name == 'Normal' else 3)
for name, size in [('Title', 24), ('Heading 1', 18), ('Heading 2', 12)]:
    st = doc.styles[name]
    st.font.name = 'Calibri'
    st.font.size = Pt(size)
    st.font.bold = True
    st.font.color.rgb = RGBColor(0, 0, 0)
    st.font.underline = False
    st.paragraph_format.space_before = Pt(8 if name == 'Heading 2' else 0)
    st.paragraph_format.space_after = Pt(7 if name == 'Heading 2' else 10)
    st.paragraph_format.keep_with_next = True
    st.paragraph_format.page_break_before = False

def inline(p, content):
    for i, segment in enumerate(re.split(r'\*\*(.*?)\*\*', content)):
        if segment:
            run = p.add_run(segment)
            if i % 2:
                run.bold = True

def table(rows, dictionary=False):
    n = len(rows[0])
    widths = {2:[2.0,4.83],3:[1.50,2.48,2.85],4:[1.35,1.65,1.6,2.23],5:[1.1,1.4,1.4,1.6,1.33],6:[.92,1.05,1.10,1.28,1.10,1.38],8:[1.28,.55,.55,.55,.55,1.15,1.10,1.10]}[n]
    if dictionary:
        widths = [1.53,2.18,2.55,.57]
    if rows[0][0] == 'Fold':
        widths = [1.10,1.57,1.60,2.56]
    if rows[0][0] == 'Feature group':
        widths = [1.40,2.47,.48,2.48]
    if rows[0][0] == 'Test ID':
        widths = [.58,3.05,1.55,1.65]
    if rows[0][0] == 'Horizon' and n == 4:
        widths = [1.0,1.70,1.65,2.48]
    t = doc.add_table(rows=0, cols=n)
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    t.autofit = False
    for c,w in zip(t.columns,widths):
        c.width = Inches(w)
    borders = OxmlElement('w:tblBorders')
    for edge in ['top','left','bottom','right','insideH','insideV']:
        e = OxmlElement('w:'+edge)
        for k,v in [('val','single'),('sz','4'),('color','D9D9D9')]:
            e.set(qn('w:'+k),v)
        borders.append(e)
    t._tbl.tblPr.append(borders)
    for ri, row in enumerate(rows):
        cells = t.add_row().cells
        trpr = t.rows[-1]._tr.get_or_add_trPr()
        trpr.append(OxmlElement('w:cantSplit'))
        if ri == 0:
            trpr.append(OxmlElement('w:tblHeader'))
        for ci, value in enumerate(row):
            cell=cells[ci]
            cell.width=Inches(widths[ci])
            cell.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
            pr=cell._tc.get_or_add_tcPr()
            mar=OxmlElement('w:tcMar')
            for edge in ['top','left','bottom','right']:
                e=OxmlElement('w:'+edge)
                e.set(qn('w:w'),'70' if dictionary else '85')
                e.set(qn('w:type'),'dxa')
                mar.append(e)
            pr.append(mar)
            sh=OxmlElement('w:shd')
            sh.set(qn('w:fill'),'203C56' if ri==0 else ('F1F5F8' if ri%2==0 else 'FFFFFF'))
            pr.append(sh)
            p=cell.paragraphs[0]
            p.paragraph_format.space_after=Pt(0)
            p.paragraph_format.line_spacing=1.0
            if rows[0][0] in ['Horizon','Fold','Method'] or (dictionary and ci==3) or (rows[0][0]=='Feature group' and ci==2) or (rows[0][0]=='Test ID' and ci==0):
                p.alignment=WD_ALIGN_PARAGRAPH.CENTER
            inline(p,value)
            for run in p.runs:
                run.font.name='Calibri'
                run.font.size=Pt(8.8 if dictionary else 9.2)
                if ri==0:
                    run.bold=True
                    run.font.color.rgb=RGBColor(255,255,255)
    p=doc.add_paragraph()
    p.paragraph_format.line_spacing=Pt(3)
    p.paragraph_format.space_after=Pt(1)
    p.add_run().font.size=Pt(3)

for page_index,page in enumerate(pages):
    if page_index:
        doc.add_page_break()
    lines=page.strip().splitlines()
    dictionary=page.lstrip().startswith('# Selected Core Data Dictionary')
    i=0
    while i<len(lines):
        line=lines[i].strip()
        if not line:
            i+=1
            continue
        if line.startswith('|'):
            rows=[]
            while i<len(lines) and lines[i].strip().startswith('|'):
                row=[s.strip() for s in lines[i].strip().strip('|').split('|')]
                rows.append(row)
                i+=1
            assert all(len(r)==len(rows[0]) for r in rows)
            table(rows,dictionary)
            continue
        if line.startswith('# '):
            inline(doc.add_paragraph(style='Title' if page_index==0 else 'Heading 1'),line[2:])
        elif line.startswith('## '):
            inline(doc.add_paragraph(style='Heading 2'),line[3:])
        elif line.startswith('@'):
            p=doc.add_paragraph()
            p.paragraph_format.space_after=Pt(3)
            p.paragraph_format.line_spacing=1.0
            p.paragraph_format.keep_with_next=True
            run=p.add_run(line[1:])
            run.font.name='Calibri'
            run.font.size=Pt(9)
            run.font.color.rgb=RGBColor(80,80,80)
            if line=='@Text to paste':
                run.bold=True
                p.paragraph_format.space_before=Pt(3)
                p.paragraph_format.space_after=Pt(5)
        elif line.startswith('- '):
            inline(doc.add_paragraph(style='List Bullet'),line[2:])
        elif re.match(r'^\d+\. ',line):
            # Preserve explicit step numbers as exact copyable text.
            inline(doc.add_paragraph(),line)
        else:
            inline(doc.add_paragraph(),line)
        i+=1

footer=sec.footer.paragraphs[0]
footer.alignment=WD_ALIGN_PARAGRAPH.RIGHT
r=footer.add_run('RetailMind Paste Ready Revisions   |   ')
r.font.name='Calibri'
r.font.size=Pt(8)
fld=OxmlElement('w:fldSimple')
fld.set(qn('w:instr'),'PAGE')
footer._p.append(fld)
doc.core_properties.title='RetailMind Capstone Text Ready to Paste'
doc.core_properties.author='RetailMind'
doc.core_properties.subject='Replacement manuscript passages with exact placement instructions'
OUT.parent.mkdir(parents=True,exist_ok=True)
doc.save(OUT)
assert OUT.is_file() and OUT.stat().st_size>30000
reopened=Document(OUT)
assert any('52 Actual test environment' in p.text for p in reopened.paragraphs)
assert len(reopened.tables)>=15
summary={'output':str(OUT),'planned_pages':len(pages),'replacement_blocks':52,'dictionary_rows':row_total,'tables':len(reopened.tables),'words_by_page':[len(p.split()) for p in pages]}
(BASE/'build-check.json').write_text(json.dumps(summary,indent=2),encoding='utf8')
(BASE/'full-text.md').write_text(text,encoding='utf8')
print(json.dumps(summary,indent=2))

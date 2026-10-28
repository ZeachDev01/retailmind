"""Check the executable notebook's two-worksheet adapter and frozen source."""
import json
import sys
import unittest
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[3]
SCRIPTS = ROOT / 'src/backend/scripts'
sys.path.insert(0, str(SCRIPTS))
NOTEBOOK = ROOT / 'docs/research/uci_online_retail_ii_colab.ipynb'


def cells():
    return [''.join(cell['source']) for cell in json.loads(NOTEBOOK.read_text(encoding='utf-8'))['cells']
            if cell['cell_type'] == 'code']


def adapter():
    code = next(code for code in cells() if 'def prepare_uci_ii(' in code)
    namespace = {}
    exec(code.split('\nsheets = pd.read_excel(')[0], namespace)
    return namespace['prepare_uci_ii']


class UciIIColabTests(unittest.TestCase):
    def test_two_years_overlap_and_same_sheet_repeated_lines(self):
        dates = pd.date_range('2009-12-01', '2011-12-09')
        raw = pd.DataFrame([
            {'Invoice': str(100000 + day), 'StockCode': str(pid), 'Description': 'Example',
             'Quantity': 2, 'InvoiceDate': date, 'Price': 1.0}
            for pid in range(10000, 10021) for day, date in enumerate(dates)
        ])
        extra = raw[(raw.StockCode == '10000') & (raw.InvoiceDate == '2010-01-15')]
        first = pd.concat([raw[raw.InvoiceDate <= '2010-12-09'], extra], ignore_index=True)
        second = raw[raw.InvoiceDate >= '2010-12-01'].copy()
        history, products, metadata = adapter()({'first': first, 'second': second})
        self.assertEqual(len(products), 20)
        self.assertGreater(history.date.nunique(), 700)
        self.assertEqual(metadata['cross_sheet_copies_removed'], 9 * 21)
        self.assertEqual(metadata['worksheets'], ['first', 'second'])
        self.assertEqual(history.loc[(history.product_id == '10000') & (history.date == '2010-01-15'), 'units_sold'].item(), 4)
        self.assertEqual(history.loc[(history.product_id == '10000') & (history.date == '2010-12-05'), 'units_sold'].item(), 2)
        self.assertIn('/502/', metadata['source_url'])
        cutoff = pd.Timestamp(metadata['selection_end'])
        second.loc[second.InvoiceDate > cutoff, 'Quantity'] = 99999
        _, changed_products, _ = adapter()({'first': first, 'second': second})
        pd.testing.assert_frame_equal(products, changed_products)

    def test_missing_worksheets_fail(self):
        with self.assertRaisesRegex(ValueError, 'No sales worksheets'):
            adapter()({})

    def test_python_cells_compile_and_bundled_sources_match(self):
        for index, code in enumerate(cells()):
            if not code.startswith('# Install'):
                compile(code, f'cell-{index}', 'exec')
        bundle = next(code for code in cells() if code.startswith('BUNDLED_SOURCES = '))
        namespace = {}
        exec(bundle.split('\nfrom pathlib import Path')[0], namespace)
        for name, source in namespace['BUNDLED_SOURCES'].items():
            self.assertEqual(source, (SCRIPTS / name).read_text(encoding='utf-8'))


if __name__ == '__main__':
    unittest.main()

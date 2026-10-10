"""Check imported demand and POS precedence, rolling back all test data."""
import sys
from datetime import timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'legacy' / 'demandForcasting'))
import train_model
from db import get_connection

conn = get_connection()
try:
    store_id = train_model.resolve_store_id(conn)
    cursor = conn.cursor()
    cursor.execute("""
        SELECT p.product_id, MIN(DATE(s.sale_date))
        FROM products p JOIN sale_items si ON si.product_id = p.product_id
        JOIN sales s ON s.sale_id = si.sale_id
        WHERE p.branch_id = %s AND p.status = 'active'
        GROUP BY p.product_id ORDER BY p.product_id LIMIT 1
    """, (store_id,))
    product_id, first_pos_day = cursor.fetchone()
    import_day = first_pos_day - timedelta(days=2)
    zero_day = first_pos_day - timedelta(days=1)
    cursor.executemany("""
        INSERT INTO forecast_sales_imports (product_id, sale_date, quantity)
        VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
    """, [(product_id, import_day, 7), (product_id, zero_day, 0), (product_id, first_pos_day, 999999)])
    sales = train_model.load_sales_totals(conn, store_id)
    product_sales = sales[sales['product_id'] == product_id].set_index('sale_day')
    assert product_sales.loc[import_day, 'qty_sold'] == 7
    assert product_sales.loc[zero_day, 'qty_sold'] == 0
    cursor.execute("""SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
        WHERE si.product_id = %s AND DATE(s.sale_date) = %s""", (product_id, first_pos_day))
    assert product_sales.loc[first_pos_day, 'qty_sold'] == cursor.fetchone()[0], 'POS sales must replace overlapping imported totals'
    assert not sales.duplicated(['product_id', 'sale_day']).any(), 'Daily history must not double count'
    catalog = train_model.load_product_catalog(conn, store_id).set_index('product_id')
    assert catalog.loc[product_id, 'first_sale_day'] <= import_day, 'Catalog must include imported history'
    assert (sales['product_id'].isin(catalog.index)).all(), 'Source must stay within the active store catalog'
    print('Forecast history source checks passed (imports, zero demand, POS precedence)')
finally:
    conn.rollback()
    conn.close()

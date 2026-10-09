import re

with open('src/frontend/components/inventory_management/reorder_planner.php', 'r') as f:
    content = f.read()

# Query 1
content = re.sub(
    r'LEFT JOIN stock_predictions.*?FOR UPDATE"',
    r'''WHERE p.product_id IN ({}) AND p.status = 'active'\n                 FOR UPDATE"''',
    content,
    flags=re.DOTALL
)
content = re.sub(
    r'sp\.prediction_id, sp\.suggested_reorder_qty\s+FROM products p',
    r'NULL as prediction_id, NULL as suggested_reorder_qty\n                 FROM products p',
    content
)

# Query 2
content = re.sub(
    r'sp\.prediction_id.*?sp\.generated_at,',
    r'NULL as prediction_id, NULL as suggested_reorder_qty, NULL as forecasted_demand_during_lead_time, NULL as incoming_stock_used, NULL as confidence_score, NULL as generated_at,',
    content,
    flags=re.DOTALL
)

content = re.sub(
    r'LEFT JOIN stock_predictions.*?LEFT JOIN supplier_products spt',
    r'LEFT JOIN supplier_products spt',
    content,
    flags=re.DOTALL
)

content = re.sub(
    r'\(COALESCE\(sp\.suggested_reorder_qty, 0\) > 0 OR COALESCE\(i\.quantity_on_hand, 0\) <= p\.reorder_level\)',
    r'(COALESCE(i.quantity_on_hand, 0) <= p.reorder_level)',
    content
)

with open('src/frontend/components/inventory_management/reorder_planner.php', 'w') as f:
    f.write(content)

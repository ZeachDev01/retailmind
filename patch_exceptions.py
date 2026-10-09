import re

with open('src/frontend/components/report/forecast_exceptions.php', 'r') as f:
    content = f.read()

content = re.sub(
    r'SELECT sp\.\*,p\.sku,p\.product_name,i\.quantity_on_hand,\n\s+COALESCE\(s\.units_30,0\) units_30, COALESCE\(s\.nonzero_days,0\) nonzero_days, s\.first_sale_date\n\s+FROM stock_predictions sp\n\s+JOIN products p ON p\.product_id=sp\.product_id\n\s+JOIN inventory i ON i\.product_id=sp\.product_id\n\s+LEFT JOIN \(\n\s+SELECT si\.product_id,\n\s+SUM\(CASE WHEN sa\.sale_date >= DATE_SUB\(NOW\(\),INTERVAL 30 DAY\) THEN si\.quantity ELSE 0 END\) units_30,\n\s+COUNT\(DISTINCT CASE WHEN sa\.sale_date >= DATE_SUB\(NOW\(\),INTERVAL 90 DAY\) THEN DATE\(sa\.sale_date\) END\) nonzero_days,\n\s+MIN\(sa\.sale_date\) first_sale_date\n\s+FROM sale_items si JOIN sales sa ON sa\.sale_id=si\.sale_id GROUP BY si\.product_id\n\s+\) s ON s\.product_id=sp\.product_id\n\s+WHERE p\.branch_id = \?\n\s+AND sp\.prediction_id=\(SELECT sp2\.prediction_id FROM stock_predictions sp2 WHERE sp2\.product_id=sp\.product_id ORDER BY sp2\.generated_at DESC,sp2\.prediction_id DESC LIMIT 1\)\n\s+ORDER BY sp\.generated_at DESC,p\.product_name',
    r'''SELECT df.forecast_id, SUM(df.predicted_units) OVER(PARTITION BY df.job_id, df.product_id) AS predicted_demand_next_30_days, df.forecast_origin AS generated_at, df.readiness_level,
           p.product_id, p.sku, p.product_name, i.quantity_on_hand,
           COALESCE(s.units_30,0) units_30, COALESCE(s.nonzero_days,0) nonzero_days, s.first_sale_date
    FROM demand_forecasts df
    JOIN products p ON p.product_id=df.product_id
    JOIN inventory i ON i.product_id=df.product_id
    LEFT JOIN (
      SELECT si.product_id,
        SUM(CASE WHEN sa.sale_date >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN si.quantity ELSE 0 END) units_30,
        COUNT(DISTINCT CASE WHEN sa.sale_date >= DATE_SUB(NOW(),INTERVAL 90 DAY) THEN DATE(sa.sale_date) END) nonzero_days,
        MIN(sa.sale_date) first_sale_date
      FROM sale_items si JOIN sales sa ON sa.sale_id=si.sale_id GROUP BY si.product_id
    ) s ON s.product_id=df.product_id
    WHERE p.branch_id = ?
      AND df.job_id=(
          SELECT df2.job_id FROM demand_forecasts df2 
          WHERE df2.product_id=df.product_id 
          ORDER BY df2.forecast_origin DESC, df2.forecast_id DESC LIMIT 1
      )
    GROUP BY df.job_id, df.product_id, df.forecast_origin, p.product_name, p.product_id, p.sku, i.quantity_on_hand, s.units_30, s.nonzero_days, s.first_sale_date, df.forecast_id, df.predicted_units, df.readiness_level
    ORDER BY df.forecast_origin DESC,p.product_name''',
    content
)

old_str = '''    =(float)(['confidence_score'] ?? 0);
    =[(int)['product_id']] ?? [];
    =isset(['wape']) ? (float)['wape'] : null;
    if(<.50) []='Low confidence';'''

new_str = '''    =['readiness_level'] ?? 'Insufficient';
    =[(int)['product_id']] ?? [];
    =isset(['wape']) ? (float)['wape'] : null;
    if( === 'Insufficient' ||  === 'Low') []='Low readiness';'''

content = content.replace(old_str, new_str)

with open('src/frontend/components/report/forecast_exceptions.php', 'w') as f:
    f.write(content)

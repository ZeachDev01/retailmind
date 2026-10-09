import re

with open('src/frontend/components/report/forecast_analytics.php', 'r') as f:
    content = f.read()

content = re.sub(
    r'SELECT p\.product_name, sp\.forecast_value, sp\.actual_demand, sp\.generated_at\n\s+FROM stock_predictions sp JOIN products p ON p\.product_id = sp\.product_id\n\s+WHERE sp\.actual_demand IS NOT NULL AND p\.branch_id = \?\n\s+ORDER BY sp\.generated_at DESC LIMIT 20',
    r'''SELECT p.product_name, SUM(df.predicted_units) AS forecast_value,
                (
                    SELECT COALESCE(SUM(si.quantity), 0)
                    FROM sale_items si
                    JOIN sales s ON s.sale_id = si.sale_id
                    WHERE si.product_id = df.product_id
                      AND s.sale_date >= df.forecast_origin
                      AND s.sale_date < DATE_ADD(df.forecast_origin, INTERVAL 28 DAY)
                ) AS actual_demand,
                df.forecast_origin AS generated_at
         FROM demand_forecasts df JOIN products p ON p.product_id = df.product_id
         WHERE p.branch_id = ? AND DATE_ADD(df.forecast_origin, INTERVAL 28 DAY) <= NOW()
         GROUP BY df.job_id, df.product_id, df.forecast_origin, p.product_name
         ORDER BY df.forecast_origin DESC LIMIT 20''',
    content
)

with open('src/frontend/components/report/forecast_analytics.php', 'w') as f:
    f.write(content)

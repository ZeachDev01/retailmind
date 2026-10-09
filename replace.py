import sys
content = open('src/frontend/components/report/report_generation.php', 'r').read()
old = '''            $stmt = $pdo->prepare(
                "SELECT p.sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        fr.model_name, fr.model_version,
                        sp.forecast_period_days,
                        sp.generated_at,
                        DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY) AS forecast_completed_at,
                        COALESCE(sp.predicted_demand_next_30_days, sp.forecast_value, 0) AS forecast_quantity,
                        COALESCE(sp.actual_demand, (
                            SELECT SUM(si.quantity)
                            FROM sale_items si
                            JOIN sales s ON s.sale_id = si.sale_id
                            WHERE si.product_id = sp.product_id
                              AND s.sale_date >= sp.generated_at
                              AND s.sale_date < DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY)
                        ), 0) AS actual_quantity,
                        sp.confidence_score
                 FROM stock_predictions sp
                 JOIN forecast_runs fr ON fr.forecast_run_id = sp.forecast_run_id
                 JOIN products p ON p.product_id = sp.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY) <= NOW()
                   AND DATE(DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY)) BETWEEN ? AND ?
                   {$productSql}
                 ORDER BY forecast_completed_at DESC,
                          ABS(actual_quantity - forecast_quantity) DESC"
            );'''
new = '''            $stmt = $pdo->prepare(
                "SELECT p.sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        df.model_version AS model_name, df.model_version,
                        28 AS forecast_period_days,
                        df.forecast_origin AS generated_at,
                        DATE_ADD(df.forecast_origin, INTERVAL 28 DAY) AS forecast_completed_at,
                        SUM(df.predicted_units) AS forecast_quantity,
                        COALESCE((
                            SELECT SUM(si.quantity)
                            FROM sale_items si
                            JOIN sales s ON s.sale_id = si.sale_id
                            WHERE si.product_id = df.product_id
                              AND s.sale_date >= df.forecast_origin
                              AND s.sale_date < DATE_ADD(df.forecast_origin, INTERVAL 28 DAY)
                        ), 0) AS actual_quantity,
                        1.0 AS confidence_score
                 FROM demand_forecasts df
                 JOIN products p ON p.product_id = df.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE DATE_ADD(df.forecast_origin, INTERVAL 28 DAY) <= NOW()
                   AND df.horizon_day <= 28
                   AND DATE(DATE_ADD(df.forecast_origin, INTERVAL 28 DAY)) BETWEEN ? AND ?
                   {$productSql}
                 GROUP BY df.job_id, df.product_id, p.sku, p.product_name, c.category_name, df.model_version, df.forecast_origin
                 ORDER BY forecast_completed_at DESC"
            );'''
if old in content:
    open('src/frontend/components/report/report_generation.php', 'w').write(content.replace(old, new))
    print('Replaced')
else:
    print('Not found')

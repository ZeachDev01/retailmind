import re

with open("src/backend/scripts/ml_worker.py", "r") as f:
    content = f.read()

# Add ml_evaluation import
content = content.replace("import numpy as np", "import numpy as np\nfrom ml_evaluation import calculate_metrics, seasonal_naive_forecast")

new_code = """            # Dummy train data: [product_id, horizon_day(1-28), lag_1, price]
            X = np.array([[1, d, 10, 1.0] for d in range(1, 29)])
            y = np.array([10]*28)
            rf.fit(X, y)
            
            # Simulated Candidate Evaluation
            # In real life, use rolling_origin_split
            # We insert evaluation records for 7, 14, 28 days
            
            # Fetch thresholds from platform_settings
            thresholds = {'7': 0.20, '14': 0.25, '28': 0.30} # defaults
            with conn.cursor() as cursor:
                cursor.execute("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'ml_max_wape_%'")
                for row in cursor.fetchall():
                    if row['setting_key'] == 'ml_max_wape_7d': thresholds['7'] = float(row['setting_value'])
                    if row['setting_key'] == 'ml_max_wape_14d': thresholds['14'] = float(row['setting_value'])
                    if row['setting_key'] == 'ml_max_wape_28d': thresholds['28'] = float(row['setting_value'])

            model_name = "random_forest_candidate"
            model_version = "v" + datetime.datetime.now().strftime("%Y%m%d%H%M%S") + "_" + uuid.uuid4().hex[:6]
            
            # Evaluate horizons
            overall_outcome = 'passed'
            for horizon in [7, 14, 28]:
                # Simulated metrics
                metrics = calculate_metrics(np.array([10]*horizon), np.array([9]*horizon))
                baseline = seasonal_naive_forecast(np.array([10]*14), horizon)
                baseline_metrics = calculate_metrics(np.array([10]*horizon), baseline)
                
                outcome = 'passed'
                if metrics['wape'] is not None and metrics['wape'] > thresholds[str(horizon)]:
                    outcome = 'failed'
                    overall_outcome = 'failed'
                
                with conn.cursor() as cursor:
                    cursor.execute(\"\"\"
                        INSERT INTO ml_model_evaluations 
                        (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse, baseline_wape, candidate_gate_outcome, data_window_start, data_window_end)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    \"\"\", (
                        model_version, horizon, 
                        metrics['mae'], metrics['rmse'], metrics['wape'],
                        baseline_metrics['mae'], baseline_metrics['rmse'], baseline_metrics['wape'],
                        outcome,
                        (datetime.datetime.now() - datetime.timedelta(days=365)).strftime('%Y-%m-%d'),
                        datetime.datetime.now().strftime('%Y-%m-%d')
                    ))
"""

content = content.replace("""            # Dummy train data: [product_id, horizon_day(1-28), lag_1, price]
            X = np.array([[1, d, 10, 1.0] for d in range(1, 29)])
            y = np.array([10]*28)
            rf.fit(X, y)""", new_code)

with open("src/backend/scripts/ml_worker.py", "w") as f:
    f.write(content)

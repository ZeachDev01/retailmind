import re

with open('src/backend/includes/functions.php', 'r') as f:
    content = f.read()

content = re.sub(
    r'LEFT JOIN stock_predictions sp ON sp.product_id = p.product_id',
    r'LEFT JOIN demand_forecasts df ON df.product_id = p.product_id',
    content
)

content = re.sub(
    r'sp\.generated_at',
    r'df.forecast_origin',
    content
)

content = re.sub(
    r'sp\.confidence_score',
    r'df.readiness_level',
    content
)

content = re.sub(
    r'MAX\(df.forecast_origin\) AS last_prediction_date',
    r'MAX(df.forecast_origin) AS last_prediction_date, MAX(df.readiness_level) AS latest_readiness_level',
    content
)

with open('src/backend/includes/functions.php', 'w') as f:
    f.write(content)

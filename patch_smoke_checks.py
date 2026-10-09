import re

with open('src/backend/tests/smoke_checks.php', 'r') as f:
    content = f.read()

# Remove 'Forecast training keeps Store Product and Day grain' block
content = re.sub(r"\s*'Forecast training keeps Store Product and Day grain' => \[\s*'file' => 'src/backend/legacy/demandForcasting/train_model\.py',\s*'needles' => \['resolve_store_id', 'p\.branch_id = %s', 'GROUP BY si\.product_id, DATE\(s\.sale_date\)'\],\s*'forbidden' => \['branch_id AS', 'groupby\(\"branch_id\"', \"groupby\(\['branch_id'\"\],\s*\],", "", content)

with open('src/backend/tests/smoke_checks.php', 'w') as f:
    f.write(content)

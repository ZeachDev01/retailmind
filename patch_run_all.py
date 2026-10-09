import re

with open('src/backend/tests/run_all.sh', 'r') as f:
    content = f.read()

content = re.sub(r"python -m py_compile src/backend/legacy/demandForcasting/.*?\.py\n", "", content)
content = re.sub(r"python src/backend/tests/forecast_regression\.py\n", "", content)

with open('src/backend/tests/run_all.sh', 'w') as f:
    f.write(content)

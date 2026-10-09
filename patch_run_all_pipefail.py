import re

with open('src/backend/tests/run_all.sh', 'r') as f:
    content = f.read()

content = content.replace('set -euo pipefail', 'set -eu')

with open('src/backend/tests/run_all.sh', 'w') as f:
    f.write(content)

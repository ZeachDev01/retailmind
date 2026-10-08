"""Run with: python src/backend/tests/infinityfree_deploy_contract.py"""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[3]
workflow = (root / '.github/workflows/ci.yml').read_text()
deploy = workflow.split('\n  deploy:\n', 1)[1]
matrix = re.search(r"fromJSON\(vars\.INFINITYFREE_MEMBER_DEPLOY_ENABLED == 'true' &&\s*'([^']+)' \|\|\s*'([^']+)'\)", deploy)
assert matrix, 'Second account must be explicitly enabled'
enabled, disabled = (json.loads(value) for value in matrix.groups())
assert disabled == [{'target': 'production', 'config_prefix': 'INFINITYFREE'}]
assert enabled == disabled + [{'target': 'member', 'config_prefix': 'INFINITYFREE_MEMBER'}]
assert 'fail-fast: false' in deploy, 'One account failure must not cancel the other'
assert 'group: infinityfree-${{ matrix.target }}' in deploy
assert "if: github.event_name == 'push' && github.ref == 'refs/heads/main'" in deploy

# Validation, upload, and root-file restoration must all use the selected account.
selectors = re.findall(r"\$\{\{ (secrets|vars)\[format\('\{0\}_(FTP_\w+)', matrix\.config_prefix\)\] \}\}", deploy)
for context, suffix in [('secrets', 'FTP_USERNAME'), ('secrets', 'FTP_PASSWORD'), ('vars', 'FTP_SERVER_DIR')]:
    assert selectors.count((context, suffix)) == 3, f'{suffix} must select the account in all three steps'
assert not re.search(r'\$\{\{\s*(secrets|vars)\.INFINITYFREE_FTP_', deploy)
assert 'protocol: ftps' in deploy and 'security: strict' in deploy
assert '**/.env' in deploy and '**/storage/**' in deploy
print('InfinityFree deployment contract: passed')

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
assert disabled == [{'target': 'production', 'config_prefix': 'INFINITYFREE', 'secret_prefix': ''}]
assert enabled == disabled + [{'target': 'member', 'config_prefix': 'INFINITYFREE_MEMBER', 'secret_prefix': 'INFINITYFREE_MEMBER_'}]
assert 'fail-fast: false' in deploy, 'One account failure must not cancel the other'
assert 'group: infinityfree-${{ matrix.target }}' in deploy
assert "if: github.event_name == 'push' && github.ref == 'refs/heads/main'" in deploy

# Validation, upload, and root-file restoration must all use the selected account.
selectors = re.findall(r"\$\{\{ secrets\[format\('\{0\}(FTP_\w+)', matrix\.secret_prefix\)\] \}\}", deploy)
for suffix in ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD']:
    assert selectors.count(suffix) == 3, f'{suffix} must select the account in all three steps'
directory = "${{ vars[format('{0}_FTP_SERVER_DIR', matrix.config_prefix)] }}"
assert deploy.count(directory) == 3, 'Keep the existing account-specific directory variables'
assert 'ftp://${FTP_SERVER}${FTP_SERVER_DIR}${file}' in deploy
assert 'ftpupload.net' not in deploy, 'Both upload steps must use the FTP_SERVER secret'
assert not re.search(r'\$\{\{\s*(secrets|vars)\.INFINITYFREE_FTP_', deploy)
assert 'protocol: ftps' in deploy and 'security: strict' in deploy
assert '**/.env' in deploy and '**/storage/**' in deploy
print('InfinityFree deployment contract: passed')

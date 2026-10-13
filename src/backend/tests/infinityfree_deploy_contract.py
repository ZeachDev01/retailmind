"""Verify the current single-production-account deployment workflow."""
import re
from pathlib import Path

root = Path(__file__).resolve().parents[3]
workflow = (root / '.github/workflows/ci.yml').read_text()
assert re.search(r'on:\s+push:\s+branches:\s+- main\b', workflow), 'Only main pushes deploy'
deploy = workflow.split('\n  deploy:\n', 1)[1]
assert 'composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader' in deploy
assert 'bash scripts/build-infinityfree-release.sh' in deploy
assert 'local-dir: .release/' in deploy, 'Upload the production package, never the repository root'
assert 'python src/backend/tests/infinityfree_deploy_contract.py' in deploy
assert 'dangerous-clean-slate: true' not in deploy
for pattern in ['**/.env', 'backend/', 'backend/**', '**/storage/', '**/storage/**', '**/*.log']:
    assert re.search(r'^\s+' + re.escape(pattern) + r'\s*$', deploy, re.M), f'Preserve hosted {pattern}'
for secret in ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']:
    assert '${{ secrets.' + secret + ' }}' in deploy
    assert f'test -n "${secret}"' in deploy, f'{secret} must be validated before deployment'
assert 'umask 077' in deploy
assert "'APP_ENV=production'" in deploy and "'APP_DEBUG=false'" in deploy
assert "'SESSION_SECURE_COOKIE=true'" in deploy
for secret in ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD']:
    assert deploy.count('${{ secrets.' + secret + ' }}') == 2, f'{secret} must select the same account for sync and environment upload'
assert 'protocol: ftps' in deploy and 'security: strict' in deploy
assert 'server-dir: /htdocs/' in deploy
assert '--ssl-reqd' in deploy
assert '--upload-file .env "ftp://$FTP_SERVER/htdocs/.env"' in deploy
print('InfinityFree deployment contract: passed')

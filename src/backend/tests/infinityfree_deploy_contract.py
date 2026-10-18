"""Verify InfinityFree deployment with independently configured database credentials."""
import re
from pathlib import Path

root = Path(__file__).resolve().parents[3]
workflow = (root / '.github/workflows/ci.yml').read_text()
assert workflow.startswith('name: Deploy to InfinityFree\n')
assert '  workflow_dispatch:' in workflow
assert re.search(r'on:\s+push:\s+branches:\s+- main\b', workflow), 'Only main pushes deploy'
deploy = workflow.split('\n  deploy:\n', 1)[1]
assert 'composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader' in deploy
assert 'bash scripts/build-infinityfree-release.sh' in deploy
assert 'local-dir: .release/' in deploy, 'Upload the production package, never the repository root'
assert 'python src/backend/tests/infinityfree_deploy_contract.py' in deploy
assert 'dangerous-clean-slate: true' not in deploy
for pattern in ['**/.env', 'backend/', 'backend/**', '**/storage/', '**/storage/**', '**/*.log']:
    assert re.search(r'^\s+' + re.escape(pattern) + r'\s*$', deploy, re.M), f'Preserve hosted {pattern}'
for secret in ['APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']:
    assert '${{ secrets.' + secret + ' }}' in deploy
    assert f'test -n "${secret}"' in deploy, f'{secret} must be validated before deployment'
assert 'umask 077' in deploy
assert "'APP_ENV=production'" in deploy and "'APP_DEBUG=false'" in deploy
assert "'SESSION_SECURE_COOKIE=true'" in deploy
assert 'FTP_SERVER: ftpupload.net' in deploy
assert 'secrets.FTP_SERVER' not in deploy, 'Do not reuse a previous hosting server secret'
for secret in ['FTP_USERNAME', 'FTP_PASSWORD']:
    assert deploy.count('${{ secrets.' + secret + ' }}') == 1, f'{secret} must be shared by both uploads'
    assert f'test -n "${secret}"' in deploy
for variable in ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD']:
    assert '${{ env.' + variable + ' }}' in deploy
assert 'test "$FTP_USERNAME" = "$DB_USER"' not in deploy, 'Allow a separate Hostinger database user'
assert 'protocol: ftps' in deploy and 'security: strict' in deploy
assert re.search(r'^\s+server-dir: /htdocs/\s*$', deploy, re.M), 'Upload website files to InfinityFree htdocs'
assert '"APP_URL=$APP_URL"' in deploy
assert 'retailmind.infinityfreeapp.com' not in deploy
assert '--ssl-reqd' in deploy
assert '--upload-file .env "ftp://$FTP_SERVER/htdocs/.env"' in deploy
print('InfinityFree deployment contract: passed')

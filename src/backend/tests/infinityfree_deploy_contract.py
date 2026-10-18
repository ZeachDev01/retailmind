"""Verify Hostinger deployment with independently configured database credentials."""
import re
from pathlib import Path

root = Path(__file__).resolve().parents[3]
workflow = (root / '.github/workflows/ci.yml').read_text()
assert workflow.startswith('name: Deploy to Hostinger\n')
assert '  workflow_dispatch:' in workflow
assert re.search(r'on:\s+push:\s+branches:\s+- main\b', workflow), 'Only main pushes deploy'
deploy = workflow.split('\n  deploy:\n', 1)[1]
assert 'composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader' in deploy
assert 'bash scripts/build-infinityfree-release.sh' in deploy
assert 'local-dir: .release/' in deploy, 'Upload the production package, never the repository root'
assert 'python src/backend/tests/infinityfree_deploy_contract.py' in deploy
assert 'dangerous-clean-slate: true' not in deploy
for pattern in ['backend/', 'backend/**', '**/storage/', '**/storage/**', '**/*.log']:
    assert re.search(r'^\s+' + re.escape(pattern) + r'\s*$', deploy, re.M), f'Preserve hosted {pattern}'
for secret in ['APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']:
    assert '${{ secrets.' + secret + ' }}' in deploy
    assert f'test -n "${secret}"' in deploy, f'{secret} must be validated before deployment'
assert 'umask 077' in deploy
assert "'APP_ENV=production'" in deploy and "'APP_DEBUG=false'" in deploy
assert "'SESSION_SECURE_COOKIE=true'" in deploy
assert 'ftpupload.net' not in deploy, 'Do not deploy to the previous InfinityFree server'
for secret in ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD']:
    assert deploy.count('${{ secrets.' + secret + ' }}') == 1, f'{secret} must be configured once'
    assert f'test -n "${secret}"' in deploy
assert 'FTP_SERVER_DIR: ./' in deploy, 'The sub-FTP account is rooted at the shalom website directory'
for variable in ['FTP_SERVER', 'FTP_SERVER_DIR', 'FTP_USERNAME', 'FTP_PASSWORD']:
    assert '${{ env.' + variable + ' }}' in deploy
assert 'test "$FTP_USERNAME" = "$DB_USER"' not in deploy, 'Allow a separate Hostinger database user'
assert 'protocol: ftps' in deploy and 'security: strict' in deploy
assert 'server-dir: ${{ env.FTP_SERVER_DIR }}' in deploy, 'Use the configured Hostinger FTP directory'
assert '/htdocs/' not in deploy, 'Do not reuse the previous InfinityFree upload directory'
assert '"APP_URL=$APP_URL"' in deploy
assert 'retailmind.infinityfreeapp.com' not in deploy
assert "'DB_CHARSET=utf8mb4' > .release/.env" in deploy, 'Include generated .env in the production package'
assert '**/.env' not in deploy, 'The combined upload must include .env'
assert deploy.count('uses: SamKirkland/FTP-Deploy-Action@') == 1, 'Use one deployment upload'
assert '--upload-file' not in deploy, 'Do not upload .env separately'
print('Hostinger deployment contract: passed')

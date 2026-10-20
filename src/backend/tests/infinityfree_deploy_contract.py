"""Keep demo deployments isolated from hosted credentials and runtime data."""
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
for pattern in ['**/.env', 'backend/', 'backend/**', '**/storage/', '**/storage/**', '**/*.log']:
    assert re.search(r'^\s+' + re.escape(pattern) + r'\s*$', deploy, re.M), f'Preserve hosted {pattern}'
assert 'Create production environment' not in deploy, 'Preserve the manually configured Demo .env'
assert '> .release/.env' not in deploy
assert 'secrets.DB_' not in deploy, 'Code deployment does not need database credentials'
assert 'ftpupload.net' not in deploy, 'Do not deploy to the previous InfinityFree server'
for variable in ['FTP_USERNAME', 'FTP_PASSWORD']:
    secret = 'HOSTINGER_DEMO_' + variable
    assert deploy.count('${{ secrets.' + secret + ' }}') == 1
    assert f'test -n "${variable}"' in deploy
assert 'FTP_SERVER: srv2062.hstgr.io' in deploy, 'Use the verified FTPS certificate hostname, not an IP'
assert 'FTP_SERVER_DIR: ./' in deploy, 'The dedicated FTP account is rooted at the Demo public_html'
for variable in ['FTP_SERVER', 'FTP_SERVER_DIR', 'FTP_USERNAME', 'FTP_PASSWORD']:
    assert '${{ env.' + variable + ' }}' in deploy
assert 'test "$FTP_USERNAME" = "$DB_USER"' not in deploy, 'Allow a separate Hostinger database user'
assert 'protocol: ftps' in deploy and 'security: strict' in deploy
assert 'server-dir: ${{ env.FTP_SERVER_DIR }}' in deploy, 'Use the configured Hostinger FTP directory'
assert '/htdocs/' not in deploy, 'Do not reuse the previous InfinityFree upload directory'
assert 'APP_URL: https://antiquewhite-cattle-865506.hostingersite.com' in deploy
assert 'retailmind.infinityfreeapp.com' not in deploy
assert deploy.count('uses: SamKirkland/FTP-Deploy-Action@') == 1, 'Use one deployment upload'
assert '--upload-file' not in deploy, 'Do not upload .env separately'
assert 'cancel-in-progress: false' in workflow, 'Never interrupt an in-progress FTP sync'
assert 'contents: read' in workflow
assert 'php src/backend/tests/hosted_database_config_test.php' in deploy
assert 'id="landing-login-username"' in deploy and '"$APP_URL/.env"' in deploy
print('Hostinger deployment contract: passed')

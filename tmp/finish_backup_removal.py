from pathlib import Path
import re
def edit(p, fn):
    p=Path(p); s=p.read_text(encoding='utf-8'); t=fn(s)
    if s!=t: p.write_text(t,encoding='utf-8',newline='')

def format_test(s):
    start=s.index('$directory = sys_get_temp_dir()')
    end=s.index('    $key = ',start)
    setup=s[start:end]
    s=s[:start]+s[end:]
    s=s.replace("$pass = 'offline-independent-passphrase';\n",setup+"    $key = \\App\\Backup\\BackupKeyStore::current();\n")
    s=s.replace("$reject(fn() => StorePackageFormat::decode($package, $schema), 'Legacy passphrase packages are no longer supported');\n",'')
    s=s.replace("!str_contains($package, $pass)","!str_contains($package, $key)").replace('SQL nor passphrase','SQL nor key')
    s=re.sub(r"^.*Wrong passphrase rejected.*\n",'',s,flags=re.M)
    s=s.replace('holder of the passphrase','holder of the recovery key').replace('use ($pass)', 'use ($key)')
    s=s.replace('$prefix = "RMSTORE1\\n" . $salt . $iv;', '$prefix = "RMSTORE2\\n" . hash(\'sha256\', $key) . $salt . $iv;')
    s=s.replace("$key = hash_pbkdf2('sha256', $pass, $salt, 600000, 32, true);",'$key = hex2bin($key);')
    s=s.replace("    $reject(fn() => StorePackageFormat::decode($package, $schema), 'Older packages still require their original passphrase');", "    $legacyPrefix = \"RMSTORE1\\n\" . str_repeat('s', 16) . str_repeat('i', 12);\n    $legacyKey = hash_pbkdf2('sha256', 'legacy-backup-passphrase', str_repeat('s', 16), 600000, 32, true);\n    $legacyCipher = openssl_encrypt(json_encode($manifest, JSON_THROW_ON_ERROR), 'aes-256-gcm', $legacyKey, OPENSSL_RAW_DATA, str_repeat('i', 12), $legacyTag, $legacyPrefix, 16);\n    $reject(fn() => StorePackageFormat::decode($legacyPrefix . $legacyTag . $legacyCipher, $schema), 'Legacy passphrase packages are no longer supported');")
    s=re.sub(r"^.*Original passphrase backups remain supported.*\n",'',s,flags=re.M)
    return s
edit('src/backend/tests/store_package_format_test.php',format_test)

# Remove tests specific to the retired credential, keeping corruption and authorization coverage.
for filename in ['database_backup_workflow_integration.php','safety_copy_workflow_integration.php','recovery_restart_workflow_integration.php']:
    def test(s):
        s=re.sub(r'^.*\$reject\(fn\(\).*?(?:Wrong independent passphrase rejected|Wrong safety passphrase rejected|Rejects wrong incoming passphrase).*\n','',s,flags=re.M)
        s=re.sub(r'    // Reject wrong (?:safety passphrase(?: during restart)?|incoming passphrase)\n    \$reject\(function\(\).*?\n    \}, .*?\);\n\n','',s,flags=re.S)
        return s
    edit('src/backend/tests/'+filename,test)

# Operational reset must produce a readable package through the same key-only service.
edit('src/backend/app/Services/OperationalDataResetService.php',lambda s:s.replace(', #[\\SensitiveParameter] string $backupPassphrase','').replace(', $backupPassphrase, $requestLock',', $requestLock'))
edit('src/frontend/components/administrator/reset_data.php',lambda s:s.replace(", (string)($_POST['backup_passphrase'] ?? '')",'').replace('store passphrase off-server','keep your recovery key separately').replace(next((line for line in s.splitlines(True) if 'id="reset-backup-passphrase"' in line),''),''))
edit('src/frontend/components/backup/safety_copy.php',lambda s:s.replace("(string)($_POST['safety_password'] ?? ''), ''", "(string)($_POST['safety_password'] ?? '')"))
edit('src/frontend/assets/css/backup_restore.css',lambda s:re.sub(r'^.*\.reconcile-older.*\n','',s,flags=re.M))
edit('src/backend/tests/backup_workflow_route_contract.php',lambda s:s.replace("&& str_contains($superAdministratorPage, 'backup_passphrase')", "&& !str_contains($superAdministratorPage, 'backup_passphrase')"))
edit('src/backend/tests/operational_reset_browser_test.js',lambda s:re.sub(r'^.*reset-backup-passphrase.*\n','',s,flags=re.M))
edit('src/backend/tests/recovery_reopen_browser_test.js',lambda s:re.sub(r'^.*locator\(\'#safety-passphrase\'\).*\n','',s,flags=re.M))

from pathlib import Path
import re

root = Path('src')
def edit(path, fn):
    p = Path(path)
    s = p.read_text(encoding='utf-8')
    t = fn(s)
    if s != t: p.write_text(t, encoding='utf-8', newline='')

# Remove obsolete positional arguments at every package/restore boundary.
def calls(s, pattern, indexes):
    matches = list(re.finditer(pattern + r'\s*\(', s))
    for m in reversed(matches):
        start = m.end(); i = start; depth = 0; quote = None; cuts = [start]
        while i < len(s):
            c = s[i]
            if quote:
                if c == '\\': i += 2; continue
                if c == quote: quote = None
            elif c in "\"'": quote = c
            elif c in '([{': depth += 1
            elif c in ')]}':
                if depth == 0: break
                depth -= 1
            elif c == ',' and depth == 0: cuts.append(i + 1)
            i += 1
        parts = [s[a:b-1] for a,b in zip(cuts,cuts[1:])] + [s[cuts[-1]:i]]
        s = s[:start] + ','.join(v for j,v in enumerate(parts) if j not in indexes) + s[i:]
    return s

for p in root.rglob('*.php'):
    s = p.read_text(encoding='utf-8')
    if 'StorePackageFormat' not in s and not any(x in s for x in ['Passphrase', 'passphrase', '->startIncoming(', '->recoverSafety(', '->restartIncoming(']): continue
    t = calls(s, r'StorePackageFormat::encode', {3})
    t = calls(t, r'StorePackageFormat::decode', {1})
    for name, indexes in [('readPackage',{5}), ('readAuthorizedPackage',{5}), ('preflight',{5}), ('startIncoming',{5,6}), ('restartIncoming',{3,4}), ('recoverSafety',{3})]:
        t = calls(t, r'->' + name, indexes)
    if p.name == 'DatabaseBackupService.php':
        t = calls(t, r'->capture', {2})
    if p.name == 'SafetyCopyService.php':
        t = calls(t, r'->verify', {1})
    if 'DatabaseRestoreService' in s or p.name == 'DatabaseRestoreService.php':
        t = calls(t, r'\$safetyService->verify', {1})
    t = calls(t, r'->createForSystem', {0})
    t = calls(t, r'\$(?:backups|fullBackups)->create', {2})
    t = calls(t, r'\$(?:safety|safetyService)->create', {4})
    if p.name == 'safety_copy_workflow_integration.php':
        t = calls(t, r'\$service->create', {4})
        t = calls(t, r'\$service->verify', {1})
    if t != s: p.write_text(t, encoding='utf-8', newline='')

p = 'src/backend/app/Backup/StorePackageFormat.php'
def package(s):
    s = s.replace('    public const ITERATIONS = 600000;\n', '').replace('    private const HEADER = "RMSTORE1\\n";\n','')
    s = re.sub(r'    public static function assertPassphrase.*?\n    public static function encode', '    public static function encode', s, flags=re.S)
    s = s.replace(', #[\\SensitiveParameter] string $passphrase', '')
    s = re.sub(r'        \$header = self::HEADER;.*?        SqlBackupFormat::statements', '        $secret = BackupKeyStore::current();\n        $header = self::KEY_HEADER . hash(\'sha256\', $secret);\n        SqlBackupFormat::statements', s, flags=re.S)
    s = s.replace("$managed ? hex2bin($passphrase) : hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true)", 'hex2bin($secret)')
    s = s.replace('        $managed = str_starts_with($package, self::KEY_HEADER);\n', '').replace('strlen(self::HEADER) + ($managed ? 64 : 0)', 'strlen(self::KEY_HEADER) + 64')
    s = s.replace('(!$managed && !str_starts_with($package, self::HEADER))', '!str_starts_with($package, self::KEY_HEADER)')
    s = re.sub(r'        if \(\$managed\) \{.*?        self::assertPassphrase\(\$passphrase\);', "        $secret = BackupKeyStore::find(substr($package, strlen(self::KEY_HEADER), 64));", s, flags=re.S)
    s = s.replace("$managed ? 'The backup is damaged or its recovery key is incorrect.' : 'The backup passphrase is incorrect, or the package is damaged.'", "'The backup is damaged or its recovery key is incorrect.'")
    return s
edit(p, package)

def backup(s):
    s = s.replace(", #[\\SensitiveParameter] string $passphrase = ''", '').replace("#[\\SensitiveParameter] string $passphrase = ''", '').replace(', #[\\SensitiveParameter] string $passphrase', '')
    s = re.sub(r"        if \(\$passphrase !== ''\) StorePackageFormat::assertPassphrase\(\$passphrase\);\n", '', s)
    s = s.replace("$passphrase === '' ? StorePackageFormat::KEY_VERSION : StorePackageFormat::VERSION", 'StorePackageFormat::KEY_VERSION')
    s = s.replace("in_array($row['envelope_version'], [StorePackageFormat::VERSION, StorePackageFormat::KEY_VERSION], true)", "$row['envelope_version'] === StorePackageFormat::KEY_VERSION")
    return s
edit('src/backend/app/Backup/DatabaseBackupService.php', backup)
for filename in ['DatabaseRestoreService.php', 'SafetyCopyService.php']:
    def services(s):
        s = re.sub(r',\s*#\[\\SensitiveParameter\] string \$(?:backup|incoming|safety)Passphrase', '', s)
        s = re.sub(r"        if \(\$safetyPassphrase !== ''\) StorePackageFormat::assertPassphrase\(\$safetyPassphrase\);\n", '', s)
        return s.replace('with verified passphrases.', 'with available recovery keys.')
    edit('src/backend/app/Backup/' + filename, services)
edit('src/backend/app/Backup/StoreReopening.php', lambda s: s.replace('with verified passphrases.', 'with available recovery keys.'))

for p in Path('src/frontend/components').rglob('*.php'):
    if 'phpmailer' in str(p): continue
    s = p.read_text(encoding='utf-8')
    t = re.sub(r'        <details><summary>Older backups</summary>.*?</details>\n', '', s)
    t = re.sub(r'\s*<div class="form-group">\s*(?:<details class="reconcile-older">.*?)?<label for="(?:restore|identity)-passphrase">.*?</div>', '', t, flags=re.S) if p.name in ['backup_restore.php','identity_reconciliation.php'] else t
    t = re.sub(r'\n\s*<div>\s*<label for="(?:safety-passphrase(?:-restart)?|incoming-passphrase)".*?</div>', '', t, flags=re.S)
    t = re.sub(r"^\s*\$(?:safety|incoming)Passphrase = .*?;\n", '', t, flags=re.M)
    t = t.replace(' Older backups also need their original passphrase.', '').replace(' Older backups still need their original passphrases.', '')
    if t != s: p.write_text(t, encoding='utf-8', newline='')
edit('src/frontend/assets/js/backup_review.js', lambda s: s.replace("['restore_password', 'backup_passphrase']", "['restore_password']"))

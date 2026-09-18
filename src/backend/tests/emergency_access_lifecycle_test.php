<?php
// Emergency Access lifecycle contract through the public module behavior.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Authorization\EmergencyAccessClock;
use App\Authorization\EmergencyAccessService;
use App\Authorization\RoleCapabilityPolicy;

final class TestEmergencyAccessClock implements EmergencyAccessClock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(string $interval): void
    {
        $this->time = $this->time->modify($interval);
    }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$throws = static function (callable $operation, string $exceptionClass, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message);
    } catch (Throwable $exception) {
        $assert($exception instanceof $exceptionClass, $message . ' (received ' . $exception::class . ')');
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
$pdo->exec("INSERT INTO platform_settings VALUES ('emergency_access_duration_minutes', '20')");
$pdo->exec('CREATE TABLE emergency_access_sessions (
    session_id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    activated_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    duration_minutes INTEGER NOT NULL,
    status TEXT NOT NULL,
    revoked_at TEXT NULL,
    revoked_by INTEGER NULL
)');
$pdo->exec('CREATE TABLE activity_log (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    action TEXT NOT NULL,
    category TEXT NOT NULL,
    module TEXT NULL,
    record_id INTEGER NULL,
    metadata TEXT NULL,
    created_at TEXT NULL
)');

$clock = new TestEmergencyAccessClock(new DateTimeImmutable('2026-09-18 10:00:00', new DateTimeZone('UTC')));
$service = new EmergencyAccessService($pdo, $clock);
$policy = new RoleCapabilityPolicy();
$GLOBALS['emergencyAccessTestService'] = $service;

function current_role(): ?string
{
    return 'super_admin';
}

function emergency_access_service(PDO $pdo): EmergencyAccessService
{
    return $GLOBALS['emergencyAccessTestService'];
}

require_once __DIR__ . '/../includes/functions.php';

$throws(
    static fn() => $service->activate(0, 'super_admin', 'Incident response'),
    DomainException::class,
    'Activation should require an authenticated actor'
);
$throws(
    static fn() => $service->activate(41, 'admin', 'Incident response'),
    DomainException::class,
    'Activation should require a Super Administrator'
);
$throws(
    static fn() => $service->activate(41, 'super_admin', '   '),
    InvalidArgumentException::class,
    'Activation should require a non-empty reason'
);

$session = $service->activate(41, 'super_admin', ' Restore Store inventory during incident ');
$assert($session['actor_user_id'] === 41, 'Activation should record the initiating actor');
$assert($session['reason'] === 'Restore Store inventory during incident', 'Activation should record the trimmed reason');
$assert($session['activated_at'] === '2026-09-18 10:00:00', 'Activation should use the injected clock');
$assert($session['expires_at'] === '2026-09-18 10:20:00', 'Activation should calculate expiry from configured duration');
$assert($session['duration_minutes'] === 20, 'Activation should record configured duration');
$assert($session['status'] === 'active', 'New access should be active');
$assert($session['revoked_at'] === null, 'New access should not be revoked');

$context = $service->authorizationContext(41, 'super_admin');
$assert(
    $policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $context, 41),
    'Initiating Super Administrator should receive the intended operational capability'
);
$assert(
    !$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $context, 42),
    'Emergency Access should not elevate another Super Administrator'
);
$metadata = $service->requireOperationalMutation(41, 'super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY);
$assert(
    $metadata === ['emergency_access_session_id' => $session['session_id']],
    'Permitted operational mutations should expose audit correlation metadata'
);
log_activity(
    $pdo,
    41,
    'Emergency inventory adjustment',
    'Inventory',
    500,
    null,
    ['quantity' => 12]
);
$operationalAudit = $pdo->query("SELECT metadata FROM activity_log WHERE action = 'Emergency inventory adjustment'")->fetchColumn();
$operationalMetadata = json_decode((string)$operationalAudit, true);
$assert(
    ($operationalMetadata['emergency_access_session_id'] ?? null) === $session['session_id'],
    'Permitted elevated actions should carry the Emergency Access session identifier in Protected Audit Record metadata'
);
$throws(
    static fn() => $service->requireOperationalMutation(42, 'super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY),
    DomainException::class,
    'Operational mutation should be denied for another actor'
);

$clock->advance('+21 minutes');
$expired = $service->status(41);
$assert($expired !== null && $expired['status'] === 'expired', 'Access should expire automatically under the injected clock');
$assert(
    !$policy->allows('super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY, null, $service->authorizationContext(41, 'super_admin'), 41),
    'Expired access should no longer grant operational mutation'
);
$throws(
    static fn() => $service->requireOperationalMutation(41, 'super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY),
    DomainException::class,
    'Operational mutation should be denied after expiry'
);

$clock->advance('+1 minute');
$pdo->exec("UPDATE platform_settings SET setting_value = '120' WHERE setting_key = 'emergency_access_duration_minutes'");
$replacement = $service->activate(41, 'super_admin', 'Second incident');
$assert($replacement['duration_minutes'] === 60, 'Configured duration should be constrained by the enforced upper bound');
$revoked = $service->revoke(41, 'super_admin');
$assert($revoked['session_id'] === $replacement['session_id'], 'Revocation should target the actor active session');
$assert($revoked['status'] === 'revoked', 'Revocation should record revoked status');
$assert($revoked['revoked_at'] === '2026-09-18 10:22:00', 'Revocation should record when access ended early');
$assert($revoked['revoked_by'] === 41, 'Revocation should record who ended access');
$throws(
    static fn() => $service->requireOperationalMutation(41, 'super_admin', RoleCapabilityPolicy::MUTATE_INVENTORY),
    DomainException::class,
    'Operational mutation should be denied after revocation'
);

$auditRows = $pdo->query("SELECT action, metadata FROM activity_log WHERE module = 'Emergency Access' ORDER BY log_id")->fetchAll(PDO::FETCH_ASSOC);
$assert(count($auditRows) === 3, 'Activation and revocation should create lifecycle Protected Audit Records');
foreach ($auditRows as $auditRow) {
    $auditMetadata = json_decode((string)$auditRow['metadata'], true);
    $assert(
        isset($auditMetadata['emergency_access_session_id']),
        'Emergency Access lifecycle records should carry session correlation metadata'
    );
}

if ($failures) {
    fwrite(STDERR, "Emergency Access lifecycle test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Emergency Access lifecycle test: passed\n";

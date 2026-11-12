<?php
// Execute the production create branch with a fake lifecycle and mail boundary.
// No live database or email service is used.
namespace AdministratorWelcomeTest;

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../includes/functions.php';
restore_exception_handler();

function password_policy_error(string $password): ?string { return null; }
function role_names_for_ids($pdo, array $ids): array { return ['admin']; }
function profile_image_storage(): object
{
    return new class {
        public function store($upload): ?string { return null; }
    };
}
function send_email_notification(string $email, string $subject, string $message, ?string $html): bool
{
    global $delivery, $mailCalls, $accountCreated;
    if (!$accountCreated) throw new \LogicException('Mail attempted before account creation');
    $mailCalls[] = compact('email', 'subject', 'message', 'html');
    if ($delivery === 'throw') throw new \RuntimeException('Simulated mail failure');
    return $delivery;
}

$source = file_get_contents(__DIR__ . '/../../frontend/components/user_manager/user_manager.php');
$start = strpos($source, "            \$createFormSubmitted = true;");
$end = strpos($source, "        } elseif (\$action === 'update')", $start);
if ($start === false || $end === false) throw new \RuntimeException('Create handler not found');
$run = eval('namespace AdministratorWelcomeTest; use Throwable; use InvalidArgumentException; '
    . 'use function administrator_welcome_email_html; '
    . 'return static function ($actorRole, $lifecycle) { $pdo = null; $actorId = 1; '
    . substr($source, $start, $end - $start)
    . 'return [$message, $messageClass, $createFormValues]; };');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new \RuntimeException($message);
};
$_POST = ['first_name' => 'Ana', 'last_name' => 'Cruz', 'username' => 'ana.admin',
    'email' => 'ana@example.test', 'password' => 'Temporary<&Password123', 'role_ids' => ['1']];
$_FILES = [];
$lifecycle = new class {
    public bool $fail = false;
    public function create(int $actorId, string $actorRole, array $account): int
    {
        global $accountCreated;
        if ($this->fail) throw new \InvalidArgumentException('Account rejected');
        if (!password_verify($_POST['password'], $account['password_hash'])) throw new \LogicException('Incorrect stored password');
        if (isset($account['password'])) throw new \LogicException('Plaintext password passed to persistence');
        $accountCreated = true;
        return 2;
    }
};
foreach ([true, false, 'throw'] as $delivery) {
    $accountCreated = false;
    $mailCalls = [];
    [$message, $class, $values] = $run('super_admin', $lifecycle);
    $assert($accountCreated && count($mailCalls) === 1, 'Create once, then send exactly one welcome email');
    $mail = $mailCalls[0];
    $assert($mail['email'] === $_POST['email'], 'Welcome must go to the new Administrator');
    $assert(str_contains($mail['message'], $_POST['username']) && str_contains($mail['message'], $_POST['password']), 'Plain text must include exact username and temporary password');
    $assert(str_contains($mail['html'], htmlspecialchars($_POST['password'], ENT_QUOTES, 'UTF-8')), 'HTML must include the escaped temporary password');
    $assert($class === ($delivery === true ? 'tag-success' : 'tag-warning'), 'Mail failure must show a warning while preserving the created account');
    $assert($values['username'] === '', 'Clear the create form even when mail fails to avoid duplicate submission');
    if ($delivery !== true) $assert(str_contains($message, 'Do not create the account again'), 'Failure must clearly say the account already exists');
}
$mailCalls = [];
$run('admin', $lifecycle);
$assert($mailCalls === [], 'Ordinary Administrator creation stays outside this email scope');
$lifecycle->fail = true;
try {
    $run('super_admin', $lifecycle);
    throw new \LogicException('Expected create rejection');
} catch (\InvalidArgumentException $exception) {
    $assert($mailCalls === [], 'Rejected creation must never send credentials');
}
echo "Administrator welcome email contract: passed\n";

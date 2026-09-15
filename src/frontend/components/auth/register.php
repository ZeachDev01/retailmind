<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (is_logged_in()) {
    redirect_by_role();
}

$_SESSION['_flash_error'] = 'Account creation is restricted to administrators.';
header('Location: ' . app_url('?login=1'));
exit;

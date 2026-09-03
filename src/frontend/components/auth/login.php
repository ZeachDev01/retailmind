<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

if (is_logged_in()) {
    redirect_by_role();
}

header('Location: ' . app_url('?login=1'));
exit;

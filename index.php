<?php
// index.php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect_by_role();
} else {
    header('Location: ' . app_url('login.php'));
    exit;
}

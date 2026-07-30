<?php
require_once __DIR__ . '/includes/auth.php';
logout_user();
header('Location: ' . app_url('login.php'));
exit;

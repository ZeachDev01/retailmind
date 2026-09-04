<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
logout_user();
header('Location: ' . app_url('?login=1'));
exit;

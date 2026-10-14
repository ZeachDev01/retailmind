<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_role(['admin', 'super_admin', 'inventory_manager']);
header('Location: ' . app_url('components/inventory_management/replenishment_queue.php'), true, 303);
exit;

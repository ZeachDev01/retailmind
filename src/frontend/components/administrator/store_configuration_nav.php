<?php
$storeConfigurationLinks = [
    ['store-receipts', 'Store & receipts', 'shop', 'components/administrator/store_settings.php'],
    ['attention-thresholds', 'Attention thresholds', 'high_priority', 'components/administrator/store_settings.php?section=attention-thresholds'],
    ['remote-access', 'Remote Access', 'deployment', 'components/administrator/store_settings.php?section=remote-access'],
    ['database-backup', 'Database Backup', 'data_backup', 'components/administrator/database_backup.php'],
];
$remoteAccessStatus = (string)($remoteAccessStatus ?? 'offline');
?>
<nav class="settings-sidebar" aria-label="Store configuration sections">
    <?php foreach ($storeConfigurationLinks as [$section, $label, $icon, $path]): ?>
        <a href="<?= htmlspecialchars(app_url($path)) ?>"<?= $activeStoreConfigurationSection === $section ? ' class="active" aria-current="page"' : '' ?>>
            <img src="<?= htmlspecialchars(theme_url('assets/img/navigation/' . $icon . '.svg')) ?>" alt="" aria-hidden="true" width="22" height="22">
            <span class="settings-nav-copy">
                <span class="settings-nav-label"><?= htmlspecialchars($label) ?></span>
                <?php if ($section === 'remote-access'): ?>
                    <span class="settings-nav-state is-<?= $remoteAccessStatus === 'online' ? 'online' : ($remoteAccessStatus === 'starting' ? 'starting' : 'offline') ?>"><?= $remoteAccessStatus === 'online' ? 'Online' : ($remoteAccessStatus === 'starting' ? 'Connecting' : 'Offline') ?></span>
                <?php endif; ?>
            </span>
        </a>
    <?php endforeach; ?>
</nav>

<?php

$sidebarUser = currentUser();
$sidebarOrganization = currentOrganization();
$currentPage = basename($_SERVER['PHP_SELF']);

$sidebarLinks = [
    ['dashboard.php', 'fa-grid-2', 'Overview'],
    ['customers.php', 'fa-users', 'Customers'],
    ['jobs.php', 'fa-briefcase', 'Jobs'],
    ['invoices.php', 'fa-file-invoice-dollar', 'Invoices'],
    ['payments.php', 'fa-credit-card', 'Payments'],
    ['calendar.php', 'fa-calendar', 'Calendar'],
];

$channelLinks = [
    ['payment_settings.php', 'fa-money-bill-wave', 'Online payments'],
    ['chat_widget_settings.php', 'fa-comment', 'Website chat'],
    ['email_settings.php', 'fa-envelope', 'Email'],
    ['telegram_settings.php', 'fa-brands fa-telegram', 'Telegram'],
];

?>
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-mark">OP</div>
        <span>OpsPilot</span>
    </div>

    <div class="workspace-switcher">
        <div class="workspace-avatar">
            <?= e(strtoupper(substr($sidebarOrganization['name'] ?? 'O', 0, 1))) ?>
        </div>
        <div class="workspace-info">
            <strong><?= e($sidebarOrganization['name'] ?? 'Workspace') ?></strong>
            <small>Business workspace</small>
        </div>
        <i class="fa-solid fa-chevron-down"></i>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <span class="nav-label">WORKSPACE</span>
            <?php foreach ($sidebarLinks as [$href, $icon, $label]): ?>
                <a
                    href="<?= e(base_url($href)) ?>"
                    class="nav-item <?= $currentPage === $href ? 'active' : '' ?>"
                >
                    <i class="fa-solid <?= e($icon) ?>"></i>
                    <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="nav-section">
            <span class="nav-label">FRONT DESK</span>
            <a href="<?= e(base_url('inbox.php')) ?>" class="nav-item <?= in_array($currentPage, ['inbox.php', 'conversation.php'], true) ? 'active' : '' ?>">
                <i class="fa-regular fa-comments"></i>
                <span>Inbox</span>
            </a>
            <a href="<?= e(base_url('ai.php')) ?>" class="nav-item <?= $currentPage === 'ai.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-sparkles"></i>
                <span>AI Receptionist</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-label">INTELLIGENCE</span>
            <a href="<?= e(base_url('automation.php')) ?>" class="nav-item <?= $currentPage === 'automation.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span>Automations</span>
            </a>
            <a href="<?= e(base_url('reports.php')) ?>" class="nav-item <?= $currentPage === 'reports.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-line"></i>
                <span>Insights</span>
            </a>
        </div>

        <div class="nav-section">
            <span class="nav-label">CHANNELS</span>
            <?php foreach ($channelLinks as [$href, $icon, $label]): ?>
                <a
                    href="<?= e(base_url($href)) ?>"
                    class="nav-item <?= $currentPage === $href ? 'active' : '' ?>"
                >
                    <i class="<?= str_contains($icon, 'fa-brands') ? e($icon) : 'fa-solid ' . e($icon) ?>"></i>
                    <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <div class="sidebar-bottom">
        <a href="#" class="nav-item coming-soon" data-feature="Settings">
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>
        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($sidebarUser['name'] ?? 'U', 0, 1))) ?>
            </div>
            <div class="profile-info">
                <strong><?= e($sidebarUser['name'] ?? 'User') ?></strong>
                <small><?= e(ucfirst($sidebarUser['role'] ?? 'member')) ?></small>
            </div>
            <a href="<?= e(base_url('logout.php')) ?>" class="logout-icon" aria-label="Sign out" title="Sign out">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-backdrop></div>

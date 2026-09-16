<?php $flash = $flash ?? null; ?>

<header class="topbar">

    <?php if ($flash): ?>

        <div
            class="toast toast-<?= e($flash['type']) ?>"
            data-toast
        >

            <i class="
                fa-solid
                <?= $flash['type'] === 'success'
                    ? 'fa-circle-check'
                    : 'fa-circle-exclamation'
                ?>
            "></i>

            <span>
                <?= e($flash['message']) ?>
            </span>

            <button
                type="button"
                data-toast-close
            >
                <i class="fa-solid fa-xmark"></i>
            </button>

        </div>

    <?php endif; ?>

    <button
        class="mobile-menu"
        id="mobileMenu"
        aria-label="Open navigation"
        aria-controls="sidebar"
        aria-expanded="false"
    >
        <i class="fa-solid fa-bars"></i>
    </button>

    <button class="search-trigger" id="searchTrigger">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span>Search anything...</span>
        <kbd>⌘ K</kbd>
    </button>

    <div class="top-actions">
        <button
            class="icon-button theme-toggle"
            type="button"
            data-theme-toggle
            aria-label="Switch theme"
            title="Switch theme"
        >
            <i class="fa-solid fa-sun"></i>
        </button>

        <button class="icon-button" aria-label="Notifications">
            <i class="fa-regular fa-bell"></i>
            <span class="notification-dot"></span>
        </button>

        <a class="new-button" href="<?= e(base_url('customers.php')) ?>">
            <i class="fa-solid fa-plus"></i>
            New
        </a>
    </div>
</header>
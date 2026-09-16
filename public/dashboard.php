<?php

require_once __DIR__ . '/../app/bootstrap.php';

requireAuth();

$user =
    currentUser();

$organization =
    currentOrganization();

if (
    !$user ||
    !$organization
) {

    logoutUser();

    redirect(
        'login.php'
    );
}

$displayName =
    explode(
        ' ',
        trim($user['name'])
    )[0];


/*
|--------------------------------------------------------------------------
| Dashboard data
|--------------------------------------------------------------------------
*/

$pdo = db();

$userCount = 0;

$organizationCreated =
    $organization['created_at']
    ?? date('Y-m-d');


$stmt =
    $pdo->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE organization_id = ?'
    );

$stmt->execute([
    currentOrganizationId()
]);

$userCount =
    (int)
    $stmt->fetchColumn();



?>

<?php

$orgId = (int) currentUser()['organization_id'];

$stmt = db()->prepare("
    SELECT COUNT(*)
    FROM customers
    WHERE organization_id = ?
      AND status = 'active'
");

$stmt->execute([$orgId]);

$customerCount = (int) $stmt->fetchColumn();


$stmt = db()->prepare("
    SELECT COUNT(*)
    FROM jobs
    WHERE organization_id = ?
      AND status NOT IN ('completed', 'cancelled')
");

$stmt->execute([$orgId]);

$activeJobs = (int) $stmt->fetchColumn();

$stmt = db()->prepare(" 
        SELECT COUNT(*)
        FROM jobs
        WHERE organization_id = ?
            AND scheduled_start IS NOT NULL
            AND status NOT IN ('completed', 'cancelled')
");

$stmt->execute([$orgId]);

$bookingCount = (int) $stmt->fetchColumn();


$stmt = db()->prepare("
    SELECT COALESCE(
        SUM(total_amount - amount_paid),
        0
    )
    FROM invoices
    WHERE organization_id = ?
      AND status NOT IN ('paid', 'cancelled')
");

$stmt->execute([$orgId]);

$outstanding = (float) $stmt->fetchColumn();


$stmt = db()->prepare("
    SELECT COALESCE(
        SUM(amount_paid),
        0
    )
    FROM invoices
    WHERE organization_id = ?
");

$stmt->execute([$orgId]);

$collected = (float) $stmt->fetchColumn();

?>
<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="description"
        content="OpsPilot business operations dashboard."
    >

    <title>
        Overview — OpsPilot
    </title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="css/tokens.css"
    >

    <link
        rel="stylesheet"
        href="css/app.css"
    >

    <script>
        document.documentElement.dataset.theme = localStorage.getItem('opspilot-theme') || 'dark';
    </script>

</head>

<body>

<div class="app-shell">


    <!-- SIDEBAR -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="brand">

            <div class="brand-mark">
                OP
            </div>

            <span>
                OpsPilot
            </span>

        </div>


        <div class="workspace-switcher">

            <div class="workspace-avatar">
                <?= e(
                    strtoupper(
                        substr(
                            $organization['name'],
                            0,
                            1
                        )
                    )
                ) ?>
            </div>

            <div class="workspace-info">

                <strong>
                    <?= e(
                        $organization['name']
                    ) ?>
                </strong>

                <small>
                    Business workspace
                </small>

            </div>

            <i class="fa-solid fa-chevron-down"></i>

        </div>


        <nav class="main-nav">

            <span class="sidebar-label">
                Workspace
            </span>


            <a
                href="dashboard.php"
                class="nav-item active"
            >

                <i class="fa-solid fa-grid-2"></i>

                <span>
                    Overview
                </span>

            </a>


            <a
                href="#"
                class="nav-item coming-soon"
                data-feature="Inbox"
            >

                <i class="fa-solid fa-inbox"></i>

                <span>
                    Inbox
                </span>

                <span class="nav-badge">
                    4
                </span>

            </a>


            <a
                href="customers.php"
                class="nav-item"
            >

                <i class="fa-solid fa-users"></i>

                <span>
                    Customers
                </span>

            </a>


            <a
                href="#"
                class="nav-item coming-soon"
                data-feature="Leads"
            >

                <i class="fa-solid fa-filter"></i>

                <span>
                    Leads
                </span>

            </a>


            <a
                href="calendar.php"
                class="nav-item"
            >

                <i class="fa-regular fa-calendar"></i>

                <span>
                    Calendar
                </span>

            </a>


            <a
                href="jobs.php"
                class="nav-item"
            >

                <i class="fa-solid fa-briefcase"></i>

                <span>
                    Jobs
                </span>

            </a>


            <a
                href="invoices.php"
                class="nav-item"
            >

                <i class="fa-regular fa-file-lines"></i>

                <span>
                    Invoices
                </span>

            </a>


            <a
                href="payments.php"
                class="nav-item"
            >

                <i class="fa-solid fa-credit-card"></i>

                <span>
                    Payments
                </span>

            </a>

        </nav>


        <div class="sidebar-section">

            <span class="sidebar-label">
                Intelligence
            </span>


            <a
                href="ai.php"
                class="nav-item"
            >

                <i class="fa-solid fa-wand-magic-sparkles"></i>

                <span>
                    AI Assistant
                </span>

                <span class="ai-dot"></span>

            </a>


            <a
                href="reports.php"
                class="nav-item"
            >

                <i class="fa-solid fa-bolt"></i>

                <span>
                    Automations
                </span>

            </a>


            <a
                href="#"
                class="nav-item coming-soon"
                data-feature="Automations"
            >

                <i class="fa-solid fa-chart-line"></i>

                <span>
                    Reports
                </span>

            </a>

        </div>


        <div class="sidebar-bottom">

            <a
                href="#"
                class="nav-item coming-soon"
                data-feature="Settings"
            >

                <i class="fa-solid fa-gear"></i>

                <span>
                    Settings
                </span>

            </a>


            <div class="profile">

                <div class="avatar">
                    <?= e(
                        strtoupper(
                            substr(
                                $user['name'],
                                0,
                                1
                            )
                        )
                    ) ?>
                </div>

                <div class="profile-info">

                    <strong>
                        <?= e(
                            $user['name']
                        ) ?>
                    </strong>

                    <small>
                        <?= e(
                            ucfirst(
                                $user['role']
                            )
                        ) ?>
                    </small>

                </div>

                <a
                    href="logout.php"
                    class="logout-icon"
                    aria-label="Sign out"
                    title="Sign out"
                >

                    <i class="fa-solid fa-arrow-right-from-bracket"></i>

                </a>

            </div>

        </div>

    </aside>


    <!-- MAIN -->

    <main class="main-content">


        <!-- TOPBAR -->

        <?php

        $flash = get_flash();

        ?>

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


            <button
                class="search-trigger"
                id="searchTrigger"
            >

                <i class="fa-solid fa-magnifying-glass"></i>

                <span>
                    Search anything...
                </span>

                <kbd>
                    ⌘ K
                </kbd>

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

                <button
                    class="icon-button"
                    aria-label="Notifications"
                >

                    <i class="fa-regular fa-bell"></i>

                    <span class="notification-dot"></span>

                </button>


                <a
                    class="new-button"
                    href="customers.php"
                >

                    <i class="fa-solid fa-plus"></i>

                    New

                </a>

            </div>

        </header>


        <!-- DASHBOARD -->

        <section class="dashboard">


            <div class="welcome">

                <div>

                    <span class="eyebrow">
                        FRIDAY · SEPTEMBER 5
                    </span>

                    <h1>
                        Good afternoon,
                        <?= e(
                            $displayName
                        ) ?>.
                    </h1>

                    <p>
                        Here's what needs your attention today.
                    </p>

                </div>


                <a
                    class="ai-command"
                    href="ai.php"
                >

                    <span class="ai-command-icon">
                        ✦
                    </span>

                    Ask OpsPilot

                    <kbd>
                        ⌘ J
                    </kbd>

                </a>

            </div>


            <!-- PULSE -->

            <section class="pulse-card">

                <div class="pulse-glow"></div>

                <div class="pulse-header">

                    <div>

                        <span class="eyebrow">
                            BUSINESS PULSE
                        </span>

                        <h2>

                            Your workspace is

                            <span class="pulse-status">
                                ready
                            </span>

                        </h2>

                    </div>


                    <span class="live-indicator">

                        <span></span>

                        Live

                    </span>

                </div>


                <div class="metrics">


                    <article class="metric">

                        <span>
                            Team members
                        </span>

                        <strong
                            data-counter="<?= $userCount ?>"
                        >
                            <?= $userCount ?>
                        </strong>

                        <small>
                            active workspace users
                        </small>

                    </article>


                    <article class="metric">

                        <span>
                            Customers
                        </span>

                        <strong data-counter="<?= $customerCount ?>">
                            <?= $customerCount ?>
                        </strong>

                        <small>
                            active customer records
                        </small>

                    </article>


                    <article class="metric">

                        <span>
                            Bookings
                        </span>

                        <strong data-counter="<?= $bookingCount ?>">
                            <?= $bookingCount ?>
                        </strong>

                        <small>
                            scheduled bookings
                        </small>

                    </article>


                    <article class="metric">

                        <span>
                            Automation
                        </span>

                        <strong>
                            Ready
                        </strong>

                        <small class="positive">
                            AI layer coming next
                        </small>

                    </article>


                </div>

            </section>


            <div class="dashboard-grid">


                <!-- ATTENTION -->

                <section class="panel">

                    <div class="panel-heading">

                        <div>

                            <span class="eyebrow">
                                GETTING STARTED
                            </span>

                            <h2>
                                Build your workspace
                            </h2>

                        </div>

                    </div>


                    <div class="attention-list">


                        <article class="attention-item">

                            <div class="attention-icon success">

                                <i class="fa-solid fa-shield-check"></i>

                            </div>

                            <div class="attention-copy">

                                <strong>
                                    Secure workspace created
                                </strong>

                                <span>
                                    Your company data has its own isolated workspace.
                                </span>

                            </div>

                            <span class="completed-tag">
                                Complete
                            </span>

                        </article>


                        <article class="attention-item">

                            <div class="attention-icon ai">

                                <i class="fa-solid fa-users"></i>

                            </div>

                            <div class="attention-copy">

                                <strong>
                                    Add your first customers
                                </strong>

                                <span>
                                    Build the customer foundation for your AI.
                                </span>

                            </div>

                            <button
                                class="mini-button coming-soon"
                                data-feature="Customer profiles"
                            >
                                Coming soon
                            </button>

                        </article>


                        <article class="attention-item">

                            <div class="attention-icon warning">

                                <i class="fa-solid fa-calendar"></i>

                            </div>

                            <div class="attention-copy">

                                <strong>
                                    Configure your services
                                </strong>

                                <span>
                                    Tell OpsPilot what your business sells.
                                </span>

                            </div>

                            <button
                                class="mini-button coming-soon"
                                data-feature="Services"
                            >
                                Coming soon
                            </button>

                        </article>


                    </div>

                </section>


                <!-- AI -->

                <section class="panel ai-panel">

                    <div class="ai-orbit">

                        <div class="orbit-ring ring-one"></div>

                        <div class="orbit-ring ring-two"></div>

                        <div class="orbit-core">
                            ✦
                        </div>

                    </div>


                    <span class="eyebrow">
                        OPSPILOT INTELLIGENCE
                    </span>

                    <h2>
                        Your business
                        brain is coming.
                    </h2>

                    <p>
                        Soon you'll be able to ask OpsPilot
                        questions about your customers,
                        revenue, bookings and operations —
                        and let it take action.
                    </p>


                    <a
                        class="ai-action"
                        href="ai.php"
                    >

                        Explore AI

                        <i class="fa-solid fa-arrow-right"></i>

                    </a>

                </section>

            </div>


            <!-- QUICK ACTIONS -->

            <section class="quick-section">

                <div class="section-heading">

                    <div>

                        <span class="eyebrow">
                            QUICK ACTIONS
                        </span>

                        <h2>
                            Make something happen.
                        </h2>

                    </div>

                </div>


                <div class="quick-grid">


                    <a class="quick-card" href="customers.php">

                        <span class="quick-icon">
                            <i class="fa-solid fa-user-plus"></i>
                        </span>

                        <span>

                            <strong>
                                Add customer
                            </strong>

                            <small>
                                Create a customer record
                            </small>

                        </span>

                        <i class="fa-solid fa-arrow-up-right-from-square"></i>

                    </a>


                    <a class="quick-card" href="calendar.php">

                        <span class="quick-icon">
                            <i class="fa-solid fa-calendar-plus"></i>
                        </span>

                        <span>

                            <strong>
                                New booking
                            </strong>

                            <small>
                                Schedule an appointment
                            </small>

                        </span>

                        <i class="fa-solid fa-arrow-up-right-from-square"></i>

                    </a>


                    <a class="quick-card" href="invoices.php">

                        <span class="quick-icon">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </span>

                        <span>

                            <strong>
                                Create invoice
                            </strong>

                            <small>
                                Bill a customer
                            </small>

                        </span>

                        <i class="fa-solid fa-arrow-up-right-from-square"></i>

                    </a>


                    <button
                        class="quick-card ai-quick"
                        data-command-palette
                    >

                        <span class="quick-icon">
                            ✦
                        </span>

                        <span>

                            <strong>
                                Ask OpsPilot
                            </strong>

                            <small>
                                Let AI help you
                            </small>

                        </span>

                        <i class="fa-solid fa-arrow-up-right-from-square"></i>

                    </button>


                </div>

            </section>

        </section>

    </main>

</div>


<!-- COMMAND PALETTE -->

<div
    class="command-overlay"
    id="commandOverlay"
    aria-hidden="true"
>

    <div
        class="command-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="commandTitle"
    >

        <div class="command-input-wrap">

            <i class="fa-solid fa-magnifying-glass"></i>

            <input
                id="commandInput"
                type="text"
                placeholder="Search or ask OpsPilot..."
                autocomplete="off"
            >

            <kbd>
                ESC
            </kbd>

        </div>


        <div class="command-suggestions">

            <span class="eyebrow">
                SUGGESTED ACTIONS
            </span>


            <button class="command-item">

                <span>
                    <i class="fa-solid fa-users"></i>
                </span>

                Find customers

            </button>


            <button class="command-item">

                <span>
                    <i class="fa-solid fa-calendar"></i>
                </span>

                Show today's schedule

            </button>


            <button class="command-item">

                <span>
                    <i class="fa-solid fa-chart-line"></i>
                </span>

                Analyze my business

            </button>


            <button class="command-item">

                <span>
                    ✦
                </span>

                Ask AI anything

            </button>

        </div>

    </div>

</div>

<div class="metrics-grid">

    <div class="metric-card glass-panel">

        <div class="metric-icon">
            <i class="fa-solid fa-users"></i>
        </div>

        <span>Active customers</span>

        <strong
            data-count="<?= $customerCount ?>"
        >
            0
        </strong>

        <small>
            Your customer base
        </small>

    </div>


    <div class="metric-card glass-panel">

        <div class="metric-icon">
            <i class="fa-solid fa-briefcase"></i>
        </div>

        <span>Active jobs</span>

        <strong
            data-count="<?= $activeJobs ?>"
        >
            0
        </strong>

        <small>
            Work currently moving
        </small>

    </div>


    <div class="metric-card glass-panel">

        <div class="metric-icon">
            <i class="fa-solid fa-arrow-trend-up"></i>
        </div>

        <span>Collected</span>

        <strong>
            ₦<?= number_format($collected, 0) ?>
        </strong>

        <small>
            Recorded invoice payments
        </small>

    </div>


    <div class="metric-card glass-panel">

        <div class="metric-icon">
            <i class="fa-solid fa-wallet"></i>
        </div>

        <span>Outstanding</span>

        <strong>
            ₦<?= number_format($outstanding, 0) ?>
        </strong>

        <small>
            Money still to collect
        </small>

    </div>

</div>


<script src="js/app.js"></script>

</body>

</html>
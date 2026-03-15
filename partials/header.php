<?php
// partials/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../db.php';

$user = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
    }
}

$site_name = 'InfoCrop AI';
try {
    $stmt_settings = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'");
    if ($stmt_settings) {
        $site_name = $stmt_settings->fetchColumn() ?: 'InfoCrop AI';
    }
} catch (PDOException $e) {
    error_log("Database connection error (site_name): " . $e->getMessage());
}

$name_part1 = $site_name;
$name_part2 = '';
if (stripos($site_name, 'Crop') !== false) {
    $parts = preg_split('/(Crop)/i', $site_name, -1, PREG_SPLIT_DELIM_CAPTURE);
    $name_part1 = $parts[0];
    $name_part2 = $parts[1] . ($parts[2] ?? '');
}

$cur_page = basename($_SERVER['PHP_SELF']);

// Credit percentage for progress bar
$credit_pct = 0;
if ($user && $user['usage_limit'] > 0) {
    $credit_pct = round(($user['usage_count'] / $user['usage_limit']) * 100);
}
?>
<header class="main-header" id="mainHeader">
    <div class="header-inner">

        <!-- Logo -->
        <a href="index.php" class="logo" aria-label="<?php echo htmlspecialchars($site_name); ?> home">
            <div class="logo-icon" aria-hidden="true">🌿</div>
            <span class="logo-text">
                <?php echo htmlspecialchars($name_part1); ?><em><?php echo htmlspecialchars($name_part2); ?></em>
            </span>
        </a>

        <?php if ($user): ?>

        <!-- Desktop nav -->
        <nav class="nav-links" aria-label="Main navigation">
            <a href="dashboard.php"      class="nav-item <?= $cur_page === 'dashboard.php'      ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">📊</span> Dashboard
            </a>
            <a href="index.php"          class="nav-item <?= $cur_page === 'index.php'          ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">🚜</span> Farm Planner
            </a>
            <a href="report_history.php" class="nav-item <?= $cur_page === 'report_history.php' ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">📋</span> My Reports
            </a>
            <a href="plans.php"          class="nav-item plans-link <?= $cur_page === 'plans.php' ? 'active' : '' ?>">
                <span class="nav-icon" aria-hidden="true">💎</span> Plans
            </a>
        </nav>

        <!-- Right: credits + user -->
        <div class="header-right">

            <!-- Credit pill -->
            <a href="plans.php" class="credits-pill" title="<?php echo $user['usage_count']; ?> of <?php echo $user['usage_limit']; ?> credits used">
                <div class="credits-pill-inner">
                    <span class="credits-label">Credits</span>
                    <span class="credits-count">
                        <strong><?php echo (int)$user['usage_count']; ?></strong>
                        <span class="credits-sep">/</span>
                        <?php echo (int)$user['usage_limit']; ?>
                    </span>
                </div>
                <div class="credits-bar" role="progressbar" aria-valuenow="<?php echo $credit_pct; ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="credits-bar-fill <?php echo $credit_pct >= 90 ? 'bar-danger' : ($credit_pct >= 70 ? 'bar-warn' : ''); ?>"
                         style="width: <?php echo min($credit_pct, 100); ?>%"></div>
                </div>
            </a>

            <!-- User dropdown -->
            <div class="user-menu" id="userMenu">
                <button class="user-trigger" id="userTrigger" aria-expanded="false" aria-haspopup="true">
                    <div class="user-avatar" aria-hidden="true">
                        <?php echo mb_strtoupper(mb_substr($user['name'], 0, 1)); ?>
                    </div>
                    <span class="user-name-short"><?php echo htmlspecialchars(explode(' ', $user['name'])[0]); ?></span>
                    <svg class="chevron" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                        <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="user-dropdown" id="userDropdown" role="menu">
                    <div class="dropdown-user-info">
                        <div class="dui-avatar" aria-hidden="true"><?php echo mb_strtoupper(mb_substr($user['name'], 0, 1)); ?></div>
                        <div>
                            <div class="dui-name"><?php echo htmlspecialchars($user['name']); ?></div>
                            <div class="dui-phone"><?php echo htmlspecialchars($user['phone'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="dashboard.php" class="dropdown-item" role="menuitem">
                        <span aria-hidden="true">📊</span> Dashboard
                    </a>
                    <a href="plans.php" class="dropdown-item" role="menuitem">
                        <span aria-hidden="true">💎</span> Upgrade Plan
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item dropdown-logout" role="menuitem">
                        <span aria-hidden="true">🚪</span> Sign Out
                    </a>
                </div>
            </div>

            <!-- Hamburger (mobile) -->
            <button class="hamburger" id="hambBtn" aria-label="Open navigation menu" aria-expanded="false" aria-controls="mobileNav">
                <span></span><span></span><span></span>
            </button>
        </div>

        <!-- Mobile drawer -->
        <nav class="mobile-nav" id="mobileNav" aria-label="Mobile navigation" aria-hidden="true">
            <div class="mobile-user-card">
                <div class="muc-avatar" aria-hidden="true"><?php echo mb_strtoupper(mb_substr($user['name'], 0, 1)); ?></div>
                <div class="muc-info">
                    <div class="muc-name"><?php echo htmlspecialchars($user['name']); ?></div>
                    <div class="muc-credits">
                        <?php echo (int)$user['usage_count']; ?> / <?php echo (int)$user['usage_limit']; ?> credits used
                    </div>
                    <div class="muc-bar">
                        <div class="muc-bar-fill <?php echo $credit_pct >= 90 ? 'bar-danger' : ($credit_pct >= 70 ? 'bar-warn' : ''); ?>"
                             style="width: <?php echo min($credit_pct, 100); ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="mobile-nav-links">
                <a href="dashboard.php"      class="mnav-item <?= $cur_page === 'dashboard.php'      ? 'active' : '' ?>">
                    <span class="mnav-icon" aria-hidden="true">📊</span>
                    <span>Dashboard</span>
                    <svg class="mnav-arrow" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="index.php"          class="mnav-item <?= $cur_page === 'index.php'          ? 'active' : '' ?>">
                    <span class="mnav-icon" aria-hidden="true">🚜</span>
                    <span>Farm Planner</span>
                    <svg class="mnav-arrow" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="report_history.php" class="mnav-item <?= $cur_page === 'report_history.php' ? 'active' : '' ?>">
                    <span class="mnav-icon" aria-hidden="true">📋</span>
                    <span>My Reports</span>
                    <svg class="mnav-arrow" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="plans.php"          class="mnav-item <?= $cur_page === 'plans.php'          ? 'active' : '' ?>">
                    <span class="mnav-icon" aria-hidden="true">💎</span>
                    <span>Upgrade Plans</span>
                    <svg class="mnav-arrow" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M5 3l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>

            <div class="mobile-nav-footer">
                <a href="logout.php" class="mnav-logout">
                    <span aria-hidden="true">🚪</span> Sign Out
                </a>
            </div>
        </nav>

        <?php else: ?>

        <!-- Guest auth buttons -->
        <div class="auth-btns" role="navigation" aria-label="Account actions">
            <a href="login.php"  class="auth-btn auth-btn-outline">Log In</a>
            <a href="signup.php" class="auth-btn auth-btn-solid">Sign Up Free</a>
        </div>

        <?php endif; ?>

    </div>
</header>

<link rel="stylesheet" href="assets/css/header.css">

<script>
(function () {
    // ── Scroll shadow ──
    const header = document.getElementById('mainHeader');
    if (header) {
        window.addEventListener('scroll', function () {
            header.classList.toggle('scrolled', window.scrollY > 10);
        }, { passive: true });
    }

    // ── User dropdown ──
    const trigger  = document.getElementById('userTrigger');
    const dropdown = document.getElementById('userDropdown');

    if (trigger && dropdown) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = dropdown.classList.contains('open');
            dropdown.classList.toggle('open', !isOpen);
            trigger.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function () {
            dropdown.classList.remove('open');
            trigger?.setAttribute('aria-expanded', 'false');
        });

        dropdown.addEventListener('click', function (e) { e.stopPropagation(); });

        // Keyboard: close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                dropdown.classList.remove('open');
                trigger?.setAttribute('aria-expanded', 'false');
                trigger?.focus();
            }
        });
    }

    // ── Hamburger / mobile drawer ──
    const hambBtn   = document.getElementById('hambBtn');
    const mobileNav = document.getElementById('mobileNav');

    if (hambBtn && mobileNav) {
        hambBtn.addEventListener('click', function () {
            const isOpen = mobileNav.classList.contains('open');
            mobileNav.classList.toggle('open', !isOpen);
            hambBtn.classList.toggle('open', !isOpen);
            hambBtn.setAttribute('aria-expanded', String(!isOpen));
            mobileNav.setAttribute('aria-hidden', String(isOpen));
        });

        // Close drawer when a link is tapped
        mobileNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                mobileNav.classList.remove('open');
                hambBtn.classList.remove('open');
                hambBtn.setAttribute('aria-expanded', 'false');
                mobileNav.setAttribute('aria-hidden', 'true');
            });
        });
    }
})();
</script>
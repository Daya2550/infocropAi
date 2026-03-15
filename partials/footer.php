<?php
// partials/footer.php — Shared footer for InfoCrop AI
require_once __DIR__ . '/../db.php';

$stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM settings");
$sys_settings = [];
while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
    $sys_settings[$row['setting_key']] = $row['setting_value'];
}
$site_name       = $sys_settings['site_name']       ?? 'InfoCrop AI';
$contact_email   = $sys_settings['contact_email']   ?? 'jagadledayanand2550@gmail.com';
$contact_phone   = $sys_settings['contact_phone']   ?? '+91 8010094034';
$contact_address = $sys_settings['contact_address'] ?? 'New Delhi, India';
?>

<footer class="fp-footer">

    <!-- Wave separator -->
    <div class="footer-wave" aria-hidden="true">
        <svg viewBox="0 0 1440 60" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
            <path d="M0 30 C240 60 480 0 720 30 C960 60 1200 0 1440 30 L1440 60 L0 60 Z" fill="#0f2a15" opacity="0.04"/>
            <path d="M0 40 C360 10 720 55 1080 20 C1260 8 1380 35 1440 40 L1440 60 L0 60 Z" fill="#0f2a15" opacity="0.03"/>
        </svg>
    </div>

    <div class="footer-inner">

        <!-- Main grid -->
        <div class="footer-grid">

            <!-- Brand col -->
            <div class="footer-brand">
                <a href="index.php" class="footer-logo-link" aria-label="InfoCrop home">
                    <div class="footer-logo-icon" aria-hidden="true">🌿</div>
                    <span class="footer-logo-text"><?php echo htmlspecialchars($site_name); ?></span>
                </a>
                <p class="footer-tagline">
                    Empowering Indian farmers with real-time AI guidance for a more profitable and sustainable future.
                </p>
                <div class="footer-badge">
                    <span class="fbadge-dot" aria-hidden="true"></span>
                    AI-Powered · Made for 🇮🇳 India
                </div>
            </div>

            <!-- Quick Links -->
            <div class="footer-col">
                <h4 class="footer-col-title">Quick Links</h4>
                <ul class="footer-nav" role="list">
                    <li>
                        <a href="index.php" class="footer-link">
                            <span aria-hidden="true">🚜</span> Farm Planner
                        </a>
                    </li>
                    <li>
                        <a href="smart_planner.php" class="footer-link footer-link-accent">
                            <span aria-hidden="true">⚡</span> Smart Reality Check
                        </a>
                    </li>
                    <li>
                        <a href="report_history.php" class="footer-link">
                            <span aria-hidden="true">📋</span> My Reports
                        </a>
                    </li>
                    <li>
                        <a href="plans.php" class="footer-link">
                            <span aria-hidden="true">💎</span> Premium Plans
                        </a>
                    </li>
                    <li>
                        <a href="dashboard.php" class="footer-link">
                            <span aria-hidden="true">📊</span> Dashboard
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Contact -->
            <div class="footer-col">
                <h4 class="footer-col-title">Contact Support</h4>
                <ul class="footer-contact-list" role="list">
                    <li class="footer-contact-item">
                        <span class="fci-icon" aria-hidden="true">📍</span>
                        <span><?php echo htmlspecialchars($contact_address); ?></span>
                    </li>
                    <li class="footer-contact-item">
                        <span class="fci-icon" aria-hidden="true">✉️</span>
                        <a href="mailto:<?php echo htmlspecialchars($contact_email); ?>" class="footer-contact-link">
                            <?php echo htmlspecialchars($contact_email); ?>
                        </a>
                    </li>
                    <li class="footer-contact-item">
                        <span class="fci-icon" aria-hidden="true">📞</span>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $contact_phone)); ?>" class="footer-contact-link">
                            <?php echo htmlspecialchars($contact_phone); ?>
                        </a>
                    </li>
                </ul>

                <div class="footer-trust">
                    <div class="trust-pill">🔒 Secure</div>
                    <div class="trust-pill">🛡️ Private</div>
                    <div class="trust-pill">✅ Verified</div>
                </div>
            </div>

        </div>

        <!-- Bottom bar -->
        <div class="footer-bottom">
            <div class="footer-bottom-inner">
                <span class="footer-copy">
                    &copy; <?php echo date('Y'); ?>
                    <a href="index.php" class="footer-copy-link"><?php echo htmlspecialchars($site_name); ?></a>
                    · All rights reserved
                </span>
                <div class="footer-bottom-links">
                    <a href="privacy-policy.php" class="footer-policy-link">Privacy Policy</a>
                    <span aria-hidden="true">·</span>
                    <a href="terms-of-use.php" class="footer-policy-link">Terms of Use</a>
                </div>
            </div>
        </div>

    </div>
</footer>

<link rel="stylesheet" href="assets/css/footer.css">

<?php include __DIR__ . '/translator.php'; ?>
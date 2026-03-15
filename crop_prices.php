<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
$crop_id = isset($_GET['crop_id']) ? (int)$_GET['crop_id'] : null;

// Fetch all reports — one entry per crop name (latest smart wins, else latest farm)
$stmt = $pdo->prepare("
    (SELECT s.id, s.crop, s.location, s.created_at, 'smart' as source
     FROM smart_reports s
     INNER JOIN (
         SELECT LOWER(crop) as crop_key, MAX(id) as max_id FROM smart_reports WHERE user_id = ? GROUP BY LOWER(crop)
     ) latest ON s.id = latest.max_id AND s.user_id = ?)
    " . "UNION" . " ALL
    (SELECT f.id, f.crop, f.location, f.created_at, 'farm' as source
     FROM farm_reports f
     INNER JOIN (
         SELECT LOWER(crop) as crop_key, MAX(id) as max_id FROM farm_reports WHERE user_id = ? GROUP BY LOWER(crop)
     ) lf ON f.id = lf.max_id AND f.user_id = ?
     WHERE NOT EXISTS (
         SELECT 1 FROM smart_reports s2 WHERE LOWER(s2.crop) = LOWER(f.crop) AND s2.user_id = ?
     ))
    ORDER BY created_at DESC
");
$stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
$all_reports = $stmt->fetchAll();

$source = isset($_GET['source']) ? $_GET['source'] : null;

if (!$crop_id && !empty($all_reports)) {
    $crop_id = $all_reports[0]['id'];
    $source = $all_reports[0]['source'];
}

$current_report = null;
if ($crop_id) {
    foreach ($all_reports as $r) {
        if ($r['id'] === $crop_id && ($source === null || $r['source'] === $source)) {
            $current_report = $r;
            break;
        }
    }
}

$market_data = "";
$error = "";

if ($current_report && isset($_POST['get_prices'])) {
    // Credit Check
    $stmt_u = $pdo->prepare("SELECT usage_limit, usage_count FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $u = $stmt_u->fetch();
    if (!$u || ((float)$u['usage_limit'] - (float)$u['usage_count']) < 0.1) {
        $error = "Insufficient credits (0.1cr needed). Please top up.";
    } else {
        $crop = $current_report['crop'];
        $loc = $current_report['location'];
        $today = date('F d, Y');

        // Get Historical Context (Initial Plan + Previous Smart Check)
        $hi_context = get_crop_ai_context($pdo, $user_id, $crop, ($source === 'smart' ? ($current_report['base_report_id'] ?? null) : $crop_id));

        $prompt = "You are a market analyst specialized in Indian Agriculture (APMC Mandis). provide a market intelligence report for:
        
        CROP: $crop
        REGION: $loc
        DATE: $today

        {$hi_context}
        
        Provide:
        1. Current average price range (per quintal) in major mandis of this region.
        2. Price trend for the next 15 days (Rising/Stable/Falling).
        3. Major factor influencing price (e.g. arrivals, demand, weather).
        4. Recommendation (Sell now or Hold/Wait).
        5. Nearby 2 mandis where price might be better.
        
        Format as clear Markdown with headers.";

        $market_data = run_gemini_stage($prompt);
        if (strncmp($market_data, '[AI_ERROR]', 10) === 0) {
            $error = substr($market_data, 10);
            $market_data = "";
        } else {
            // Deduct Credit
            $pdo->prepare("UPDATE users SET usage_count = usage_count + 0.1 WHERE id = ?")->execute([$user_id]);
        }
    }
}

// Fetch System Settings
$sys = [];
foreach ($pdo->query("SELECT * FROM settings") as $s) $sys[$s['setting_key']] = $s['setting_value'];
$site_name = $sys['site_name'] ?? 'InfoCrop AI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mandi Prices — <?= htmlspecialchars($site_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/features.css">
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="page-wrap">
    <div class="page-head">
        <div class="page-head-left">
            <h1>💹 Mandi Price Insights</h1>
            <p>AI-powered market intelligence for your crop & region.</p>
        </div>
        <div class="crop-bar">
            <label>Select Crop:</label>
            <select onchange="window.location.href='crop_prices.php?crop_id=' + this.value.split('|')[0] + '&source=' + this.value.split('|')[1]">
                <?php foreach ($all_reports as $r): ?>
                    <?php $val = $r['id'] . '|' . $r['source']; ?>
                    <option value="<?= $val ?>" <?= ($r['id'] == $crop_id && $r['source'] == $source) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['crop']) ?> (<?= $r['source'] == 'farm' ? 'Initial' : 'Smart' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="fc-grid">
        <div>
            <div class="fc-card">
                <div class="fc-card-title">🔍 Get Market Intelligence</div>
                <?php if ($current_report): ?>
                    <p style="font-size:0.88rem;color:var(--muted);margin-bottom:16px;">
                        Crop: <strong><?= htmlspecialchars($current_report['crop']) ?></strong> &nbsp;&bull;&nbsp;
                        Region: <strong><?= htmlspecialchars($current_report['location'] ?? 'N/A') ?></strong>
                    </p>
                    <form method="POST">
                        <button type="submit" name="get_prices" class="fc-btn fc-btn-primary">🔍 Get Mandi Intelligence (0.1cr)</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state" style="border:none;padding:20px 0;">
                        <div class="es-icon">🏦</div>
                        <p>Please complete a Smart Reality Check first to use this feature.</p>
                        <a href="smart_planner.php" class="fc-btn fc-btn-primary" style="max-width:220px;margin:0 auto;">Run Smart Check</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($market_data): ?>
                <div class="fc-card">
                    <div class="fc-card-title">📊 Market Report</div>
                    <div class="ai-result"><?= render_ai_html($market_data) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="sidebar-widget info">
                <div class="widget-title">💡 How It Works</div>
                <p style="font-size:0.83rem;color:var(--muted);line-height:1.6;">
                    The AI analyses current APMC mandi arrivals, seasonal demand trends, and your crop’s regional market history to provide an intelligent pricing forecast.
                </p>
            </div>
            <div class="sidebar-widget">
                <div class="widget-title">💡 Tips</div>
                <ul style="font-size:0.82rem;color:var(--muted);padding-left:16px;line-height:1.8;">
                    <li>Run after Smart Check for best accuracy</li>
                    <li>Compare 2-3 crops to find best value</li>
                    <li>Check 7-10 days before harvest</li>
                </ul>
            </div>
            <a href="dashboard.php?crop_id=<?= $crop_id ?>&source=<?= $source ?>" class="fc-btn fc-btn-outline" style="width:100%;justify-content:center;">← Dashboard</a>
        </div>
    </div>
</div>
</main>

<?php include 'partials/footer.php'; ?>
</body>
</html>

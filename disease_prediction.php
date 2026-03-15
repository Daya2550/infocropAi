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
    (SELECT s.id, s.crop, s.detected_stage, s.location, s.created_at, 'smart' as source
     FROM smart_reports s
     INNER JOIN (
         SELECT LOWER(crop) as crop_key, MAX(id) as max_id FROM smart_reports WHERE user_id = ? GROUP BY LOWER(crop)
     ) latest ON s.id = latest.max_id AND s.user_id = ?)
    " . "UNION" . " ALL
    (SELECT f.id, f.crop, 'Initial/Seedling Stage' as detected_stage, f.location, f.created_at, 'farm' as source
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

$prediction = "";
$error = "";

if ($current_report && isset($_POST['predict'])) {
    // Translate any non-English inputs to English
    $_POST = translate_inputs_to_english($_POST);

    // Credit Check
    $stmt_u = $pdo->prepare("SELECT usage_limit, usage_count FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $u = $stmt_u->fetch();
    if (!$u || ((float)$u['usage_limit'] - (float)$u['usage_count']) < 0.1) {
        $error = "Insufficient credits (0.1cr needed). Please top up.";
    } else {
        $crop = $current_report['crop'];
        $stage = $current_report['detected_stage'];
        $loc = $current_report['location'];
        $today = date('F d, Y');

        $observations = isset($_POST['observations']) ? trim($_POST['observations']) : "None reported.";
        
        // Get Historical Context (Initial Plan + Previous Smart Check)
        $hi_context = get_crop_ai_context($pdo, $user_id, $crop, ($source === 'smart' ? ($current_report['base_report_id'] ?? null) : $crop_id));

        $prompt = "You are an expert plant pathologist. Based on the following data and farmer's observations, predict potential disease outbreaks for this crop in the next 15 days in this region. 
        
        CROP: $crop
        GROWTH STAGE: $stage
        LOCATION: $loc
        FARMER'S OBSERVATIONS: $observations
        TODAY'S DATE: $today

        {$hi_context}
        
        Provide:
        1. Top 3 suspected diseases/pests based on seasonal patterns and observations.
        2. Risk level (High/Medium/Low) for each.
        3. Early symptoms to watch for.
        4. Preventive actions (chemical and organic).
        5. A short weather warning if relevant.
        
        Format nicely with Markdown headers.";

        $prediction = run_gemini_stage($prompt);
        if (strncmp($prediction, '[AI_ERROR]', 10) === 0) {
            $error = substr($prediction, 10);
            $prediction = "";
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
    <title>Disease Prediction — <?= htmlspecialchars($site_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/features.css">
    <style>
        textarea.fc-input { min-height: 90px; resize: vertical; }
    </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="page-wrap">
    <div class="page-head">
        <div class="page-head-left">
            <h1>🩺 AI Disease Prediction</h1>
            <p>Identify crop diseases early using AI analysis.</p>
        </div>
        <div class="crop-bar">
            <label>Select Crop:</label>
            <select onchange="window.location.href='disease_prediction.php?crop_id=' + this.value.split('|')[0] + '&source=' + this.value.split('|')[1]">
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
                <div class="fc-card-title">🔍 Describe Observations</div>
                <?php if ($current_report): ?>
                    <p style="font-size:0.88rem;color:var(--muted);margin-bottom:16px;">
                        Crop: <strong><?= htmlspecialchars($current_report['crop']) ?></strong> &nbsp;&bull;&nbsp;
                        Stage: <strong><?= htmlspecialchars($current_report['detected_stage'] ?? 'Initial') ?></strong>
                    </p>
                    <form method="POST" class="fc-form">
                        <div>
                            <label class="fc-label">Field Observations (Optional)</label>
                            <textarea name="observations" class="fc-input" placeholder="Describe any yellow spots, insects, wilting, or unusual plant growth you see... Leave blank for AI to use crop-stage data only."></textarea>
                        </div>
                        <button type="submit" name="predict" class="fc-btn fc-btn-primary">✨ Generate AI Disease Forecast (0.1cr)</button>
                    </form>
                <?php else: ?>
                    <div class="empty-state" style="border:none;padding:20px 0;">
                        <div class="es-icon">🌱</div>
                        <p>No active crop plan found. Please create one in Farm Planner first.</p>
                        <a href="index.php" class="fc-btn fc-btn-primary" style="max-width:200px;margin:0 auto;">Go to Farm Planner</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($prediction): ?>
                <div class="fc-card">
                    <div class="fc-card-title">📊 Disease Analysis Report</div>
                    <div class="ai-result"><?= render_ai_html($prediction) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="sidebar-widget danger">
                <div class="widget-title">🚨 Early Warning Signs</div>
                <ul style="font-size:0.82rem;color:var(--muted);padding-left:16px;line-height:1.9;">
                    <li>Yellow/brown spots on leaves</li>
                    <li>Wilting despite watering</li>
                    <li>White powdery coating</li>
                    <li>Rotting at stem base</li>
                    <li>Insect holes or webbing</li>
                </ul>
            </div>
            <div class="sidebar-widget">
                <div class="widget-title">💡 Better Results</div>
                <p style="font-size:0.82rem;color:var(--muted);line-height:1.6;">For best accuracy, describe specific symptoms (color, location on plant, affected %). The AI uses your historical crop data + current stage automatically.</p>
            </div>
            <a href="dashboard.php?crop_id=<?= $crop_id ?>&source=<?= $source ?>" class="fc-btn fc-btn-outline" style="width:100%;justify-content:center;">← Dashboard</a>
        </div>
    </div>
</div>
</main>

<?php include 'partials/footer.php'; ?>
</body>
</html>


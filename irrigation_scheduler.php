<?php
session_start();
require_once 'db.php';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
$crop_id = isset($_GET['crop_id']) ? (int)$_GET['crop_id'] : null;

// Fetch all reports — one entry per crop name (latest smart wins, else latest farm)
$stmt = $pdo->prepare("
    (SELECT s.id, s.crop, s.created_at, 'smart' as source
     FROM smart_reports s
     INNER JOIN (
         SELECT LOWER(crop) as crop_key, MAX(id) as max_id FROM smart_reports WHERE user_id = ? GROUP BY LOWER(crop)
     ) latest ON s.id = latest.max_id AND s.user_id = ?)
    " . "UNION" . " ALL
    (SELECT f.id, f.crop, f.created_at, 'farm' as source
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

// Fetch Irrigation & Sprinkling Tasks (Only for smart reports)
$irrigation_tasks = [];
if ($source === 'smart') {
    $tasks_stmt = $pdo->prepare("SELECT * FROM crop_tasks WHERE user_id = ? AND smart_report_id = ? AND category IN ('irrigation', 'sprinkling') ORDER BY due_date ASC");
    $tasks_stmt->execute([$user_id, $crop_id]);
    $irrigation_tasks = $tasks_stmt->fetchAll();
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
    <title>Irrigation Scheduler — <?= htmlspecialchars($site_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/global.css">
    <style>
        :root { --primary: #1565c0; --bg: #f0f4f8; --card: #ffffff; --radius: 12px; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); }
        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .card { background: var(--card); border-radius: var(--radius); padding: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .schedule-item { display: flex; align-items: center; gap: 20px; padding: 20px; border-left: 5px solid var(--primary); background: #fff; border-radius: 8px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .date-box { text-align: center; min-width: 60px; }
        .date-day { font-size: 1.5rem; font-weight: 800; color: var(--primary); }
        .date-month { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #666; }
        .task-info { flex: 1; }
        .task-title { font-weight: 800; font-size: 1.1rem; margin-bottom: 5px; }
        .task-desc { font-size: 0.9rem; color: #444; line-height: 1.5; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-pending { background: #e3f2fd; color: #1565c0; }
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        
        @media (max-width: 480px) {
            .schedule-item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .date-box { display: flex; align-items: baseline; gap: 8px; border-bottom: 1px solid #eee; padding-bottom: 8px; width: 100%; text-align: left; }
            .date-day { font-size: 1.25rem; }
            .status-badge { align-self: flex-start; }
            .container { padding: 0 10px; }
            .card { padding: 20px 15px; }
        }
    </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="container">
    <h1 style="font-weight: 800; color: var(--primary); margin-bottom: 30px;">💧 Irrigation Scheduler</h1>

    <div class="card" style="padding: 15px 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="font-weight: 700; color: #666;">Watering Schedule for:</span>
            <select onchange="window.location.href='irrigation_scheduler.php?crop_id=' + this.value.split('|')[0] + '&source=' + this.value.split('|')[1]" style="border:none; font-weight:800; color:var(--primary); font-size: 1rem; outline:none; background:transparent;">
                <?php foreach ($all_reports as $r): ?>
                    <?php $val = $r['id'] . '|' . $r['source']; ?>
                    <option value="<?= $val ?>" <?= ($r['id'] == $crop_id && $r['source'] == $source) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['crop']) ?> (<?= $r['source'] == 'farm' ? 'Initial' : 'Smart' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($source === 'farm'): ?>
        <div class="card" style="text-align: center; border: 1.5px dashed var(--primary); padding: 40px 20px;">
            <div style="font-size: 3rem; margin-bottom: 20px;">🚜</div>
            <h3 style="color: var(--primary); margin-bottom: 10px;">Setup Management First</h3>
            <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">To generate a specific watering timeline, please visit the dashboard and click "Generate AI Management".</p>
            <a href="dashboard.php?crop_id=<?= $crop_id ?>&source=farm" class="btn-action btn-primary" style="background:var(--primary); color:#fff; text-decoration:none; padding:10px 25px; border-radius:20px; font-weight:800;">Go to Dashboard</a>
        </div>
    <?php elseif (empty($irrigation_tasks)): ?>
        <div class="card" style="text-align: center; color: #888; padding: 40px 20px;">
            <div style="font-size: 3rem; margin-bottom: 20px;">💧</div>
            <p style="font-weight: 700; color: #444; margin-bottom: 10px;">No irrigation or sprinkling tasks found.</p>
            <p style="font-size: 0.9rem; margin-bottom: 20px;">To generate a specific watering schedule for <strong><?= htmlspecialchars($current_report['crop'] ?? 'your crop') ?></strong>, please complete a new Smart Reality Check.</p>
            <a href="smart_planner.php?step=1&prefill_report=<?= $current_report['base_report_id'] ?? '' ?>" class="btn-action btn-primary" style="background:var(--primary); color:#fff; text-decoration:none; padding:10px 20px; border-radius:20px;">⚡ Start Smart Check</a>
        </div>
    <?php else: ?>
        <?php foreach ($irrigation_tasks as $t): ?>
            <div class="schedule-item">
                <div class="date-box">
                    <div class="date-day"><?= date('d', strtotime($t['due_date'])) ?></div>
                    <div class="date-month"><?= date('M', strtotime($t['due_date'])) ?></div>
                </div>
                <div class="task-info">
                    <div class="task-title"><?= htmlspecialchars($t['title']) ?></div>
                    <div class="task-desc"><?= htmlspecialchars($t['description']) ?></div>
                </div>
                <div class="status-badge status-<?= $t['status'] ?>"><?= $t['status'] ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 30px;">
        <a href="dashboard.php?crop_id=<?= $crop_id ?>&source=<?= $source ?>" style="color: #666; text-decoration: none; font-weight: 700; background: #fff; padding: 10px 25px; border-radius: 30px; border: 1.5px solid #ddd; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">← Back to Dashboard</a>
    </div>
</div>
</main>

<?php include 'partials/footer.php'; ?>
</body>
</html>

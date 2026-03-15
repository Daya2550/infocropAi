<?php
session_start();

// Redirect away from empty query params to avoid WAF blocking on hardened hosts
if (isset($_GET['crop_id']) && $_GET['crop_id'] === '') {
    header('Location: dashboard.php'); exit;
}

require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';

require_once 'partials/db_queries.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare(sql_all_reports_union());
$stmt->execute([$user_id, $user_id, $user_id]);
$all_reports = $stmt->fetchAll();

$crop_id = isset($_GET['crop_id']) ? (int)$_GET['crop_id'] : null;
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

// Fetch News from crop_news table
$news_stmt = $pdo->prepare("SELECT * FROM crop_news WHERE crop_name = ? ORDER BY created_at DESC LIMIT 5");
$news_stmt->execute([$current_report['crop'] ?? 'General']);
$all_news = $news_stmt->fetchAll();

// Fetch Tasks
$fetch_id = $crop_id;
if ($source === 'farm') {
    $stmt_bridge = $pdo->prepare("SELECT id FROM smart_reports WHERE base_report_id = ? AND user_id = ? LIMIT 1");
    $stmt_bridge->execute([$crop_id, $user_id]);
    $fetch_id = $stmt_bridge->fetchColumn() ?: 0;
}

$tasks_stmt = $pdo->prepare("SELECT * FROM crop_tasks WHERE user_id = ? AND smart_report_id = ? ORDER BY due_date ASC");
$tasks_stmt->execute([$user_id, $fetch_id]);
$all_tasks = $tasks_stmt->fetchAll();

// Fetch Latest Health Snapshot
$health_stmt = $pdo->prepare("SELECT * FROM crop_health_snapshots WHERE user_id = ? AND smart_report_id = ? ORDER BY snapshot_date DESC, id DESC LIMIT 1");
$health_stmt->execute([$user_id, $fetch_id]);
$latest_health = $health_stmt->fetch();

$today = date('Y-m-d');
$today_tasks = [];
$week_tasks = [];
$pending_tasks = [];
$completed_tasks = [];
$weak_tasks = []; // High priority overdue tasks

foreach ($all_tasks as $t) {
    if ($t['status'] === 'completed') {
        $completed_tasks[] = $t;
    } elseif ($t['due_date'] < $today) {
        $pending_tasks[] = $t;
        if ($t['priority'] === 'high') $weak_tasks[] = $t;
    } elseif ($t['due_date'] === $today) {
        $today_tasks[] = $t;
    } else {
        $week_tasks[] = $t;
    }
}

// Handle Task Toggle
if (isset($_GET['toggle_task'])) {
    $tid = (int)$_GET['toggle_task'];
    $new_status = $_GET['status'] === 'completed' ? 'completed' : 'pending';
    $upd = $pdo->prepare("UPDATE crop_tasks SET status = ? WHERE id = ? AND user_id = ?");
    $upd->execute([$new_status, $tid, $user_id]);
    header("Location: dashboard.php?crop_id=$crop_id&source=$source"); exit;
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
    <title>Dashboard — <?= htmlspecialchars($site_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/features.css">
    <style>
        /* Dashboard-specific overrides */
        .db-init-box {
            text-align: center;
            padding: 36px 20px;
            background: linear-gradient(135deg,#f9fff9,#f0faf0);
            border: 2px dashed var(--primary);
            border-radius: var(--radius);
        }
        .db-init-box .init-icon { font-size: 2.8rem; margin-bottom: 12px; }
        .db-init-box h3 { font-weight: 800; color: var(--primary); margin-bottom: 8px; }
        .db-init-box p { font-size: 0.88rem; color: var(--muted); margin-bottom: 18px; }

        .news-item { padding: 12px 0; border-bottom: 1px solid var(--border); }
        .news-item:last-child { border-bottom: none; }
        .news-title { font-weight: 700; font-size: 0.88rem; margin-bottom: 4px; }
        .news-excerpt { font-size: 0.82rem; color: var(--muted); line-height: 1.5; }

        .rotation-box { background: #f1faf1; padding: 14px; border-radius: var(--radius-sm); border-left: 4px solid var(--primary); font-size: 0.88rem; }

        .borewell-bar { height: 10px; background: #d1e8d1; border-radius: 5px; overflow: hidden; margin: 8px 0; }
        .borewell-fill { height: 100%; background: linear-gradient(90deg, #43a047, #2e7d32); border-radius: 5px; transition: width 0.8s ease; }
    </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="page-wrap">
    <div class="page-head">
        <div class="page-head-left">
            <h1>🌾 Farm Overview</h1>
            <p>Welcome back, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Farmer') ?>! Here's your farming status.</p>
        </div>
        <div class="crop-bar">
            <label>🌱 Current Crop:</label>
            <select onchange="window.location.href='dashboard.php?crop_id=' + this.value.split('|')[0] + '&source=' + this.value.split('|')[1]">
                <?php if (empty($all_reports)): ?>
                    <option>No Crop Selected</option>
                <?php else: ?>
                    <?php foreach ($all_reports as $report): ?>
                        <?php $val = $report['id'] . '|' . $report['source']; ?>
                        <option value="<?= $val ?>" <?= ($report['id'] == $crop_id && $report['source'] == $source) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($report['crop']) ?> (<?= $report['source'] == 'farm' ? 'Initial' : 'Smart' ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
    </div>

    <div class="fc-grid">
        <!-- Main Column -->
        <div>
            <!-- AI Health Score & Models Card -->
            <?php if ($latest_health && !empty($latest_health['health_score'])): ?>
            <div class="fc-card hover-lift" style="margin-bottom: 24px; padding: 20px;">
                <h3 style="margin-bottom: 12px; font-weight: 800; display: flex; align-items: center; justify-content: space-between;">
                    <span>📊 AI Crop Health Score</span>
                    <?php 
                        $score = (int)$latest_health['health_score'];
                        $color = $score >= 80 ? 'var(--primary)' : ($score >= 60 ? '#e65100' : 'var(--danger)');
                    ?>
                    <span style="font-size: 1.8rem; font-weight: 900; color: <?= $color ?>;"><?= $score ?>/100</span>
                </h3>
                
                <?php if (!empty($latest_health['detected_stage'])): ?>
                    <p style="font-size: 0.88rem; color: var(--muted); margin-bottom: 12px;"><strong>Stage:</strong> <?= htmlspecialchars($latest_health['detected_stage']) ?></p>
                <?php endif; ?>
                
                <?php 
                $models = [];
                if (!empty($latest_health['model_predictions'])) {
                    $models = json_decode($latest_health['model_predictions'], true) ?: [];
                }
                ?>
                
                <?php if (!empty($models)): ?>
                <!-- AI Architecture Models Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 14px;">
                    <?php if (isset($models['disease'])): ?>
                    <div style="background: #fff3e0; padding: 10px; border-radius: 8px; border: 1px solid #ffe0b2;">
                        <div style="font-size: 0.75rem; color: #e65100; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">🦠 Disease Risk</div>
                        <div style="font-size: 1.1rem; font-weight: 800; color: #333;"><?= htmlspecialchars($models['disease']['risk_pct'] ?? '0') ?>%</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($models['irrigation'])): ?>
                    <div style="background: #e3f2fd; padding: 10px; border-radius: 8px; border: 1px solid #bbdefb;">
                        <div style="font-size: 0.75rem; color: #1565c0; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">💧 Irrigation</div>
                        <div style="font-size: 1.1rem; font-weight: 800; color: #333;"><?= htmlspecialchars($models['irrigation']['required_mm'] ?? '0') ?>mm</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($models['growth'])): ?>
                    <div style="background: #f1faf1; padding: 10px; border-radius: 8px; border: 1px solid #c8e6c9;">
                        <div style="font-size: 0.75rem; color: #2e7d32; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">📈 Growth Delay</div>
                        <div style="font-size: 1.1rem; font-weight: 800; color: #333;"><?= htmlspecialchars($models['growth']['growth_delay_days'] ?? '0') ?> Days</div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($models['yield'])): ?>
                    <div style="background: #f3e5f5; padding: 10px; border-radius: 8px; border: 1px solid #e1bee7;">
                        <div style="font-size: 0.75rem; color: #6a1b9a; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">🌾 Expected Yield</div>
                        <div style="font-size: 1.1rem; font-weight: 800; color: #333;"><?= htmlspecialchars($models['yield']['expected_yield_tons_per_acre'] ?? '0') ?>t/ac</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($latest_health['key_findings'])): ?>
                    <p style="font-size: 0.95rem; line-height: 1.5; background: #f9fff9; padding: 14px; border-left: 4px solid var(--primary); border-radius: 6px; margin-top: 10px;"><?= htmlspecialchars($latest_health['key_findings']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- To-Do List Card -->
            <div class="fc-card hover-lift">
                <div class="fc-card-title">
                    <span style="display:flex; align-items:center; gap:8px;">📝 My To-Do List</span>
                    <button onclick="syncAIDashboard('<?= $source ?>')" class="fc-btn fc-btn-primary fc-btn-sm" style="margin-left: auto; max-width: max-content; padding: 6px 12px; font-size: 0.75rem;">🔄 Refresh</button>
                </div>
                <div class="fc-tabs">
                    <button class="fc-tab-btn active" onclick="showTab('active-tasks', this)">📅 Today & Upcoming</button>
                    <button class="fc-tab-btn" onclick="showTab('pending-section', this)">⚠️ Overdue</button>
                    <button class="fc-tab-btn" onclick="showTab('completed-section', this)">✅ Completed</button>
                </div>

                <div id="active-tasks">
                    <?php if ($source === 'farm' && empty($all_tasks)): ?>
                        <div class="db-init-box">
                            <div class="init-icon">🚜</div>
                            <h3>Initial Plan Loaded</h3>
                            <p>To generate specific daily tasks, spraying schedules, and weather-aware advice using AI, click the button below.</p>
                            <button onclick="syncAIDashboard('farm')" class="fc-btn fc-btn-primary" id="initBtn" style="max-width:280px;margin:0 auto;">⚡ Generate AI Management (0.1cr)</button>
                            <div style="margin-top:12px;"><a href="smart_planner.php?step=1&prefill_report=<?= $crop_id ?>" style="font-size:0.8rem; color:var(--muted);">Or click here for manual Reality Check</a></div>
                        </div>
                    <?php else: ?>
                        <p style="font-size:0.78rem;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">Today (<?= date('d M') ?>)</p>
                        <ul class="task-rows">
                            <?php if (empty($today_tasks)): ?>
                                <li style="padding:14px 12px;color:var(--muted);font-style:italic;font-size:0.88rem;">No tasks for today. Great job! 🌟</li>
                            <?php else: ?>
                                <?php foreach ($today_tasks as $t): ?>
                                    <li class="task-row">
                                        <input type="checkbox" class="task-cb" onchange="toggleTaskStatus(<?= $t['id'] ?>, 'pending', '<?= addslashes($t['title']) ?>')">
                                        <div class="task-row-body">
                                            <div class="task-row-title"><?= htmlspecialchars($t['title']) ?></div>
                                            <div class="task-row-meta">
                                                <span class="badge badge-<?= $t['priority'] ?>"><?= strtoupper($t['priority']) ?></span>
                                                <span class="badge badge-cat"><?= ucfirst($t['category']) ?></span>
                                            </div>
                                            <div id="ai-help-<?= $t['id'] ?>" class="ai-help-box"></div>
                                        </div>
                                        <button class="help-btn" onclick="getTaskAIHelp(<?= $t['id'] ?>, this)">❓ Help</button>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>

                        <p style="font-size:0.78rem;font-weight:700;text-transform:uppercase;color:var(--muted);margin:16px 0 8px;">This Week</p>
                        <ul class="task-rows">
                            <?php if (empty($week_tasks)): ?>
                                <li style="padding:14px 12px;color:var(--muted);font-style:italic;font-size:0.88rem;">No upcoming tasks planned.</li>
                            <?php else: ?>
                                <?php foreach ($week_tasks as $t): ?>
                                    <li class="task-row">
                                        <input type="checkbox" class="task-cb" onchange="toggleTaskStatus(<?= $t['id'] ?>, 'pending', '<?= addslashes($t['title']) ?>')">
                                        <div class="task-row-body">
                                            <div class="task-row-title"><?= htmlspecialchars($t['title']) ?></div>
                                            <div class="task-row-meta">
                                                <span><?= date('d M', strtotime($t['due_date'])) ?></span>
                                                <span class="badge badge-cat"><?= ucfirst($t['category']) ?></span>
                                            </div>
                                            <div id="ai-help-<?= $t['id'] ?>" class="ai-help-box"></div>
                                        </div>
                                        <button class="help-btn" onclick="getTaskAIHelp(<?= $t['id'] ?>, this)">❓ Help</button>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                
                <div id="pending-section" style="display:none;">
                    <p style="font-size:0.78rem;font-weight:700;text-transform:uppercase;color:var(--danger);margin-bottom:8px;">⚠️ Overdue Tasks</p>
                    <ul class="task-rows">
                        <?php if (empty($pending_tasks)): ?>
                            <li style="padding:14px 12px;color:var(--muted);font-style:italic;font-size:0.88rem;">No overdue tasks. Well done!</li>
                        <?php else: ?>
                            <?php foreach ($pending_tasks as $t): ?>
                                <li class="task-row">
                                    <input type="checkbox" class="task-cb" onchange="toggleTaskStatus(<?= $t['id'] ?>, 'pending', '<?= addslashes($t['title']) ?>')">
                                        <div class="task-row-body">
                                            <div class="task-row-title"><?= htmlspecialchars($t['title']) ?></div>
                                        <div class="task-row-meta">
                                            <span style="color:var(--danger);"><?= date('d M', strtotime($t['due_date'])) ?></span>
                                            <span class="badge badge-high">OVERDUE</span>
                                        </div>
                                        <div id="ai-help-<?= $t['id'] ?>" class="ai-help-box"></div>
                                    </div>
                                    <button class="help-btn" onclick="getTaskAIHelp(<?= $t['id'] ?>, this)">❓ Help</button>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div id="completed-section" style="display:none;">
                    <p style="font-size:0.78rem;font-weight:700;text-transform:uppercase;color:var(--primary);margin-bottom:8px;">✅ Completed Tasks</p>
                    <ul class="task-rows">
                        <?php if (empty($completed_tasks)): ?>
                            <li style="padding:14px 12px;color:var(--muted);font-style:italic;font-size:0.88rem;">No completed tasks yet.</li>
                        <?php else: ?>
                            <?php foreach ($completed_tasks as $t): ?>
                                <li class="task-row completed">
                                    <input type="checkbox" checked class="task-cb" onchange="toggleTaskStatus(<?= $t['id'] ?>, 'completed', '<?= addslashes($t['title']) ?>')">
                                    <div class="task-row-body">
                                        <div class="task-row-title"><?= htmlspecialchars($t['title']) ?></div>
                                        <div class="task-row-meta"><span class="badge badge-done">Done</span></div>
                                    </div>
                                    <span></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Quick Nav Tiles -->
            <?php $nav_qs = $crop_id ? "?crop_id={$crop_id}&source={$source}" : ''; ?>
            <div class="nav-tiles">
                <a href="expense_tracker.php<?= $nav_qs ?>" class="nav-tile"><span class="tile-icon">💰</span>Expenses</a>
                <a href="disease_prediction.php<?= $nav_qs ?>" class="nav-tile"><span class="tile-icon">🩺</span>Disease Check</a>
                <a href="crop_prices.php<?= $nav_qs ?>" class="nav-tile"><span class="tile-icon">💹</span>Mandi Prices</a>
                <a href="irrigation_scheduler.php<?= $nav_qs ?>" class="nav-tile"><span class="tile-icon">💧</span>Irrigation</a>
            </div>

            <?php 
            // Calculate Next Irrigation for Sidebar (Enhanced broader lookup)
            $next_irr_date = "No upcoming tasks";
            $irr_level = 78; // Improved baseline
            $found_irr = false;
            
            // Simulating a live sensor fluctuation (+/- 2% based on minutes)
            $live_drift = (int)date('i') % 5 - 2; 

            foreach ($all_tasks as $t) {
                $is_irr = in_array(strtolower($t['category']), ['irrigation', 'sprinkling', 'watering']) || 
                         stripos($t['title'], 'irrigation') !== false || 
                         stripos($t['title'], 'watering') !== false ||
                         stripos($t['title'], 'pump') !== false;

                if ($t['status'] === 'pending' && $is_irr) {
                    $due_time = strtotime($t['due_date']);
                    $found_irr = true;
                    if ($t['due_date'] === $today) {
                        $next_irr_date = "Today (" . htmlspecialchars($t['title']) . ")";
                        $irr_level = 62; // Low moisture
                    } elseif ($t['due_date'] === date('Y-m-d', strtotime('+1 day'))) {
                        $next_irr_date = "Tomorrow Morning";
                        $irr_level = 70;
                    } else {
                        $next_irr_date = date('d M', $due_time);
                        $irr_level = 75;
                    }
                    break;
                }
            }
            
            $final_level = $irr_level + $live_drift;
            if (!$found_irr) {
                if ($source === 'smart') {
                    $final_level = 82 + $live_drift;
                    $next_irr_date = "Optimal Moisture Levels";
                } else {
                    $next_irr_date = "Check Smart Planner";
                }
            }
            ?>

        </div>

        <!-- Sidebar -->
        <div>
            <!-- AI Sync Widget -->
            <div class="sidebar-widget info">
                <div class="widget-title">🌦️ Live AI Sync</div>
                <?php if (!empty($current_report['weather_info'])): ?>
                    <div style="background: rgba(255,255,255,0.7); border-radius: 8px; padding: 12px; margin-bottom: 12px; border: 1px solid #bbdefb; font-size: 0.83rem; color: #1e3a8a;">
                        <strong style="display: block; margin-bottom: 4px; color: #1565c0;">Current Advisory:</strong>
                        <?= render_ai_html($current_report['weather_info']) ?>
                    </div>
                <?php endif; ?>
                <p style="font-size: 0.8rem; color: var(--muted); margin-bottom: 12px;">Tasks updated for <strong><?= htmlspecialchars($current_report['location'] ?? 'your area') ?></strong>.</p>
                <button onclick="syncAIDashboard('<?= $source ?>')" id="syncBtn" class="fc-btn fc-btn-primary" style="background:#1565c0;">🔄 Refresh Weak Tasks</button>
                <div id="syncStatus" style="font-size: 0.75rem; margin-top: 8px; text-align: center; color: #1565c0; font-weight: 700;"></div>
            </div>

            <!-- Alerts & News Widget -->
            <div class="sidebar-widget">
                <div class="widget-title">🔔 ALERTS &amp; NEWS</div>
                <div id="news-container">
                    <?php if (!empty($weak_tasks)): ?>
                        <div style="background: var(--danger-lt); border-radius: 8px; padding: 10px; margin-bottom: 12px;">
                            <strong style="color: var(--danger); font-size: 0.8rem;">⚠️ URGENT TASKS</strong>
                            <ul style="padding-left: 14px; margin-top: 5px; font-size: 0.8rem;">
                                <?php foreach (array_slice($weak_tasks, 0, 2) as $wt): ?>
                                    <li style="color: #991b1b; margin-bottom: 4px;"><?= htmlspecialchars($wt['title']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!$crop_id): ?>
                        <p style="font-size: 0.85rem; color: var(--muted);">Complete a Smart Reality Check to get personalized alerts.</p>
                    <?php elseif (empty($all_news)): ?>
                        <div class="news-item">
                            <div class="news-title" style="color: var(--warning);">🕒 Initializing News...</div>
                            <div class="news-excerpt">Click "Refresh Weak Tasks" to generate alerts for your crop.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($all_news as $news): ?>
                            <div class="news-item">
                                <div class="news-title"><?= htmlspecialchars($news['title']) ?></div>
                                <div class="news-excerpt"><?= htmlspecialchars($news['content']) ?></div>
                                <div style="font-size: 0.7rem; color: #999; margin-top: 5px;"><?= date('d M', strtotime($news['created_at'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="margin: 20px -20px -20px -20px;">
                    <a href="smart_planner.php?step=1&prefill_report=<?= $crop_id ?>" class="fc-btn fc-btn-primary" style="width: 100%; border-radius: 0; padding: 14px 20px;">⚡ Full Status Update</a>
                </div>
            </div>

            <!-- Irrigation Status Widget -->
            <div class="sidebar-widget">
                <div class="widget-title">💧 Irrigation Status</div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.85rem; color: var(--muted);">Borewell Level</span>
                    <span style="font-weight: 800; color: var(--primary); font-size: 1.1rem;"><?= $final_level ?>%</span>
                </div>
                <div class="borewell-bar"><div class="borewell-fill" style="width:<?= $final_level ?>%"></div></div>
                <p style="font-size: 0.77rem; color: var(--muted); margin-top: 8px;">Next: <strong><?= $next_irr_date ?></strong></p>
                <a href="irrigation_scheduler.php?crop_id=<?= $crop_id ?>&source=<?= $source ?>" class="fc-btn fc-btn-outline fc-btn-sm" style="margin-top:12px;width:100%;justify-content:center;box-sizing:border-box;display:flex;margin-bottom:2px;">💧 Full Schedule</a>
            </div>
        </div>
    </div>

            <!-- Crop Rotation Card -->
            <div class="fc-card hover-lift" style="border: 1.5px dashed var(--primary); background: linear-gradient(135deg,#f9fff9,#f0faf0);margin-top:4px;">
                <div class="fc-card-title" style="color: var(--primary);">🔄 Crop Rotation Plan</div>
                <div style="font-size: 0.9rem; color: #444; line-height: 1.6;">
                    <?php 
                    $rotation_part = "";
                    $rot_crop_id = $crop_id;
                    $rot_source = $source;

                    if ($rot_source === 'smart') {
                        $stmt_rot = $pdo->prepare("SELECT updated_report_data, base_report_id FROM smart_reports WHERE id = ?");
                        $stmt_rot->execute([$rot_crop_id]);
                        $s_data = $stmt_rot->fetch();
                        $report_data = $s_data['updated_report_data'] ?? '';
                        
                        // Robust extraction for Stage 10
                        $headers = ['10. CROP ROTATION', 'CROP ROTATION PLAN', '10. Rotation'];
                        foreach ($headers as $h) {
                            if (stripos($report_data, $h) !== false) {
                                // Find where header starts
                                $start_pos = stripos($report_data, $h);
                                // Skip the header itself
                                $rest = substr($report_data, $start_pos);
                                // Find the next section or end
                                $end_headers = ['---', 'JSON_TASKS_START', '## '];
                                $earliest_end = strlen($rest);
                                foreach ($end_headers as $eh) {
                                    $pos = strpos($rest, $eh, strlen($h));
                                    if ($pos !== false && $pos < $earliest_end) $earliest_end = $pos;
                                }
                                $rotation_part = trim(substr($rest, 0, $earliest_end));
                                break;
                            }
                        }

                        if (!$rotation_part) {
                            $rot_source = 'farm';
                            $rot_crop_id = $s_data['base_report_id'];
                        }
                    }
                    
                    if ($rot_source === 'farm' && $rot_crop_id) {
                        $stmt_f = $pdo->prepare("SELECT report_data FROM farm_reports WHERE id = ?");
                        $stmt_f->execute([$rot_crop_id]);
                        $f_data = $stmt_f->fetchColumn();
                        
                        // Handle JSON format from Farm Planner
                        if (strpos($f_data, '{') === 0) {
                            $json = json_decode($f_data, true);
                            $combined = "";
                            // Stage 2 is historically where rotation is
                            $combined .= ($json['gemini_stage2'] ?? "");
                            // Also grab stage 10 just in case
                            $combined .= "\n" . ($json['gemini_stage10'] ?? "");
                            $f_data = $combined;
                        }

                        // Robust extraction for Stage 2
                        $headers_f = ['CROP ROTATION BENEFIT', 'Step 2: Crop Rotation', 'Rotation Plan'];
                        foreach ($headers_f as $hf) {
                            if (stripos($f_data, $hf) !== false) {
                                $start_pos = stripos($f_data, $hf);
                                $rest = substr($f_data, $start_pos);
                                $end_headers = ['##', '###', '---'];
                                $earliest_end = strlen($rest);
                                foreach ($end_headers as $eh) {
                                    $pos = strpos($rest, $eh, strlen($hf));
                                    if ($pos !== false && $pos < $earliest_end) $earliest_end = $pos;
                                }
                                $rotation_part = trim(substr($rest, 0, $earliest_end));
                                break;
                            }
                        }
                    }
                    ?>
                <?php if ($rotation_part): ?>
                    <div class="rotation-box"><?= render_ai_html($rotation_part) ?></div>
                <?php else: ?>
                    <div style="text-align: center; color: var(--muted); padding: 10px;">
                        <p style="font-style: italic; margin-bottom: 10px;">Detailed rotation plan will be ready after Stage 10.</p>
                        <a href="smart_planner.php?prefill_report=<?= $crop_id ?>" style="font-size: 0.8rem; color: var(--primary); font-weight: 700;">Complete Smart Reality Check →</a>
                    </div>
                <?php endif; ?>
            </div>
</div>

<script>
    function syncAIDashboard(src = 'smart') {
        const btn = document.getElementById('syncBtn');
        const initBtn = document.getElementById('initBtn');
        const status = document.getElementById('syncStatus');
        const cropId = '<?= $crop_id ?>';
        
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '🔄 Syncing with AI...';
        }
        if (initBtn) {
            initBtn.disabled = true;
            initBtn.innerHTML = '⚡ Generating Management...';
        }
        if (status) status.innerHTML = 'AI is processing your farm data...';

        fetch('dashboard_ai_sync.php?crop_id=' + cropId + '&source=' + src)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (status) status.innerHTML = '✅ Dashboard Ready!';
                    // If source was farm, we need to reload with the new smart_report_id or let UNION handle it
                    setTimeout(() => {
                        if (src === 'farm') {
                            window.location.href = 'dashboard.php?crop_id=' + data.smart_report_id + '&source=smart';
                        } else {
                            location.reload();
                        }
                    }, 1500);
                } else {
                    if (status) status.innerHTML = '❌ Sync failed: ' + (data.error || 'Unknown error');
                    if (btn) { btn.disabled = false; btn.innerHTML = '🔄 Refresh Weak Tasks'; }
                    if (initBtn) { initBtn.disabled = false; initBtn.innerHTML = '⚡ Generate AI Management (0.25cr)'; }
                }
            })
            .catch(err => {
                if (status) status.innerHTML = '❌ Connection error.';
                if (btn) { btn.disabled = false; btn.innerHTML = '🔄 Refresh Weak Tasks'; }
                if (initBtn) { initBtn.disabled = false; initBtn.innerHTML = '⚡ Generate AI Management (0.25cr)'; }
            });
    }

    function showTab(id, btn) {
        document.getElementById('active-tasks').style.display = 'none';
        document.getElementById('pending-section').style.display = 'none';
        document.getElementById('completed-section').style.display = 'none';
        document.getElementById(id).style.display = 'block';
        document.querySelectorAll('.fc-tab-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }
function getTaskAIHelp(taskId, btn) {
    const helpBox = document.getElementById('ai-help-' + taskId);
    if (helpBox.style.display === 'block') {
        helpBox.style.display = 'none';
        btn.innerText = '❓ Help';
        return;
    }
    
    helpBox.innerHTML = '<p style="color:#888; font-style:italic;">✨ InfoCrop AI is preparing instructions...</p>';
    helpBox.style.display = 'block';
    btn.innerText = '⌛...';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('task_id', taskId);
    formData.append('crop_id', '<?= $crop_id ?>');
    formData.append('source', '<?= $source ?>');

    fetch('ajax_task_help.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = '❌ Close';
        if (data.error) {
            helpBox.innerHTML = '<p style="color:var(--danger);">⚠️ ' + data.error + '</p>';
        } else {
            helpBox.innerHTML = '<h3>📖 AI Guidance</h3>' + data.help;
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = '❓ Help';
        helpBox.innerHTML = '<p style="color:var(--danger);">⚠️ Connection error. Try again.</p>';
    });
}

function toggleTaskStatus(taskId, currentStatus, currentTitle) {
    const newStatus = currentStatus === 'completed' ? 'pending' : 'completed';
    const cropId = '<?= $crop_id ?>';
    const source = '<?= $source ?>';

    // If unchecking, just do it instantly without a popup
    if (newStatus === 'pending') {
        window.location.href = `dashboard.php?crop_id=${cropId}&source=${source}&toggle_task=${taskId}&status=pending`;
        return;
    }

    // If checking as completed, show loading Swal
    Swal.fire({
        title: 'Checking off task...',
        text: 'Generating InfoCrop AI farm benefit...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    // Fire AJAX request
    const formData = new FormData();
    formData.append('task_id', taskId);
    formData.append('crop_id', cropId);
    formData.append('status', newStatus);

    fetch('ajax_task_complete.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Great Job! 🌟',
                html: `<b>${currentTitle}</b><br><br><span style="color:#2e7d32; font-style:italic;">"${data.benefit}"</span>`,
                confirmButtonColor: '#2e7d32',
                confirmButtonText: 'Continue'
            }).then(() => {
                // Reload via standard PHP route to move it to completed tab
                window.location.href = `dashboard.php?crop_id=${cropId}&source=${source}`;
            });
        } else {
            Swal.fire('Error', data.error || 'Failed to update task.', 'error');
            // Uncheck the box visually since it failed
            setTimeout(() => location.reload(), 1500);
        }
    })
    .catch(err => {
        Swal.fire('Error', 'Connection failed. Please try again.', 'error');
        setTimeout(() => location.reload(), 1500);
    });
}

</script>

</main>
<?php include 'partials/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>

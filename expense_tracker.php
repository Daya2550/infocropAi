<?php
session_start();

// Redirect away from URLs with empty query params — prevents 403 on hardened hosts
if (isset($_GET['crop_id']) && $_GET['crop_id'] === '') {
    header('Location: expense_tracker.php'); exit;
}

require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';

require_once 'partials/db_queries.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
$crop_id = isset($_GET['crop_id']) ? (int)$_GET['crop_id'] : null;

// Fetch all reports — one entry per crop name (latest smart wins, else latest farm)
$stmt = $pdo->prepare(sql_latest_reports_by_crop());
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

// Handle Add Expense (Only for Smart reports)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense']) && $source === 'smart') {
    // Translate any non-English inputs to English
    $_POST = translate_inputs_to_english($_POST);

    $item = trim($_POST['item']);
    $amount = (float)$_POST['amount'];
    $category = trim($_POST['category']);
    $date = $_POST['date'] ?: date('Y-m-d');
    
    if ($item && $amount > 0 && $crop_id) {
        $ins = $pdo->prepare("INSERT INTO farm_expenses (user_id, smart_report_id, item, amount, date, category) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$user_id, $crop_id, $item, $amount, $date, $category]);
        header("Location: expense_tracker.php?crop_id=$crop_id&source=smart&success=1"); exit;
    }
}

// Fetch Expenses
$expenses = [];
if ($source === 'smart') {
    $expenses_stmt = $pdo->prepare("SELECT * FROM farm_expenses WHERE user_id = ? AND smart_report_id = ? ORDER BY date DESC");
    $expenses_stmt->execute([$user_id, $crop_id]);
    $expenses = $expenses_stmt->fetchAll();
}

$total_expense = 0;
foreach ($expenses as $e) $total_expense += (float)$e['amount'];

$profit_forecast = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['predict_profit']) && $source === 'smart') {
    // Credit Check
    $stmt_u = $pdo->prepare("SELECT usage_limit, usage_count FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $u = $stmt_u->fetch();
    
    if (!$u || ((float)$u['usage_limit'] - (float)$u['usage_count']) < 0.1) {
        $error = "Insufficient credits (0.1cr needed).";
    } else {
        $expenses_list = "";
        foreach ($expenses as $e) {
            $expenses_list .= "- " . $e['item'] . ": Rs. " . $e['amount'] . " (" . $e['category'] . ")\n";
        }

        // Fetch Initial Plan Data
        $stmt_plan = $pdo->prepare("SELECT report_data FROM farm_reports WHERE id = (SELECT base_report_id FROM smart_reports WHERE id = ?)");
        $stmt_plan->execute([$crop_id]);
        $plan_raw = $stmt_plan->fetchColumn();
        $plan_json = json_decode($plan_raw, true);

        $target_yield = $plan_json['confirmed_yield'] ?? $plan_json['yield_target'] ?? 'N/A';
        $mandi_rate = $plan_json['mandi_rate'] ?? 'N/A';
        $full_budget = $plan_json['confirmed_budget'] ?? $plan_json['budget_total'] ?? 'N/A';
        $crop_name = $current_report['crop'] ?? 'this crop';

        $prompt = "You are a senior agricultural economist. Analyze the following farm expenses and predict the final Profit or Loss.
        CROP: $crop_name
        LOGGED EXPENSES SO FAR: Rs. $total_expense
        DETAILED EXPENSES:
        $expenses_list
        INITIAL PLAN CONTEXT:
        - Target Yield: $target_yield kg/acre
        - Expected Market Rate: Rs. $mandi_rate
        - Total Planned Budget: Rs. $full_budget
        Provide: 1. A clear 'Estimated Final Profit/Loss' figure. 2. Analysis of current spending vs planned budget. 3. Risk factors. 4. 3 specific tips. Format with Markdown.";

        $profit_forecast = run_gemini_stage($prompt);
        if (strncmp($profit_forecast, '[AI_ERROR]', 10) === 0) {
            $error = substr($profit_forecast, 10);
            $profit_forecast = "";
        } else {
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
    <title>Expense Tracker — <?= htmlspecialchars($site_name) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/features.css">
    <style>
        .exp-cat { display: inline-flex; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; background: var(--info-lt); color: var(--info); }
        .profit-box { background: linear-gradient(135deg,#f1faf1,#e8f5e9); border-left: 4px solid var(--primary); }
        .loss-box { background: var(--danger-lt); border-left: 4px solid var(--danger); }
    </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="page-wrap">
    <div class="page-head">
        <div class="page-head-left">
            <h1>💰 Expense Tracker</h1>
            <p>Track farming costs, predict profit/loss with AI.</p>
        </div>
        <div class="crop-bar">
            <label>Select Crop:</label>
            <select onchange="window.location.href='expense_tracker.php?crop_id=' + this.value.split('|')[0] + '&source=' + this.value.split('|')[1]">
                <?php if (empty($all_reports)): ?>
                    <option>No crops found</option>
                <?php else: ?>
                    <?php foreach ($all_reports as $r): ?>
                        <?php $val = $r['id'] . '|' . $r['source']; ?>
                        <option value="<?= $val ?>" <?= ($r['id'] == $crop_id && $r['source'] == $source) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['crop']) ?> (<?= $r['source'] == 'smart' ? 'Smart' : 'Initial' ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?><div class="alert alert-success">✅ Expense added successfully!</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="fc-grid">
        <!-- Main Column -->
        <div>

            <!-- Expense Summary Stats -->
            <?php if ($source === 'smart'): ?>
            <div class="stat-row">
                <div class="stat-chip"><span class="chip-label">Total Spent</span><span class="chip-value">₹<?= number_format($total_expense, 2) ?></span></div>
                <div class="stat-chip info"><span class="chip-label">Expenses</span><span class="chip-value"><?= count($expenses) ?></span></div>
            </div>
            <?php endif; ?>

            <!-- Add Expense Card -->
            <?php if ($source === 'farm'): ?>
                <div class="empty-state">
                    <div class="es-icon">📊</div>
                    <h3>Setup Management First</h3>
                    <p>Go to the dashboard and click "Generate AI Management" to start tracking expenses.</p>
                    <a href="dashboard.php?crop_id=<?= $crop_id ?>&source=farm" class="fc-btn fc-btn-primary" style="max-width:220px;margin:0 auto;">Go to Dashboard</a>
                </div>
            <?php else: ?>
                <div class="fc-card">
                    <div class="fc-card-title">➕ Add New Expense</div>
                    <form method="POST" class="fc-form">
                        <div class="fc-form-row">
                            <div>
                                <label class="fc-label">Item Name</label>
                                <input type="text" name="item" class="fc-input" placeholder="e.g. Urea bag, Seeds" required>
                            </div>
                            <div>
                                <label class="fc-label">Amount (₹)</label>
                                <input type="number" name="amount" class="fc-input" placeholder="0.00" step="0.01" required>
                            </div>
                        </div>
                        <div class="fc-form-row">
                            <div>
                                <label class="fc-label">Category</label>
                                <select name="category" class="fc-input">
                                    <option>Fertilizer</option><option>Seeds</option><option>Pesticides</option>
                                    <option>Labor</option><option>Water/Irrigation</option><option>Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="fc-label">Date</label>
                                <input type="date" name="date" class="fc-input" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <button type="submit" name="add_expense" class="fc-btn fc-btn-primary">+ Add Expense</button>
                    </form>
                </div>

                <!-- Expense History Table -->
                <div class="fc-card">
                    <div class="fc-card-title">📋 Expense History</div>
                    <?php if (empty($expenses)): ?>
                        <div class="empty-state" style="border:none;padding:20px;">
                            <div class="es-icon">🧾</div>
                            <p>No expenses recorded yet. Add your first expense above.</p>
                        </div>
                    <?php else: ?>
                        <div class="fc-table-wrap">
                            <table class="fc-table">
                                <thead><tr><th>Date</th><th>Item</th><th>Category</th><th style="text-align:right">Amount</th></tr></thead>
                                <tbody>
                                    <?php foreach ($expenses as $e): ?>
                                        <tr>
                                            <td><?= date('d M', strtotime($e['date'])) ?></td>
                                            <td><?= htmlspecialchars($e['item']) ?></td>
                                            <td><span class="exp-cat"><?= htmlspecialchars($e['category']) ?></span></td>
                                            <td style="text-align:right;font-weight:700;color:var(--danger);">₹<?= number_format($e['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background:var(--primary-lt);">
                                        <td colspan="3" style="font-weight:800;padding:12px 14px;">Total Spent</td>
                                        <td style="text-align:right;font-weight:800;color:var(--primary);font-size:1.05rem;padding:12px 14px;">₹<?= number_format($total_expense, 2) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar: AI Forecast -->
        <?php if ($source === 'smart'): ?>
        <div>
            <div class="sidebar-widget">
                <div class="widget-title">✨ AI Profit/Loss Forecast</div>
                <p style="font-size:0.82rem;color:var(--muted);margin-bottom:14px;">Total spent: <strong>₹<?= number_format($total_expense, 2) ?></strong>. AI analyses vs your initial plan targets.</p>
                <form method="POST">
                    <button type="submit" name="predict_profit" class="fc-btn fc-btn-primary">✨ Generate Forecast (0.1cr)</button>
                </form>
                <?php if ($profit_forecast): ?>
                    <div class="ai-result" style="margin-top:16px;">
                        <?= render_ai_html($profit_forecast) ?>
                    </div>
                <?php endif; ?>
            </div>
            <a href="dashboard.php?crop_id=<?= $crop_id ?>&source=<?= $source ?>" class="fc-btn fc-btn-outline" style="width:100%;justify-content:center;">← Dashboard</a>
        </div>
        <?php endif; ?>
    </div>
</div>
</main>

<?php include 'partials/footer.php'; ?>
</body>
</html>

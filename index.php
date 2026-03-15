<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';

// Check if user is logged in
set_time_limit(0); // Allow unlimited execution time — Gemini may take up to 2 minutes for complex stages
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user data for usage limits
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch();

if (!$current_user || $current_user['status'] === 'suspended') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Check usage limit — supports decimal credits (e.g. 0.25 per Smart Check)
if ($current_user['usage_count'] >= $current_user['usage_limit'] && !isset($_GET['action']) && !isset($_GET['stage'])) {
    $limit_reached = true;
} else {
    $limit_reached = false;
}
$credits_remaining = round((float)$current_user['usage_limit'] - (float)$current_user['usage_count'], 2);

// Fetch System Settings
$stmt = $pdo->query("SELECT * FROM settings");
$sys_settings = [];
foreach ($stmt->fetchAll() as $s) {
    $sys_settings[$s['setting_key']] = $s['setting_value'];
}
$site_name = $sys_settings['site_name'] ?? 'InfoCrop AI';

// ── SESSION DATA HELPERS ──────────────────────────────────────
function get_farm_data() {
    return isset($_SESSION['farm_data']) ? $_SESSION['farm_data'] : [];
}

function save_farm_data($data) {
    $_SESSION['farm_data'] = $data;
}

function clear_farm_data() {
    unset($_SESSION['farm_data']);
    unset($_SESSION['completed']);
    unset($_SESSION['prev_gemini']);
}

// ── ROUTE LOGIC ──────────────────────────────────────────────
$stage_num = isset($_GET['stage']) ? (int)$_GET['stage'] : 1;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'restart') {
    clear_farm_data();
    header('Location: index.php');
    exit;
}

$farm_data = get_farm_data();
$completed = isset($_SESSION['completed']) ? $_SESSION['completed'] : [];
$show_report = ($action === 'report' || $stage_num > count($stages));

// Determine which AI response to show in the right panel:
// If the current stage is already completed (user navigated back), show THAT stage's AI data.
// Otherwise, show the previous stage's AI data (the normal forward flow).
$prev_gemini = null;
$panel_stage_label = null;
if (!$show_report && isset($stages[$stage_num - 1])) {
    $cur_stage_config = $stages[$stage_num - 1];
    $cur_gkey = 'gemini_' . $cur_stage_config['gemini_key'];
    // If navigated-back to a completed stage, show THIS stage's AI result on the right
    if (in_array($stage_num, $completed) && !empty($farm_data[$cur_gkey])) {
        $prev_gemini = $farm_data[$cur_gkey];
        $panel_stage_label = $cur_stage_config['emoji'] . ' Stage ' . $cur_stage_config['num'];
    } elseif ($stage_num > 1 && isset($stages[$stage_num - 2])) {
        // Normal forward flow: show previous stage's AI result
        $prev_stage_config = $stages[$stage_num - 2];
        $prev_gkey = 'gemini_' . $prev_stage_config['gemini_key'];
        if (!empty($farm_data[$prev_gkey])) {
            $prev_gemini = $farm_data[$prev_gkey];
            $panel_stage_label = $prev_stage_config['emoji'] . ' Stage ' . $prev_stage_config['num'];
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Translate all non-English POST inputs to English automatically
    $_POST = translate_inputs_to_english($_POST);

    $current_stage_idx = $stage_num - 1;
    $stage_config = $stages[$current_stage_idx];

    // ── Capture plant_mode (must happen before field loop) ──────────
    $plant_mode_post = isset($_POST['plant_mode']) ? trim($_POST['plant_mode']) : 'new';
    if ($stage_num === 1) {
        $farm_data['plant_mode'] = $plant_mode_post;
        // Auto-populate confirmed_crop from crop_name_old for old-plant mode
        if ($plant_mode_post === 'old' && !empty($_POST['crop_name_old'])) {
            $farm_data['confirmed_crop'] = trim($_POST['crop_name_old']);
        }
    }

    // ── Collect form inputs & detect changes ─────────────────────
    $changed = false;
    foreach ($stage_config['fields'] as $field) {
        $val = isset($_POST[$field['name']]) ? trim($_POST[$field['name']]) : '';
        if ($val !== '' && $val !== 'Select') {
            // Flag as changed if value differs from what's already saved
            if (($farm_data[$field['name']] ?? null) !== $val) {
                $changed = true;
            }
            $farm_data[$field['name']] = $val;
        }
    }

    // ── Shortcut: stage already done & nothing changed ────────────
    // Skip Gemini, save data, and bounce back to the furthest stage.
    $already_completed = in_array($stage_num, $completed);
    if ($already_completed && !$changed) {
        save_farm_data($farm_data); // persist any trivial re-saves
        $_SESSION['completed'] = $completed;
        // Determine furthest reached stage
        $max_done = max($completed);
        if ($max_done >= count($stages)) {
            header('Location: index.php?action=report');
        } else {
            header('Location: index.php?stage=' . ($max_done + 1));
        }
        exit;
    }

    // ── Call Gemini with Robust Error Handling ─────────────────────
    try {
        // ── Branch prompt for Old Plant mode in Stage 1 ─────────────
        $use_prompt = $stage_config['prompt'];
        if ($stage_num === 1 && ($farm_data['plant_mode'] ?? 'new') === 'old' && !empty($stage_config['prompt_old'])) {
            $use_prompt = $stage_config['prompt_old'];
        }
        $prompt = format_prompt($use_prompt, $farm_data);

        // ── Inject historical AI context (Cross-session) ──────────
        // This allows the AI to "remember" previous years/reports of the same crop.
        $crop_lookup = $farm_data['confirmed_crop'] ?? '';
        if ($crop_lookup) {
            $hi_context = get_crop_ai_context($pdo, $user_id, $crop_lookup);
            if ($hi_context) {
                $prompt .= "\n\n== GLOBAL HISTORICAL CONTEXT (Previous Seasons) ==\n"
                         . "The farmer has grown this crop before. Use these insights for better planning:\n"
                         . $hi_context;
            }
        }

        // ── Inject prior AI stage outputs as context ──────────────
        // Compressed format: strip markdown, collapse tables, extract key points
        // This ensures each stage builds on previous AI recommendations.
        $prev_context = '';
        $current_gkey = 'gemini_' . $stage_config['gemini_key'];
        foreach ($stages as $ps) {
            $gkey = 'gemini_' . $ps['gemini_key'];
            if (!empty($farm_data[$gkey]) && $gkey !== $current_gkey) {
                $raw = $farm_data[$gkey];

                // ─ Compress: strip markdown formatting ─
                $raw = preg_replace('/^#{1,4}\s+/m', '', $raw);          // strip ## headers
                $raw = str_replace('**', '', $raw);                       // strip bold
                $raw = preg_replace('/^\|[-:| ]+\|$/m', '', $raw);        // strip table separator rows
                // Collapse table rows: "| A | B | C |" → "A: B, C"
                $raw = preg_replace_callback('/^\|(.+)\|$/m', function($m) {
                    $cells = array_map('trim', explode('|', trim($m[1], '|')));
                    $cells = array_filter($cells, fn($c) => $c !== '' && $c !== '---');
                    if (count($cells) >= 2) {
                        $key = array_shift($cells);
                        return $key . ': ' . implode(', ', $cells);
                    }
                    return implode(', ', $cells);
                }, $raw);
                $raw = preg_replace('/[*_`~]+/', '', $raw);               // strip remaining markdown chars
                $raw = preg_replace('/\n{3,}/', "\n", $raw);              // collapse blank lines
                $raw = preg_replace('/[ \t]+/', ' ', $raw);               // collapse spaces
                $raw = trim($raw);

                // Truncate compressed text
                $summary = mb_substr($raw, 0, 500);
                if (mb_strlen($raw) > 500) $summary .= '...';

                $prev_context .= "\n[Stage " . $ps['num'] . " - " . $ps['title'] . "]\n" . $summary . "\n";
            }
        }
        if ($prev_context !== '') {
            $prompt .= "\n\n== PRIOR STAGE CONTEXT (compressed) ==\n"
                     . "Reference these prior AI recommendations for consistency:\n"
                     . $prev_context
                     . "== END CONTEXT ==\n"
                     . "Ensure your response is consistent with above. Reference specific prior data where relevant.";
        }

        $gemini_response = run_gemini_stage($prompt);

        // Check for specific [AI_ERROR] tag from lib/gemini.php
        if (strncmp($gemini_response, '[AI_ERROR]', 10) === 0) {
            throw new Exception(trim(substr($gemini_response, 10)));
        }
        
        $gemini_key = 'gemini_' . $stage_config['gemini_key'];
        $farm_data[$gemini_key] = $gemini_response;

        // Update progress
        if (!in_array($stage_num, $completed)) {
            $completed[] = $stage_num;
        }

        save_farm_data($farm_data);
        $_SESSION['completed'] = $completed;
        $_SESSION['prev_gemini'] = $gemini_response;
        unset($_SESSION['stage_error']); // Clear errors on success

        // Move to next stage
        $next_stage = $stage_num + 1;
        if ($next_stage > count($stages)) {
            // Safe DB update: reconnect if MySQL timed out during AI call
            for ($dbAttempt = 0; $dbAttempt < 2; $dbAttempt++) {
                try {
                    $pdo->prepare("UPDATE users SET usage_count = usage_count + 1 WHERE id = ?")->execute([$user_id]);
                    break;
                } catch (Exception $dbErr) {
                    if ($dbAttempt === 0) {
                        // Reconnect PDO if MySQL timed out during long AI call (get_pdo_connection defined in db.php)
                        if (function_exists('get_pdo_connection')) {
                            /** @var callable $reconnect */
                            $reconnect = 'get_pdo_connection';
                            $pdo = $reconnect();
                        }
                    }
                }
            }
            header('Location: index.php?action=report');
        } else {
            header('Location: index.php?stage=' . $next_stage);
        }
        exit;

    } catch (Exception $e) {
        // Log technical error (if we have a log system), but show friendly msg to user
        $_SESSION['stage_error'] = "InfoCrop AI is currently busy or encountered a minor technical hiccup. Please try clicking 'Next' again in a few seconds. (Info: " . $e->getMessage() . ")";
        save_farm_data($farm_data);
        header('Location: index.php?stage=' . $stage_num);
        exit;
    } catch (Throwable $t) {
        // Catch-all for fatal PHP errors during this block
        $_SESSION['stage_error'] = "A server-side error occurred. We have redirected you back to let you try again. If this persists, please contact support. (Debug: " . $t->getMessage() . " in " . basename($t->getFile()) . ":" . $t->getLine() . ")";
        header('Location: index.php?stage=' . $stage_num);
        exit;
    }
}

// Get error message if any
$stage_error = isset($_SESSION['stage_error']) ? $_SESSION['stage_error'] : null;
unset($_SESSION['stage_error']); // Only show once

// Get current stage config
$current_stage = (!$show_report && isset($stages[$stage_num - 1])) ? $stages[$stage_num - 1] : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>🌱 Farm Planner AI – <?php echo htmlspecialchars($site_name); ?></title>
  <meta name="description" content="10-stage AI-powered farm planning wizard for Indian farmers. Get real-time crop, soil, market, and weather guidance powered by InfoCrop AI.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/global.css">
  <link rel="stylesheet" href="assets/css/FarmPlanner.css?v=2.0">
  <style>
    /* Critical fallback for loading overlay to prevent layout shift before CSS loads */
    #loadingOverlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999; }
    #loadingOverlay.show { display: flex !important; }
    /* Plant Mode Toggle Tabs */
    .pm-tabs { display:flex; gap:0; margin-bottom:22px; border-radius:12px; overflow:hidden; border:2px solid #c8e6c9; }
    .pm-tab { flex:1; padding:13px 10px; text-align:center; cursor:pointer; font-weight:700; font-size:.9rem;
              background:#f8faf8; color:#555; transition:.2s; border:none; font-family:inherit; }
    .pm-tab.active { background:linear-gradient(135deg,#2e7d32,#1b5e20); color:#fff; }
    .pm-tab:hover:not(.active) { background:#e8f5e9; }
    .pm-mode-badge { display:inline-block; font-size:.7rem; background:rgba(255,255,255,.22);
                     padding:2px 8px; border-radius:20px; margin-left:6px; vertical-align:middle; }
    .pm-divider { font-size:.72rem; font-weight:800; text-transform:uppercase; color:#94a3b8;
                  letter-spacing:.08em; margin:18px 0 10px; padding:6px 12px;
                  background:#f0f7f0; border-left:3px solid #43a047; border-radius:6px; }
    /* Clickable completed stage steps */
    .fp-step.done { cursor: pointer; }
    .fp-step.done:hover .fp-step-circle {
        background: #2e7d32;
        transform: scale(1.12);
        box-shadow: 0 4px 12px rgba(27,94,32,.35);
        transition: all .2s;
    }
    .fp-step.done a { text-decoration: none; color: inherit; display: contents; }
    /* Back button style */
    .fp-btn-back {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 10px 22px; border-radius: 8px;
        border: 1.5px solid #c8e6c9; background: #f1f8f1;
        color: #2e7d32; font-size: .9rem; font-weight: 600;
        cursor: pointer; text-decoration: none;
        transition: background .2s, border-color .2s;
    }
    .fp-btn-back:hover { background: #e8f5e9; border-color: #81c784; }
    .fp-submit-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .fp-stage-edit-link {
        font-size: 0.75rem; background: #fff; border: 1px solid #ddd;
        padding: 4px 10px; border-radius: 20px; color: #666;
        text-decoration: none; font-weight: 600;
        transition: all 0.2s;
    }
    .fp-stage-edit-link:hover {
        background: var(--primary); color: #fff; border-color: var(--primary);
        box-shadow: 0 4px 8px rgba(46,125,50,0.2);
    }
  </style>
</head>
<body>

<!-- ══════════════════════════════════════════════
     LOADING OVERLAY — shown while Gemini responds
     ══════════════════════════════════════════════ -->
<div id="loadingOverlay">
  <div class="lo-card">
    <!-- Spinner rings -->
    <div class="lo-spinner">
      <div class="lo-ring lo-ring-1"></div>
      <div class="lo-ring lo-ring-2"></div>
      <div class="lo-ring lo-ring-3"></div>
      <div class="lo-icon">🌱</div>
    </div>

    <!-- Stage label -->
    <div class="lo-stage-label" id="loStageLabel">
      <?php if ($current_stage): ?>
        Stage <?php echo $current_stage['num']; ?> of 10 · <?php echo $current_stage['title']; ?>
      <?php endif; ?>
    </div>

    <!-- Main message -->
    <h2 class="lo-title">InfoCrop AI is Analysing…</h2>
    <p class="lo-sub">Building your personalised farm guidance with real-time Indian agricultural data. <strong>This may take up to 2 minutes</strong> — please keep this page open.</p>

    <!-- Animated progress bar -->
    <div class="lo-progress-wrap">
      <div class="lo-progress-bar" id="loProgressBar"></div>
    </div>

    <!-- Rotating tips -->
    <div class="lo-tip" id="loTip">⏳ Preparing your farm analysis — please wait…</div>

    <!-- Status dots -->
    <div class="lo-dots">
      <div class="lo-dot lo-dot-done">✓ Farm data validated</div>
      <div class="lo-dot lo-dot-active" id="loDotSending">📡 Sending to InfoCrop AI</div>
      <div class="lo-dot lo-dot-wait" id="loDotProcessing">⏳ AI building your plan (up to 2 min)</div>
      <div class="lo-dot lo-dot-wait" id="loDotReady">✅ Response ready</div>
    </div>
  </div>
</div>

<?php include 'partials/header.php'; ?>

<!-- ──────────── MAIN WRAPPER ─────────────────────────────── -->
<div class="fp-wrapper container">

  <!-- PAGE HEADER -->
  <?php if (!$show_report): ?>
  <div class="fp-header">
    <h1>🌱 Farm Planner AI</h1>
    <p>Complete 10-stage guidance powered by Infocrop AI — from crop selection to profit planning.</p>
    <span class="badge-ai">✨ Powered by Infocrop AI &nbsp;|&nbsp; Real-time market data</span>
    <div style="margin-top:12px">
      <a href="report_history.php" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#1565c0,#0d47a1);color:#fff;font-size:.84rem;font-weight:700;padding:8px 22px;border-radius:50px;text-decoration:none;transition:all .3s cubic-bezier(.4,0,.2,1);box-shadow:0 6px 18px rgba(13,71,161,0.25)" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 10px 24px rgba(13,71,161,0.35)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 6px 18px rgba(13,71,161,0.25)'">
        📋 My Saved Reports
      </a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($limit_reached): ?>
  <!-- ──────────── LIMIT REACHED VIEW ──────────────────────── -->
  <div class="limit-reached-card">
    <div class="icon">🚀</div>
    <h2>Free Usage Limit Reached</h2>
    <p>You have used all your AI Farm Plan credits. Upgrade your plan to create more detailed plans and get 24/7 support.</p>
    <div class="btn-group">
        <a href="plans.php" class="btn-upgrade">View Plans</a>
        <a href="index.php?action=report&last=1" class="btn-secondary">View Last Plan</a>
    </div>
    <?php if ($credits_remaining >= 0.25): ?>
    <div style="margin-top:16px;background:rgba(255,255,255,.15);border-radius:12px;padding:12px 16px;font-size:.85rem">
      ⚡ You have <strong><?= number_format($credits_remaining, 2) ?> credits</strong> remaining — enough for a <a href="smart_planner.php" style="color:#fff;font-weight:700">Smart Reality Check (0.25 cr)</a>!
    </div>
    <?php endif; ?>
  </div>  <?php else: ?>

  <!-- ──────────── PROGRESS BAR ────────────────────────────── -->
  <div class="fp-progress" role="navigation" aria-label="Stage progress">
    <?php foreach ($stages as $s): ?>
    <?php 
       $status_class = '';
       $is_done = in_array($s['num'], $completed);
       if ($is_done) $status_class = 'done';
       else if ($current_stage && $s['num'] == $current_stage['num']) $status_class = 'active';
    ?>
    <div class="fp-step <?php echo $status_class; ?>">
      <?php if ($is_done): ?>
        <a href="index.php?stage=<?php echo $s['num']; ?>" title="Go back to Stage <?php echo $s['num']; ?>: <?php echo htmlspecialchars($s['title']); ?>">
      <?php endif; ?>
      <div class="fp-step-circle">
        <?php if ($is_done): ?>✓<?php else: ?><?php echo $s['emoji']; ?><?php endif; ?>
      </div>
      <div class="fp-step-label"><?php echo $s['title']; ?></div>
      <?php if ($is_done): ?></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($show_report): ?>
  <!-- ══════════════════════════════════════════════════════
       REPORT VIEW
       ══════════════════════════════════════════════════════ -->
  <div class="fp-report">
    <div class="fp-report-header">
      <div style="font-size:3rem;margin-bottom:8px;">🌾</div>
      <h2>Your Farm Plan is Ready!</h2>
      <p>Complete 10-stage AI analysis for <?php echo htmlspecialchars($farm_data['farmer_name'] ?? 'Your Farm'); ?> · <?php echo htmlspecialchars($farm_data['city'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($farm_data['state'] ?? 'N/A'); ?></p>
    </div>

    <!-- Summary strip -->
    <div class="fp-report-summary">
      <div class="fp-report-summary-item">
        <div class="val"><?php echo htmlspecialchars($farm_data['confirmed_crop'] ?? $farm_data['crop_recommendation_TOP_CROP'] ?? '—'); ?></div>
        <div class="lbl">Recommended Crop</div>
      </div>
      <div class="fp-report-summary-item">
        <div class="val"><?php echo htmlspecialchars($farm_data['land_area'] ?? '—'); ?> Acres</div>
        <div class="lbl">Land Area</div>
      </div>
      <div class="fp-report-summary-item">
        <div class="val">₹<?php echo htmlspecialchars($farm_data['budget'] ?? '—'); ?></div>
        <div class="lbl">Total Budget</div>
      </div>
      <div class="fp-report-summary-item">
        <div class="val"><?php echo htmlspecialchars($farm_data['season'] ?? '—'); ?></div>
        <div class="lbl">Season</div>
      </div>
      <div class="fp-report-summary-item">
        <div class="val"><?php echo htmlspecialchars($farm_data['sowing_date'] ?? '—'); ?></div>
        <div class="lbl">Sowing Date</div>
      </div>
      <div class="fp-report-summary-item">
        <div class="val"><?php echo htmlspecialchars($farm_data['harvest_month'] ?? '—'); ?></div>
        <div class="lbl">Harvest Month</div>
      </div>
    </div>

    <!-- Stage sections (collapsible) -->
    <div class="fp-report-body">
      <?php foreach ($stages as $idx => $s): ?>
      <?php $gkey = 'gemini_' . $s['gemini_key']; ?>
      <div class="fp-stage-section">
        <div class="fp-stage-section-header <?php echo ($idx === 0) ? 'open' : ''; ?>" onclick="toggleSection(this)">
          <div style="display:flex; align-items:center; gap:12px; flex:1;">
            <span style="font-size:1.5rem"><?php echo $s['emoji']; ?></span>
            <h3 style="margin:0;">Stage <?php echo $s['num']; ?>: <?php echo $s['title']; ?></h3>
            <a href="index.php?stage=<?php echo $s['num']; ?>" class="fp-stage-edit-link" onclick="event.stopPropagation();" title="Edit this stage">
              ✏️ Edit
            </a>
          </div>
          <span class="chevron">▼</span>
        </div>
        <div class="fp-stage-section-body <?php echo ($idx === 0) ? 'open' : ''; ?>">

          <!-- Farmer inputs summary -->
          <table class="fp-inputs-table">
            <thead><tr><th>Field</th><th>Your Input</th></tr></thead>
            <tbody>
            <?php foreach ($s['fields'] as $field): ?>
            <?php $val = $farm_data[$field['name']] ?? ''; ?>
            <?php if ($val !== '' && $val !== 'Select'): ?>
            <tr><td><?php echo $field['label']; ?></td><td><?php echo htmlspecialchars($val); ?></td></tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
          </table>

          <!-- AI guidance -->
          <?php if (isset($farm_data[$gkey])): ?>
          <div style="font-size:.85rem;font-weight:700;color:#1b5e20;margin-bottom:8px;">🤖 InfoCrop AI Guidance:</div>
          <div class="fp-report-ai fp-ai-body">
            <?php echo render_ai_html($farm_data[$gkey]); ?>
          </div>
          <?php else: ?>
          <p style="color:#aaa;font-size:.85rem;">No AI guidance for this stage.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Action bar -->
    <div class="fp-action-bar">
      <a href="download.php" class="fp-btn-download">
        📥 Download PDF Farm Plan
      </a>
      <a href="smart_planner.php?step=1&prefill_report=<?= $report_id ?? $last_report_id ?? '' ?>" class="fp-btn-download" style="background:linear-gradient(135deg,#e65100,#bf360c);box-shadow:0 4px 14px rgba(230,81,0,.35)">
        ⚡ Smart Reality Check <small style="opacity:.8">(0.25 cr)</small>
      </a>
      <a href="report_history.php" class="fp-btn-download" style="background:linear-gradient(135deg,#1565c0,#0d47a1);box-shadow:0 4px 14px rgba(21,101,192,.35)">
        📋 My Saved Reports
      </a>
      <a href="index.php?action=restart" class="fp-btn-restart">
        🔄 Start New Plan
      </a>
    </div>
  </div>

  <?php elseif ($current_stage): ?>
  <!-- ══════════════════════════════════════════════════════
       STAGE WIZARD FORM
       ══════════════════════════════════════════════════════ -->
  <div class="fp-content">

    <!-- LEFT: Stage Form -->
    <div class="fp-stage-card hover-lift">
      <div class="fp-stage-header">
        <div class="fp-stage-tag">
          Stage <?php echo $current_stage['num']; ?> of 10
          &nbsp;·&nbsp;
          <?php echo $current_stage['title']; ?>
        </div>
        <h2><?php echo $current_stage['emoji']; ?> <?php echo $current_stage['title']; ?></h2>
        <p><?php echo $current_stage['subtitle']; ?></p>
      </div>

      <div class="fp-stage-body">
        <form action="index.php?stage=<?php echo $stage_num; ?>" method="POST" id="stageForm">

          <?php if ($stage_error): ?>
          <div class="fp-error-box">
            <div class="fp-error-icon">⚠️</div>
            <div class="fp-error-msg">
              <strong>Wait! The AI encountered an error:</strong><br>
              <?php echo htmlspecialchars($stage_error); ?><br>
              <span style="font-size:0.8rem;opacity:0.8;">Please check your internet and click "Next" to try again.</span>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($stage_num === 1): ?>
          <!-- ── PLANT MODE TOGGLE TABS (Stage 1 only) ── -->
          <?php $cur_plant_mode = $farm_data['plant_mode'] ?? 'new'; ?>
          <input type="hidden" name="plant_mode" id="plantModeInput" value="<?= htmlspecialchars($cur_plant_mode) ?>">

          <div class="pm-tabs">
            <button type="button" class="pm-tab <?= $cur_plant_mode === 'new' ? 'active' : '' ?>" id="pmTabNew"
                    onclick="switchPlantMode('new')">
              🌱 New Plant
              <span class="pm-mode-badge">Plan from scratch</span>
            </button>
            <button type="button" class="pm-tab <?= $cur_plant_mode === 'old' ? 'active' : '' ?>" id="pmTabOld"
                    onclick="switchPlantMode('old')">
              🌳 Old Plant / Existing Crop
              <span class="pm-mode-badge">Already growing</span>
            </button>
          </div>

          <!-- NEW PLANT PANEL -->
          <div id="panelNewPlant" style="<?= $cur_plant_mode === 'old' ? 'display:none' : '' ?>">
            <div class="fp-form-grid">
              <?php foreach ($current_stage['fields'] as $idx => $field):
                    if (($field['panel'] ?? 'new') === 'old') continue; ?>
              <div class="fp-field <?= ($field['type'] === 'text' && $idx === 0) ? 'full-width' : '' ?>">
                <label for="<?= $field['name'] ?>">
                  <?= $field['label'] ?>
                  <?php if ($field['required'] ?? false): ?><span class="req"> *</span><?php endif; ?>
                </label>
                <?php if ($field['type'] === 'select'): ?>
                <select name="<?= $field['name'] ?>" id="<?= $field['name'] ?>" <?php if ($field['required'] ?? false) echo 'required'; ?>>
                  <option value="">Select…</option>
                  <?php foreach ($field['options'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= (($farm_data[$field['name']] ?? '') === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
                <?php elseif ($field['type'] === 'date'): ?>
                <input type="date" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>"
                  <?php if ($field['required'] ?? false) echo 'required'; ?>>
                <?php elseif ($field['type'] === 'number'): ?>
                <input type="number" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>"
                  placeholder="<?= $field['placeholder'] ?? '' ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>"
                  min="<?= $field['min'] ?? '' ?>" max="<?= $field['max'] ?? '' ?>" step="<?= $field['step'] ?? 'any' ?>"
                  <?php if ($field['required'] ?? false) echo 'required'; ?>>
                <?php else: ?>
                <input type="text" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>"
                  placeholder="<?= $field['placeholder'] ?? '' ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>"
                  <?php if ($field['required'] ?? false) echo 'required'; ?>>
                <?php endif; ?>
                <?php if (isset($field['hint'])): ?>
                <div class="fp-hint">ℹ️ <?= $field['hint'] ?></div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div><!-- /#panelNewPlant -->

          <!-- OLD PLANT PANEL -->
          <div id="panelOldPlant" style="<?= $cur_plant_mode !== 'old' ? 'display:none' : '' ?>">

            <!-- Common fields applicable to both modes -->
            <div class="pm-divider">🧑‍🌾 Farmer & Farm Details</div>
            <div class="fp-form-grid">
              <?php
              $common_names = ['farmer_name','city','state','land_area','soil_type','season','budget','water_source','crop_goal','labor_type'];
              foreach ($current_stage['fields'] as $idx => $field):
                if (!in_array($field['name'], $common_names)) continue; ?>
              <div class="fp-field <?= ($field['name'] === 'farmer_name') ? 'full-width' : '' ?>">
                <label for="<?= $field['name'] ?>_old"><?= $field['label'] ?></label>
                <?php if ($field['type'] === 'select'): ?>
                <select name="<?= $field['name'] ?>" id="<?= $field['name'] ?>_old">
                  <option value="">Select…</option>
                  <?php foreach ($field['options'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= (($farm_data[$field['name']] ?? '') === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
                <?php elseif ($field['type'] === 'number'): ?>
                <input type="number" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>_old"
                  placeholder="<?= $field['placeholder'] ?? '' ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>"
                  min="<?= $field['min'] ?? '' ?>" step="<?= $field['step'] ?? 'any' ?>">
                <?php else: ?>
                <input type="text" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>_old"
                  placeholder="<?= $field['placeholder'] ?? '' ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>">
                <?php endif; ?>
                <?php if (isset($field['hint'])): ?>
                <div class="fp-hint">ℹ️ <?= $field['hint'] ?></div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Old-plant specific fields -->
            <div class="pm-divider">🌳 Existing Crop / Orchard Details</div>
            <div class="fp-form-grid">
              <?php foreach ($current_stage['fields'] as $idx => $field):
                    if (($field['panel'] ?? 'new') !== 'old') continue; ?>
              <div class="fp-field">
                <label for="<?= $field['name'] ?>"><?= $field['label'] ?></label>
                <?php if ($field['type'] === 'select'): ?>
                <select name="<?= $field['name'] ?>" id="<?= $field['name'] ?>">
                  <option value="">Select…</option>
                  <?php foreach ($field['options'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= (($farm_data[$field['name']] ?? '') === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
                <?php elseif ($field['type'] === 'date'): ?>
                <input type="date" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>">
                <?php elseif ($field['type'] === 'number'): ?>
                <input type="number" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>"
                  placeholder="<?= $field['placeholder'] ?? '' ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>"
                  min="<?= $field['min'] ?? '' ?>">
                <?php else: ?>
                <input type="text" name="<?= $field['name'] ?>" id="<?= $field['name'] ?>"
                  placeholder="<?= $field['placeholder'] ?? '' ?>"
                  value="<?= htmlspecialchars($farm_data[$field['name']] ?? '') ?>">
                <?php endif; ?>
                <?php if (isset($field['hint'])): ?>
                <div class="fp-hint">ℹ️ <?= $field['hint'] ?></div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>

          </div><!-- /#panelOldPlant -->

          <?php else: /* NOT Stage 1 — standard field rendering */ ?>
          <div class="fp-form-grid">
            <?php foreach ($current_stage['fields'] as $idx => $field): ?>
            <div class="fp-field <?php echo ($field['type'] === 'text' && $idx === 0) ? 'full-width' : ''; ?>">
              <label for="<?php echo $field['name']; ?>">
                <?php echo $field['label']; ?>
                <?php if ($field['required'] ?? false): ?><span class="req"> *</span><?php endif; ?>
              </label>

              <?php if ($field['type'] === 'select'): ?>
              <select name="<?php echo $field['name']; ?>" id="<?php echo $field['name']; ?>"
                <?php if ($field['required'] ?? false) echo 'required'; ?>>
                <option value="">Select…</option>
                <?php foreach ($field['options'] as $opt): ?>
                <option value="<?php echo $opt; ?>"
                  <?php if (($farm_data[$field['name']] ?? '') === $opt) echo 'selected'; ?>>
                  <?php echo $opt; ?>
                </option>
                <?php endforeach; ?>
              </select>

              <?php elseif ($field['type'] === 'date'): ?>
              <input type="date" name="<?php echo $field['name']; ?>" id="<?php echo $field['name']; ?>"
                value="<?php echo htmlspecialchars($farm_data[$field['name']] ?? ''); ?>"
                <?php if ($field['required'] ?? false) echo 'required'; ?>>

              <?php elseif ($field['type'] === 'number'): ?>
              <input type="number" name="<?php echo $field['name']; ?>" id="<?php echo $field['name']; ?>"
                placeholder="<?php echo $field['placeholder'] ?? ''; ?>"
                value="<?php echo htmlspecialchars($farm_data[$field['name']] ?? ''); ?>"
                min="<?php echo $field['min'] ?? ''; ?>"
                max="<?php echo $field['max'] ?? ''; ?>"
                step="<?php echo $field['step'] ?? 'any'; ?>"
                <?php if ($field['required'] ?? false) echo 'required'; ?>>

              <?php else: ?>
              <input type="text" name="<?php echo $field['name']; ?>" id="<?php echo $field['name']; ?>"
                placeholder="<?php echo $field['placeholder'] ?? ''; ?>"
                value="<?php echo htmlspecialchars($farm_data[$field['name']] ?? ''); ?>"
                <?php if ($field['required'] ?? false) echo 'required'; ?>>
              <?php endif; ?>

              <?php if (isset($field['hint'])): ?>
              <div class="fp-hint">ℹ️ <?php echo $field['hint']; ?></div>
              <?php endif; ?>

            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; /* end Stage 1 vs other stages */ ?>


          <!-- Stage tip -->
          <div style="background:#f0f7f0;border-radius:8px;padding:12px 16px;margin-top:20px;font-size:.82rem;color:#555;border-left:3px solid #43a047;">
            💡 <strong>Tip:</strong>
            <?php 
              $tips = [
                1 => "Fill accurate details — InfoCrop AI uses these to recommend the best crop for your specific land.",
                2 => "You can confirm the AI-recommended crop from Stage 1 or enter your own preferred crop.",
                3 => "If you don't know soil test values, enter \"Unknown\" — InfoCrop AI will recommend a soil test.",
                4 => "Drip irrigation can reduce water use by 40-50%. InfoCrop AI will check subsidy eligibility.",
                5 => "InfoCrop AI uses IMD seasonal forecasts and historical climate data for the region.",
                6 => "Mention any disease you've seen before — InfoCrop AI will give preventive spray schedule.",
                7 => "Enter your nearest APMC mandi. InfoCrop AI will show current prices and best selling window.",
                8 => "InfoCrop AI creates a week-by-week activity calendar based on your sowing and harvest dates.",
                9 => "Post-harvest losses in India average 20-30%. Proper storage advice can save crores.",
                10 => "This is the final stage. Your complete farm plan PDF will be ready after this!",
              ];
              echo $tips[$current_stage['num']] ?? '';
            ?>
          </div>

          <div class="fp-submit-row">
            <?php if ($current_stage['num'] > 1): ?>
            <a href="index.php?stage=<?php echo $current_stage['num'] - 1; ?>" class="fp-btn-back" title="Go back to Stage <?php echo $current_stage['num'] - 1; ?>">
              ← Back
            </a>
            <?php else: ?>
            <span></span><?php /* spacer so Next stays right-aligned on stage 1 */ ?>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:16px;">
              <span class="fp-stage-counter">
                <?php if ($current_stage['num'] === 10): ?>🎯 Last stage — Final step!
                <?php else: ?><?php echo (10 - $current_stage['num']); ?> more stage<?php echo (10 - $current_stage['num'] != 1) ? 's' : ''; ?> after this
                <?php endif; ?>
              </span>
              <?php
              // In old-plant mode on Stage 1, change subtitle of button
              $is_old_mode = ($stage_num === 1 && ($farm_data['plant_mode'] ?? 'new') === 'old');
              ?>
              <button type="submit" class="fp-btn-next" id="submitBtn">
                <?php if ($current_stage['num'] === 10): ?>
                  🎉 Generate Farm Plan <span class="arrow">→</span>
                <?php elseif ($is_old_mode): ?>
                  🌳 Get Stage Assessment &amp; Next <span class="arrow">→</span>
                <?php else: ?>
                  Get AI Guidance &amp; Next Stage <span class="arrow">→</span>
                <?php endif; ?>
              </button>
            </div>
          </div>

        </form>
      </div>
    </div>

    <!-- RIGHT: Gemini AI Guidance Panel -->
    <div class="fp-ai-panel hover-lift">
      <div class="fp-ai-header">
        <span style="font-size:1.2rem">🤖</span>
        <h3>InfoCrop AI Guidance</h3>
        <?php if ($prev_gemini): ?>
        <div class="pulse-dot"></div>
        <span class="fp-ai-stage-tag">
          <?php echo $panel_stage_label ?? ''; ?>
        </span>
        <?php endif; ?>
      </div>

      <div class="fp-ai-body" id="aiBody">
        <?php if ($prev_gemini): ?>
          <?php echo render_ai_html($prev_gemini); ?>
        <?php else: ?>
        <div class="fp-ai-empty">
          <div class="icon">🌱</div>
          <p>Fill in Stage 1 and InfoCrop AI will analyse your farm details in real-time.</p>
          <p style="margin-top:8px;font-size:.78rem;color:#bbb;">Powered by InfoCrop AI with current Indian agricultural data.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.fp-content -->
  <?php endif; ?>
  <?php endif; // End limit_reached else ?>

</div><!-- /.fp-wrapper -->

<script>
// ── Plant Mode Switch ──────────────────────────────────────
function switchPlantMode(mode) {
  var modeInput = document.getElementById('plantModeInput');
  var newPanel  = document.getElementById('panelNewPlant');
  var oldPanel  = document.getElementById('panelOldPlant');
  var tabNew    = document.getElementById('pmTabNew');
  var tabOld    = document.getElementById('pmTabOld');
  var submitBtn = document.getElementById('submitBtn');
  if (!newPanel || !oldPanel) return; // Only Stage 1 has these panels

  if (modeInput) modeInput.value = mode;

  function setPanel(panel, show) {
    panel.style.display = show ? '' : 'none';
    // Disable inputs in hidden panel to prevent validation + duplicate submission
    panel.querySelectorAll('input, select, textarea').forEach(function(el) {
      if (show) {
        el.removeAttribute('disabled');
      } else {
        el.setAttribute('disabled', 'disabled');
      }
    });
  }

  if (mode === 'old') {
    setPanel(newPanel, false);
    setPanel(oldPanel, true);
    if (tabNew) tabNew.classList.remove('active');
    if (tabOld) tabOld.classList.add('active');
    if (submitBtn) submitBtn.innerHTML = '🌳 Get Stage Assessment &amp; Next <span class="arrow">→</span>';
  } else {
    setPanel(newPanel, true);
    setPanel(oldPanel, false);
    if (tabOld) tabOld.classList.remove('active');
    if (tabNew) tabNew.classList.add('active');
    if (submitBtn) submitBtn.innerHTML = 'Get AI Guidance &amp; Next Stage <span class="arrow">→</span>';
  }
}

// ── Initialize plant mode state on page load ──────────────
(function() {
  var modeInput = document.getElementById('plantModeInput');
  if (modeInput) {
    switchPlantMode(modeInput.value || 'new');
  }
})();


// ── Form submit → show loading overlay ──────────────────
const form = document.getElementById('stageForm');
const overlay = document.getElementById('loadingOverlay');

if (form && overlay) {
  form.addEventListener('submit', function(e) {
    const invalid = form.querySelectorAll(':invalid');
    if (invalid.length === 0) {
      overlay.classList.add('show');
      
      // ── Animate Status Dots ──
      const dotSending = document.getElementById('loDotSending');
      const dotProcessing = document.getElementById('loDotProcessing');
      const dotReady = document.getElementById('loDotReady');
      const tipBox = document.getElementById('loTip');

      // Step 2: Processing (after 4s)
      setTimeout(() => {
        dotSending.className = 'lo-dot lo-dot-done-now';
        dotSending.innerHTML = '✓ Sent to InfoCrop AI';
        dotProcessing.className = 'lo-dot lo-dot-active';
        dotProcessing.innerHTML = '⏳ AI building your plan (up to 2 min)…';
      }, 4000);

      // Step 3: Mark ready only after a long wait (90s — actual response comes via page reload)
      setTimeout(() => {
        dotProcessing.innerHTML = '⏳ AI is writing detailed guidance…';
      }, 30000);
      setTimeout(() => {
        dotProcessing.innerHTML = '⏳ Almost done — finalising your plan…';
      }, 75000);

      // ── Rotate Tips ──
      const tips = [
        "🌾 Analysing soil-crop compatibility for your region…",
        "📊 Checking Farm Feasibility — water, budget, soil & heat risk…",
        "💧 Calculating irrigation requirements and pump-hours…",
        "🌡️ Reviewing weather risk and seasonal patterns for <?php echo htmlspecialchars($farm_data['state'] ?? 'India'); ?>…",
        "💰 Building profit scenarios — poor, normal & good season…",
        "🐛 Checking pest & disease risks for this season…",
        "📅 Creating your week-by-week farm activity calendar…",
        "🏪 Fetching APMC mandi market intelligence for <?php echo htmlspecialchars($farm_data['city'] ?? 'your region'); ?>…",
        "✨ Reviewing 2024-25 ICAR & government scheme recommendations…",
        "📋 Preparing your complete structured farm plan report…"
      ];
      let tipIdx = 0;
      setInterval(() => {
        tipIdx = (tipIdx + 1) % tips.length;
        tipBox.style.opacity = 0;
        setTimeout(() => {
          tipBox.innerText = tips[tipIdx];
          tipBox.style.opacity = 1;
        }, 400);
      }, 4000);
    }
  });
}

// ── Collapsible report sections ──────────────────────────
function toggleSection(header) {
  const body = header.nextElementSibling;
  header.classList.toggle('open');
  body.classList.toggle('open');
}

// ── Auto-scroll AI panel to top on new content ───────────
const aiBody = document.getElementById('aiBody');
if (aiBody) aiBody.scrollTop = 0;
</script>

<!-- ── Dynamic Footer ── -->
<?php include 'partials/footer.php'; ?>
</body>
</html>


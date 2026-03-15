<?php
// admin/api_keys.php — Gemini API Key Pool Manager
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php"); exit;
}

$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();

// ── Auto-create table if needed ──────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS gemini_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL DEFAULT 'Key',
    api_key VARCHAR(255) NOT NULL,
    platform VARCHAR(50) NOT NULL DEFAULT 'gemini',
    model VARCHAR(100) NOT NULL DEFAULT 'gemini-1.5-flash',
    base_url VARCHAR(255) NULL DEFAULT NULL,
    exhausted_date DATE NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    calls_today INT NOT NULL DEFAULT 0,
    last_call_date DATE NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Update existing table if needed ──────────────────────────────
$cols = $pdo->query("DESCRIBE gemini_api_keys")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('platform', $cols)) {
    $pdo->exec("ALTER TABLE gemini_api_keys ADD COLUMN platform VARCHAR(50) NOT NULL DEFAULT 'gemini' AFTER api_key");
}
if (!in_array('base_url', $cols)) {
    $pdo->exec("ALTER TABLE gemini_api_keys ADD COLUMN base_url VARCHAR(255) NULL DEFAULT NULL AFTER model");
}

$today     = date('Y-m-d');
$admin_name = $_SESSION['admin_user'] ?? 'Admin';
$message   = '';
$error     = '';

// ── POST actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label    = trim($_POST['label'] ?? 'Key ' . date('His'));
        $key      = trim($_POST['api_key'] ?? '');
        $platform = trim($_POST['platform'] ?? 'gemini');
        $model    = trim($_POST['model'] ?? '');
        $baseUrl  = trim($_POST['base_url'] ?? '');

        if (!$model) {
            $model = ($platform === 'gemini') ? 'gemini-1.5-flash' : 'gpt-3.5-turbo';
        }

        if ($key) {
            $pdo->prepare("INSERT INTO gemini_api_keys (label, api_key, platform, model, base_url) VALUES (?,?,?,?,?)")
                ->execute([$label, $key, $platform, $model, $baseUrl ?: null]);
            $message = "API key <strong>" . htmlspecialchars($label) . "</strong> added successfully.";
        } else {
            $error = "API key cannot be empty.";
        }
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM gemini_api_keys WHERE id = ?")->execute([(int)$_POST['id']]);
        $message = "Key deleted.";
    }

    if ($action === 'toggle') {
        $row = $pdo->prepare("SELECT is_active FROM gemini_api_keys WHERE id=?");
        $row->execute([(int)$_POST['id']]);
        $cur = $row->fetchColumn();
        $pdo->prepare("UPDATE gemini_api_keys SET is_active=? WHERE id=?")
            ->execute([$cur ? 0 : 1, (int)$_POST['id']]);
        $message = "Key status updated.";
    }

    if ($action === 'reset_all') {
        $pdo->exec("UPDATE gemini_api_keys SET exhausted_date = NULL, calls_today = 0");
        $message = "All keys reset — daily limits cleared.";
    }

    if ($action === 'reset_one') {
        $pdo->prepare("UPDATE gemini_api_keys SET exhausted_date = NULL, calls_today = 0 WHERE id=?")
            ->execute([(int)$_POST['id']]);
        $message = "Key reset successfully.";
    }

    if ($action === 'edit') {
        $id       = (int) $_POST['id'];
        $label    = trim($_POST['label'] ?? '');
        $platform = trim($_POST['platform'] ?? 'gemini');
        $model    = trim($_POST['model'] ?? '');
        $baseUrl  = trim($_POST['base_url'] ?? '');
        $newKey   = trim($_POST['api_key'] ?? '');

        if ($label && $model) {
            if ($newKey) {
                $pdo->prepare("UPDATE gemini_api_keys SET label=?, platform=?, model=?, base_url=?, api_key=? WHERE id=?")
                    ->execute([$label, $platform, $model, $baseUrl ?: null, $newKey, $id]);
            } else {
                $pdo->prepare("UPDATE gemini_api_keys SET label=?, platform=?, model=?, base_url=? WHERE id=?")
                    ->execute([$label, $platform, $model, $baseUrl ?: null, $id]);
            }
            $message = "Key <strong>" . htmlspecialchars($label) . "</strong> updated successfully.";
        } else {
            $error = "Label and Model are required.";
        }
    }

    header("Location: api_keys.php?msg=" . urlencode($message ?: $error) . "&type=" . ($error ? 'error' : 'ok'));
    exit;
}

// ── AJAX: Test a key ─────────────────────────────────────────────
if (isset($_GET['test_key_id'])) {
    header('Content-Type: application/json');
    $id = (int) $_GET['test_key_id'];
    $row = $pdo->prepare("SELECT * FROM gemini_api_keys WHERE id = ?");
    $row->execute([$id]);
    $keyRow = $row->fetch(PDO::FETCH_ASSOC);

    if (!$keyRow) {
        echo json_encode(['ok' => false, 'msg' => 'Key not found in database.']);
        exit;
    }

    $platform = $keyRow['platform'] ?? 'gemini';
    $testPrompt = 'Say hello in one sentence.';

    if ($platform === 'gemini') {
        $model = $keyRow['model'] ?: 'gemini-1.5-flash';
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $keyRow['api_key'];
        $data  = [
            'contents' => [['parts' => [['text' => $testPrompt]]]],
            'generationConfig' => ['maxOutputTokens' => 50]
        ];
        $headers = ['Content-Type: application/json'];
    } else {
        // OpenAI-compatible
        $endpoints = [
            'openai'     => 'https://api.openai.com/v1/chat/completions',
            'deepseek'   => 'https://api.deepseek.com/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'groq'       => 'https://api.groq.com/openai/v1/chat/completions',
            'together'   => 'https://api.together.xyz/v1/chat/completions',
            'moonshot'   => 'https://api.moonshot.ai/v1/chat/completions',
            'xai'        => 'https://api.x.ai/v1/chat/completions',
            'cohere'     => 'https://api.cohere.com/v2/chat/completions',
            'meta'       => 'https://api.llama.com/v1/chat/completions',
        ];
        $url = $keyRow['base_url'] ?: ($endpoints[$platform] ?? $endpoints['openai']);
        if (strpos($url, '/chat/completions') === false) {
            $url = rtrim($url, '/') . '/chat/completions';
        }
        $data = [
            'model'    => $keyRow['model'] ?: 'gpt-3.5-turbo',
            'messages' => [['role' => 'user', 'content' => $testPrompt]],
            'max_tokens' => 50
        ];
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $keyRow['api_key']
        ];
        if ($platform === 'openrouter') {
            $headers[] = 'HTTP-Referer: https://infocropai.free.nf';
            $headers[] = 'X-Title: InfoCrop AI';
        }
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['ok' => false, 'msg' => "Connection error: $curlErr"]);
        exit;
    }

    $result = json_decode($response, true);

    if ($httpCode === 200) {
        // Extract a preview of the response
        if ($platform === 'gemini') {
            $preview = $result['candidates'][0]['content']['parts'][0]['text'] ?? 'Response OK (no text)';
        } else {
            $preview = $result['choices'][0]['message']['content'] ?? 'Response OK (no text)';
        }
        echo json_encode(['ok' => true, 'msg' => '✅ Working! Response: "' . mb_substr(trim($preview), 0, 80) . '…"']);
    } elseif ($httpCode === 429) {
        echo json_encode(['ok' => false, 'msg' => '⚠️ Rate-limited (429) — Key is valid but has hit its usage limit for now. It will auto-reset later.']);
    } elseif ($httpCode === 401) {
        $errMsg = $result['error']['message'] ?? '';
        echo json_encode(['ok' => false, 'msg' => "❌ Unauthorized (401) — The API key is invalid or was entered incorrectly. Please double-check the key. Detail: $errMsg"]);
    } elseif ($httpCode === 403) {
        $errMsg = $result['error']['message'] ?? '';
        $reason = 'The API key has been revoked, leaked, or your account lacks permission.';
        if (stripos($errMsg, 'leak') !== false) $reason = 'This key was reported as LEAKED. Generate a new key from the provider dashboard.';
        elseif (stripos($errMsg, 'billing') !== false || stripos($errMsg, 'quota') !== false) $reason = 'Billing issue — your account may not have an active plan or has exceeded its spending limit.';
        elseif (stripos($errMsg, 'permission') !== false) $reason = 'Permission denied — your account may not have access to this model.';
        echo json_encode(['ok' => false, 'msg' => "❌ Forbidden (403) — $reason Detail: $errMsg"]);
    } elseif ($httpCode === 404) {
        echo json_encode(['ok' => false, 'msg' => "❌ Not Found (404) — The model name or API endpoint is wrong. Check if the model \"" . htmlspecialchars($keyRow['model']) . "\" exists on " . strtoupper($platform) . "."]);
    } elseif ($httpCode === 500 || $httpCode === 502 || $httpCode === 503) {
        echo json_encode(['ok' => false, 'msg' => "⚠️ Server Error ($httpCode) — The " . strtoupper($platform) . " server is temporarily down or overloaded. Try again in a few minutes."]);
    } else {
        $errMsg = $result['error']['message'] ?? ($result['error'] ?? "Unknown error");
        if (is_array($errMsg)) $errMsg = json_encode($errMsg);
        echo json_encode(['ok' => false, 'msg' => "❌ Error ($httpCode) — $errMsg"]);
    }
    exit;
}

if (isset($_GET['msg'])) {
    if ($_GET['type'] === 'error') $error   = $_GET['msg'];
    else                           $message = $_GET['msg'];
}

// ── Fetch keys ────────────────────────────────────────────────────
$keys = $pdo->query("SELECT * FROM gemini_api_keys ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API Keys | InfoCrop Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--dark:#0f172a;--card:#1e293b;--border:#334155;--text:#e2e8f0;--muted:#94a3b8;--green:#22c55e;--green-d:#16a34a;--amber:#f59e0b;--red:#ef4444;--blue:#3b82f6}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:var(--dark);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden}
    
    /* ── Sidebar ── */
    .sidebar{width:250px;flex-shrink:0;background:linear-gradient(180deg,#0a1628,#0f172a);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;height:100vh;z-index:100}
    .sidebar-logo{padding:28px 24px 20px;border-bottom:1px solid var(--border)}
    .sidebar-logo .logo-icon{font-size:1.8rem}
    .sidebar-logo h2{font-size:1rem;font-weight:700;margin-top:4px}
    .sidebar-logo p{font-size:.7rem;color:var(--green);font-weight:500;text-transform:uppercase;letter-spacing:.1em}
    .sidebar nav{flex:1;padding:16px 12px}
    .nav-label{color:var(--muted);font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;padding:8px 12px 4px}
    .nav-link{display:flex;align-items:center;gap:10px;color:var(--muted);text-decoration:none;padding:10px 12px;border-radius:8px;font-size:.875rem;font-weight:500;transition:all .15s;margin-bottom:2px}
    .nav-link .icon{font-size:1rem;width:22px;text-align:center;flex-shrink:0}
    .nav-link:hover{background:rgba(255,255,255,.05);color:var(--text)}
    .nav-link.active{background:rgba(34,197,94,.12);color:var(--green)}
    .badge-count{margin-left:auto;background:var(--amber);color:#000;font-size:.7rem;font-weight:700;padding:1px 7px;border-radius:20px}
    .sidebar-footer{padding:16px 12px;border-top:1px solid var(--border)}
    .sidebar-footer a{display:flex;align-items:center;gap:10px;color:var(--red);text-decoration:none;padding:10px 12px;border-radius:8px;font-size:.875rem;font-weight:600;transition:background .15s}
    .sidebar-footer a:hover{background:rgba(239,68,68,.1)}

    /* ── Main ── */
    .main{flex:1;margin-left:250px;display:flex;flex-direction:column;min-width:0}
    .topbar{background:var(--card);border-bottom:1px solid var(--border);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
    .topbar h1{font-size:1.15rem;font-weight:700}
    .admin-chip{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:var(--green);padding:6px 14px;border-radius:20px;font-size:.8rem;font-weight:600}
    .content{padding:32px;flex:1}

    /* ── Responsive Overhaul ── */
    @media (max-width: 1024px) {
      .sidebar { left: -250px; transition: left 0.3s ease; }
      .sidebar.open { left: 0; box-shadow: 10px 0 30px rgba(0,0,0,0.5); }
      .main { margin-left: 0; }
      .topbar { padding: 12px 16px; }
      .content { padding: 16px; }
      .summary-bar { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 640px) {
      .summary-bar { grid-template-columns: 1fr; }
      .form-row { grid-template-columns: 1fr; }
      .key-card { grid-template-columns: 1fr; text-align: center; justify-items: center; }
      .key-actions { align-items: center; width: 100%; border-top: 1px solid var(--border); padding-top: 16px; margin-top: 8px; flex-direction: row; justify-content: center; gap: 12px; }
      .key-stats { justify-content: center; }
      .topbar h1 { font-size: 1rem; }
    }

    /* ── Hamburger Toggle ── */
    .menu-toggle {
      display: none;
      background: rgba(255,255,255,.05);
      border: 1px solid var(--border);
      color: var(--text);
      padding: 8px 12px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 1.2rem;
    }
    @media (max-width: 1024px) { .menu-toggle { display: block; } }

    /* ── Overlay ── */
    .sidebar-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.5);
      z-index: 90;
      backdrop-filter: blur(2px);
    }
    .sidebar-overlay.active { display: block; }

    /* ── Existing Admin Styles ── */
    .alert{padding:12px 18px;border-radius:10px;font-size:.875rem;font-weight:500;margin-bottom:24px}
    .alert-ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:var(--green)}
    .alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red)}
    .summary-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px}
    .summary-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
    .summary-card .s-val{font-size:1.8rem;font-weight:800;line-height:1}
    .summary-card .s-lbl{font-size:.72rem;color:var(--muted);margin-top:4px;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
    .add-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:26px;margin-bottom:28px}
    .add-card h2{font-size:.95rem;font-weight:700;margin-bottom:18px;display:flex;align-items:center;gap:8px}
    .form-row{display:grid;grid-template-columns:1fr 2fr 1fr;gap:16px;align-items:end}
    .form-group label{display:block;color:var(--muted);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:7px}
    .form-group input,.form-group select{width:100%;background:var(--dark);border:1.5px solid var(--border);border-radius:10px;color:var(--text);font-family:'Inter',sans-serif;font-size:.875rem;padding:11px 14px;outline:none;transition:border-color .2s}
    .form-group input:focus,.form-group select:focus{border-color:var(--green)}
    .form-group select option{background:var(--card)}
    .input-wrap{position:relative}
    .input-wrap .eye-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;font-size:.9rem;padding:4px}
    .input-wrap .eye-btn:hover{color:var(--green)}
    .btn-add{background:linear-gradient(135deg,var(--green),var(--green-d));color:#fff;border:none;padding:12px 26px;border-radius:10px;font-family:'Inter',sans-serif;font-size:.875rem;font-weight:700;cursor:pointer;transition:opacity .2s,transform .2s;white-space:nowrap}
    .btn-add:hover{opacity:.9;transform:translateY(-1px)}
    .keys-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
    .keys-header h2{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px}
    .btn-reset-all{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber);padding:8px 18px;border-radius:8px;font-family:'Inter',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;transition:background .2s}
    .btn-reset-all:hover{background:rgba(245,158,11,.22)}
    .key-card{background:var(--card);border:1.5px solid var(--border);border-radius:16px;padding:22px 24px;margin-bottom:14px;display:grid;grid-template-columns:auto 1fr auto;gap:20px;align-items:center;transition:border-color .2s}
    .key-card.is-current{border-color:rgba(34,197,94,.5);box-shadow:0 0 0 1px rgba(34,197,94,.2)}
    .key-card.is-exhausted{border-color:rgba(239,68,68,.35)}
    .key-card.is-disabled{opacity:.55}
    .key-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
    .key-icon.current-icon{background:rgba(34,197,94,.15)}
    .key-icon.exhausted-icon{background:rgba(239,68,68,.12)}
    .key-icon.disabled-icon{background:rgba(148,163,184,.1)}
    .key-icon.ok-icon{background:rgba(59,130,246,.12)}
    .key-meta h3{font-size:.95rem;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:8px}
    .key-masked{font-family:monospace;font-size:.78rem;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid var(--border);padding:3px 9px;border-radius:6px;cursor:pointer;transition:background .15s;user-select:none}
    .key-masked:hover{background:rgba(255,255,255,.09)}
    .key-stats{display:flex;gap:14px;margin-top:8px;flex-wrap:wrap}
    .key-stat{font-size:.72rem;color:var(--muted);font-weight:500}
    .key-stat strong{color:var(--text);font-weight:700}
    .chip{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em}
    .chip-green{background:rgba(34,197,94,.15);color:#4ade80}
    .chip-red{background:rgba(239,68,68,.15);color:#f87171}
    .chip-amber{background:rgba(245,158,11,.15);color:#fbbf24}
    .chip-muted{background:rgba(148,163,184,.12);color:var(--muted)}
    .chip-pulse{animation:pulse-chip 1.8s infinite}
    @keyframes pulse-chip{0%,100%{opacity:1}50%{opacity:.5}}
    .key-actions{display:flex;flex-direction:column;gap:8px;align-items:flex-end}
    .btn-sm{padding:6px 14px;border-radius:8px;font-family:'Inter',sans-serif;font-size:.75rem;font-weight:700;cursor:pointer;border:none;transition:opacity .15s}
    .btn-toggle-on{background:rgba(34,197,94,.15);color:#4ade80}
    .btn-toggle-off{background:rgba(148,163,184,.12);color:var(--muted)}
    .btn-toggle-on:hover,.btn-toggle-off:hover{opacity:.75}
    .btn-del{background:rgba(239,68,68,.12);color:#f87171}
    .btn-del:hover{opacity:.75}
    .btn-reset{background:rgba(245,158,11,.12);color:var(--amber)}
    .btn-reset:hover{opacity:.75}
    .btn-test{background:rgba(59,130,246,.15);color:#60a5fa}
    .btn-test:hover{opacity:.75}
    .btn-test:disabled{opacity:.5;cursor:wait}
    .test-result{display:block;width:100%;font-size:.72rem;padding:4px 0;line-height:1.4;word-break:break-word}
    .test-ok{color:#4ade80}
    .test-fail{color:#f87171}
    .btn-edit{background:rgba(139,92,246,.15);color:#a78bfa}
    .btn-edit:hover{opacity:.75}
    /* Edit Modal */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);z-index:1000;justify-content:center;align-items:center}
    .modal-overlay.open{display:flex}
    .modal-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px 32px;width:95%;max-width:540px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
    .modal-box h2{font-size:1.1rem;margin-bottom:18px;color:var(--text)}
    .modal-box .form-group{margin-bottom:14px}
    .modal-box .form-group label{display:block;font-size:.75rem;color:var(--muted);margin-bottom:4px;font-weight:600}
    .modal-box .form-group input,.modal-box .form-group select{width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--dark);color:var(--text);font-size:.85rem;font-family:'Inter',sans-serif}
    .modal-box .form-group input:focus,.modal-box .form-group select:focus{border-color:var(--blue);outline:none}
    .modal-btns{display:flex;gap:10px;margin-top:20px;justify-content:flex-end}
    .modal-btns .btn-save{padding:8px 22px;border-radius:10px;background:var(--blue);color:#fff;border:none;font-weight:700;font-size:.8rem;cursor:pointer}
    .modal-btns .btn-save:hover{opacity:.85}
    .modal-btns .btn-cancel{padding:8px 22px;border-radius:10px;background:rgba(148,163,184,.12);color:var(--muted);border:none;font-weight:700;font-size:.8rem;cursor:pointer}
    .modal-btns .btn-cancel:hover{opacity:.75}
    .empty-state{text-align:center;padding:60px 20px;color:var(--muted)}
    .empty-state .big-icon{font-size:3rem;margin-bottom:12px}
    .empty-state p{font-size:.9rem}
    .live-dot{width:8px;height:8px;background:var(--green);border-radius:50%;display:inline-block;animation:pulse-chip 1.5s infinite}
  </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🌿</div>
    <h2>InfoCrop Admin</h2>
    <p>Control Panel</p>
  </div>
  <nav>
    <div class="nav-label">Menu</div>
    <a href="index.php" class="nav-link"><span class="icon">📊</span> Dashboard</a>
    <a href="users.php" class="nav-link"><span class="icon">👥</span> Manage Users</a>
    <a href="payments.php" class="nav-link">
      <span class="icon">💳</span> Payments
      <?php if ($pending_payments > 0): ?><span class="badge-count"><?php echo $pending_payments; ?></span><?php endif; ?>
    </a>
    <a href="referral_links.php" class="nav-link">
      <span class="icon">🔗</span> Referral Links
    </a>
    <a href="reports.php"  class="nav-link"><span class="icon">📋</span> Farm Reports</a>
    <a href="settings.php" class="nav-link"><span class="icon">⚙️</span> System Settings</a>
    <a href="api_keys.php" class="nav-link active"><span class="icon">🔑</span> API Keys</a>
  </nav>
  <div class="sidebar-footer"><a href="logout.php"><span>🚪</span> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>🔑 API Keys Pool</h1>
    </div>
    <div class="admin-chip">👤 <?php echo htmlspecialchars($admin_name); ?></div>
  </div>
  <div class="content">

    <?php if ($message): ?>
    <div class="alert alert-ok">✅ <?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- ── Summary Stats ── -->
    <?php
      $total     = count($keys);
      $active_ok = 0; $exhausted = 0; $disabled = 0; $total_calls = 0;
      foreach ($keys as $k) {
          if (!$k['is_active'])                         $disabled++;
          elseif ($k['exhausted_date'] === $today)      $exhausted++;
          else                                          $active_ok++;
          if ($k['last_call_date'] === $today)          $total_calls += (int)$k['calls_today'];
      }
    ?>
    <div class="summary-bar">
      <div class="summary-card">
        <div class="s-val"><?php echo $total; ?></div>
        <div class="s-lbl">Total Keys</div>
      </div>
      <div class="summary-card">
        <div class="s-val" style="color:var(--green)"><?php echo $active_ok; ?></div>
        <div class="s-lbl">🟢 Available Today</div>
      </div>
      <div class="summary-card">
        <div class="s-val" style="color:var(--red)"><?php echo $exhausted; ?></div>
        <div class="s-lbl">🔴 Exhausted Today</div>
      </div>
      <div class="summary-card">
        <div class="s-val" style="color:var(--muted)"><?php echo $disabled; ?></div>
        <div class="s-lbl">⚫ Disabled</div>
      </div>
      <div class="summary-card">
        <div class="s-val" style="color:var(--amber)"><?php echo $total_calls; ?></div>
        <div class="s-lbl">📡 Calls Today</div>
      </div>
    </div>

    <!-- ── Add Key Form ── -->
    <div class="add-card">
      <h2>➕ Add New AI Key</h2>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="form-row" style="grid-template-columns: 1fr 1fr 1fr; margin-bottom: 16px;">
          <div class="form-group">
            <label>Label / Nickname</label>
            <input type="text" name="label" placeholder="e.g. My OpenAI Key" required>
          </div>
          <div class="form-group">
            <label>Platform</label>
            <select name="platform" id="platformSelect" onchange="updateModelSuggestions()" required>
              <option value="gemini">Google Gemini</option>
              <option value="openai">OpenAI</option>
              <option value="deepseek">DeepSeek</option>
              <option value="groq">Groq</option>
              <option value="openrouter">OpenRouter</option>
              <option value="together">Together AI</option>
              <option value="moonshot">Moonshot / Kimi</option>
              <option value="xai">xAI / Grok</option>
              <option value="cohere">Cohere</option>
              <option value="meta">Meta / Llama</option>
            </select>
          </div>
          <div class="form-group">
            <label>Model</label>
            <input type="text" name="model" id="modelInput" list="model-suggestions" placeholder="e.g. gemini-1.5-flash" required value="gemini-1.5-flash">
            <datalist id="model-suggestions">
              <optgroup label="Gemini" id="opt-gemini">
                <option value="gemini-3-flash-preview">gemini-3-flash-preview ⚡</option>
                <option value="gemini-1.5-pro">gemini-1.5-pro 🧠</option>
                <option value="gemini-2.0-flash">gemini-2.0-flash 🚀</option>
              </optgroup>
            </datalist>
          </div>
        </div>
        <div class="form-row" style="grid-template-columns: 2fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>API Key</label>
            <div class="input-wrap">
              <input type="password" name="api_key" id="newKeyInput" placeholder="sk-..." required>
              <button type="button" class="eye-btn" onclick="toggleNewKey(this)">👁️</button>
            </div>
          </div>
          <div class="form-group">
            <label>Custom Base URL (Optional)</label>
            <input type="url" name="base_url" placeholder="https://api.example.com/v1">
          </div>
        </div>
        <div style="margin-top:16px">
          <button type="submit" class="btn-add">🔑 Add AI Key</button>
          <span style="font-size:.75rem;color:var(--muted);margin-left:14px">Random rotation is enabled across all active keys.</span>
        </div>
      </form>
    </div>

    <!-- ── Keys List ── -->
    <div class="keys-header">
      <h2>🗝️ Configured Keys <span style="color:var(--muted);font-weight:500;font-size:.8rem;margin-left:6px"><?php echo $total; ?> total — auto-refreshes daily</span></h2>
      <?php if ($total > 0): ?>
      <form method="POST" onsubmit="return confirm('Reset daily limit counters for all keys?')">
        <input type="hidden" name="action" value="reset_all">
        <button type="submit" class="btn-reset-all">🔄 Reset All Limits</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if (empty($keys)): ?>
    <div class="empty-state">
      <div class="big-icon">🔑</div>
      <p>No API keys configured yet.<br>Add your first Gemini API key above.</p>
    </div>
    <?php else: ?>
    <?php foreach ($keys as $i => $k):
      $isActive    = ($k['is_active'] && $k['exhausted_date'] !== $today);
      $isExhausted = ($k['is_active'] && $k['exhausted_date'] === $today);
      $isDisabled  = !$k['is_active'];
      $cardClass   = $isActive ? 'is-current' : ($isExhausted ? 'is-exhausted' : ($isDisabled ? 'is-disabled' : ''));
      $iconClass   = $isActive ? 'current-icon' : ($isExhausted ? 'exhausted-icon' : ($isDisabled ? 'disabled-icon' : 'ok-icon'));
      $iconEmoji   = $isActive ? '⚡' : ($isExhausted ? '🚫' : ($isDisabled ? '⚫' : '🔑'));
      $maskedKey   = substr($k['api_key'], 0, 8) . str_repeat('•', 24) . substr($k['api_key'], -4);
      $callsToday  = ($k['last_call_date'] === $today) ? (int)$k['calls_today'] : 0;
    ?>
    <div class="key-card <?php echo $cardClass; ?>">
      <!-- Icon -->
      <div class="key-icon <?php echo $iconClass; ?>"><?php echo $iconEmoji; ?></div>

      <!-- Meta -->
      <div class="key-meta">
        <h3>
          <?php echo htmlspecialchars($k['label']); ?>
          <?php if ($isActive): ?>
            <span class="chip chip-green chip-pulse"><span class="live-dot"></span> ACTIVE</span>
          <?php elseif ($isExhausted): ?>
            <span class="chip chip-red">🔴 EXHAUSTED</span>
          <?php elseif ($isDisabled): ?>
            <span class="chip chip-muted">⚫ DISABLED</span>
          <?php endif; ?>
          <span class="chip chip-muted" style="text-transform:uppercase;"><?php echo htmlspecialchars($k['platform']); ?></span>
          <span class="chip chip-amber" style="font-size:.65rem"><?php echo htmlspecialchars($k['model']); ?></span>
        </h3>

        <span class="key-masked" onclick="revealKey(this, '<?php echo htmlspecialchars($k['api_key']); ?>')"
              title="Click to reveal">
          <?php echo $maskedKey; ?> 🔍
        </span>

        <div class="key-stats">
          <div class="key-stat">Calls today: <strong><?php echo $callsToday; ?></strong></div>
          <div class="key-stat">Base: <strong><?php echo $k['base_url'] ?: 'Default'; ?></strong></div>
          <?php if ($k['exhausted_date'] === $today): ?>
            <div class="key-stat" style="color:var(--red)">⏰ Exhausted: <strong>resets tomorrow at midnight</strong></div>
          <?php elseif ($k['exhausted_date']): ?>
            <div class="key-stat">Last exhausted: <strong><?php echo $k['exhausted_date']; ?></strong></div>
          <?php else: ?>
            <div class="key-stat">Never exhausted</div>
          <?php endif; ?>
          <div class="key-stat">Added: <strong><?php echo date('d M Y', strtotime($k['created_at'])); ?></strong></div>
          <div class="key-stat">Priority: <strong>#<?php echo $i + 1; ?></strong></div>
        </div>
      </div>

      <!-- Actions -->
      <div class="key-actions">
        <!-- Toggle enable/disable -->
        <form method="POST">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
          <button type="submit" class="btn-sm <?php echo $k['is_active'] ? 'btn-toggle-on' : 'btn-toggle-off'; ?>">
            <?php echo $k['is_active'] ? '✅ Enabled' : '⚫ Disabled'; ?>
          </button>
        </form>
        <!-- Reset single key -->
        <?php if ($isExhausted): ?>
        <form method="POST" onsubmit="return confirm('Reset this key\'s daily limit?')">
          <input type="hidden" name="action" value="reset_one">
          <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
          <button type="submit" class="btn-sm btn-reset">🔄 Reset Limit</button>
        </form>
        <?php endif; ?>
        <!-- Delete -->
        <form method="POST" onsubmit="return confirm('Delete this API key permanently?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
          <button type="submit" class="btn-sm btn-del">🗑️ Delete</button>
        </form>
        <!-- Test Key -->
        <button type="button" class="btn-sm btn-test" onclick="testKey(<?php echo $k['id']; ?>, this)" title="Send a tiny test prompt to verify this key works">
          🧪 Test Key
        </button>
        <!-- Edit Key -->
        <button type="button" class="btn-sm btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($k)); ?>)" title="Edit this key's details">
          ✏️ Edit
        </button>
        <span class="test-result" id="test-result-<?php echo $k['id']; ?>"></span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:24px;padding:16px;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:12px;font-size:.8rem;color:#93c5fd;line-height:1.7">
      <strong>ℹ️ How rotation works:</strong><br>
      Keys are <strong>randomly shuffled</strong> for every request to distribute load. If a key returns a <em>429 rate-limit error</em>, it is automatically
      marked as <strong>Exhausted</strong> for today and the next random key is used. Supports Gemini, OpenAI, DeepSeek, Grok, and more.
    </div>

  </div>
</div>

<!-- ── Edit Key Modal ── -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <h2>✏️ Edit AI Key</h2>
    <form method="POST" id="editForm">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="form-group">
        <label>Label / Nickname</label>
        <input type="text" name="label" id="editLabel" required>
      </div>
      <div class="form-group">
        <label>Platform</label>
        <select name="platform" id="editPlatform">
          <option value="gemini">Google Gemini</option>
          <option value="openai">OpenAI</option>
          <option value="deepseek">DeepSeek</option>
          <option value="groq">Groq</option>
          <option value="openrouter">OpenRouter</option>
          <option value="together">Together AI</option>
          <option value="moonshot">Moonshot / Kimi</option>
          <option value="xai">xAI / Grok</option>
          <option value="cohere">Cohere</option>
          <option value="meta">Meta / Llama</option>
        </select>
      </div>
      <div class="form-group">
        <label>Model</label>
        <input type="text" name="model" id="editModel" required>
      </div>
      <div class="form-group">
        <label>Custom Base URL (Optional)</label>
        <input type="url" name="base_url" id="editBaseUrl" placeholder="Leave blank for default">
      </div>
      <div class="form-group">
        <label>New API Key (leave blank to keep current)</label>
        <input type="password" name="api_key" id="editApiKey" placeholder="Leave blank to keep unchanged">
      </div>
      <div class="modal-btns">
        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
        <button type="submit" class="btn-save">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('active');
}
function toggleNewKey(btn) {
  const f = document.getElementById('newKeyInput');
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁️' : '🙈';
}
function revealKey(el, fullKey) {
  if (el.dataset.revealed === '1') {
    el.textContent = el.dataset.masked + ' 🔍';
    el.dataset.revealed = '0';
  } else {
    if (!el.dataset.masked) el.dataset.masked = el.textContent.replace(' 🔍','').trim();
    el.textContent = fullKey + ' 📋';
    el.dataset.revealed = '1';
    // Auto-copy to clipboard
    navigator.clipboard?.writeText(fullKey);
  }
}
function updateModelSuggestions() {
  const platform = document.getElementById('platformSelect').value;
  const datalist = document.getElementById('model-suggestions');
  const input = document.getElementById('modelInput');
  
  let options = '';
  switch(platform) {
    case 'gemini':
      options = '<option value="gemini-1.5-flash">gemini-1.5-flash</option><option value="gemini-1.5-pro">gemini-1.5-pro</option><option value="gemini-2.0-flash">gemini-2.0-flash</option>';
      input.value = 'gemini-1.5-flash';
      break;
    case 'openai':
      options = '<option value="gpt-3.5-turbo">gpt-3.5-turbo</option><option value="gpt-4o">gpt-4o</option><option value="gpt-4o-mini">gpt-4o-mini</option>';
      input.value = 'gpt-4o-mini';
      break;
    case 'deepseek':
      options = '<option value="deepseek-chat">deepseek-chat</option><option value="deepseek-reasoner">deepseek-reasoner</option>';
      input.value = 'deepseek-chat';
      break;
    case 'groq':
      options = '<option value="llama-3.3-70b-versatile">llama-3.3-70b-versatile</option><option value="mixtral-8x7b-32768">mixtral-8x7b-32768</option>';
      input.value = 'llama-3.3-70b-versatile';
      break;
    case 'xai':
      options = '<option value="grok-2-latest">grok-2-latest</option><option value="grok-beta">grok-beta</option>';
      input.value = 'grok-2-latest';
      break;
    case 'cohere':
      options = '<option value="command-r-plus">command-r-plus</option><option value="command-r">command-r</option><option value="command">command</option>';
      input.value = 'command-r-plus';
      break;
    case 'meta':
      options = '<option value="Llama-4-Maverick-17B-128E-Instruct-FP8">Llama-4-Maverick</option><option value="Llama-3.3-70B-Instruct">Llama-3.3-70B</option>';
      input.value = 'Llama-4-Maverick-17B-128E-Instruct-FP8';
      break;
    default:
      options = '<option value="">Custom Model</option>';
  }
  datalist.innerHTML = options;
}

async function testKey(keyId, btn) {
  const resultEl = document.getElementById('test-result-' + keyId);
  const origText = btn.textContent;
  btn.disabled = true;
  btn.textContent = '⏳ Testing...';
  resultEl.textContent = '';
  resultEl.className = 'test-result';

  try {
    const resp = await fetch('api_keys.php?test_key_id=' + keyId);
    const data = await resp.json();
    resultEl.textContent = data.msg;
    resultEl.className = 'test-result ' + (data.ok ? 'test-ok' : 'test-fail');
  } catch (e) {
    resultEl.textContent = '❌ Network error: ' + e.message;
    resultEl.className = 'test-result test-fail';
  }

  btn.disabled = false;
  btn.textContent = origText;
}

function openEditModal(keyData) {
  document.getElementById('editId').value = keyData.id;
  document.getElementById('editLabel').value = keyData.label;
  document.getElementById('editPlatform').value = keyData.platform || 'gemini';
  document.getElementById('editModel').value = keyData.model;
  document.getElementById('editBaseUrl').value = keyData.base_url || '';
  document.getElementById('editApiKey').value = '';
  document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
  document.getElementById('editModal').classList.remove('open');
}

// Close modal on overlay click
document.getElementById('editModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});
</script>

</body>
</html>

<?php
// admin/settings.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php"); exit;
}

$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Text Settings
    if (isset($_POST['settings'])) {
        foreach ($_POST['settings'] as $key => $value) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$key, trim($value), trim($value)]);
        }
    }

    // Handle QR Code Upload
    if (isset($_FILES['payment_qr']) && $_FILES['payment_qr']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'svg'];
        $filename = $_FILES['payment_qr']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = "qr_code." . $ext;
            $upload_dir = '../uploads/system/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $dest = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['payment_qr']['tmp_name'], $dest)) {
                // Save path in settings
                $db_path = 'uploads/system/' . $new_filename;
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('payment_qr_path', ?) ON DUPLICATE KEY UPDATE setting_value=?")
                    ->execute([$db_path, $db_path]);
            }
        }
    }
    
    $message = "Settings saved successfully!";
}

$stmt = $pdo->query("SELECT * FROM settings");
$settings = [];
foreach ($stmt->fetchAll() as $s) { $settings[$s['setting_key']] = $s['setting_value']; }
$admin_name = $_SESSION['admin_user'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings | InfoCrop Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root { --dark:#0f172a; --card:#1e293b; --border:#334155; --text:#e2e8f0; --muted:#94a3b8; --green:#22c55e; --green-d:#16a34a; --amber:#f59e0b; --red:#ef4444; --blue:#3b82f6; }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:var(--dark);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden}

    /* ── Sidebar ── */
    .sidebar {
      width: 250px; flex-shrink: 0;
      background: linear-gradient(180deg, #0a1628 0%, #0f172a 100%);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: fixed; height: 100vh; z-index: 100;
      transition: left 0.3s ease;
    }
    .sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid var(--border); }
    .sidebar-logo .logo-icon { font-size: 1.8rem; }
    .sidebar-logo h2 { font-size: 1rem; font-weight: 700; color: var(--text); margin-top: 4px; }
    .sidebar-logo p { font-size: 0.7rem; color: var(--green); font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; }

    .sidebar nav { flex: 1; padding: 16px 12px; }
    .nav-label { color: var(--muted); font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; padding: 8px 12px 4px; }
    .nav-link {
      display: flex; align-items: center; gap: 10px;
      color: var(--muted); text-decoration: none;
      padding: 10px 12px; border-radius: 8px;
      font-size: 0.875rem; font-weight: 500;
      transition: all 0.15s; margin-bottom: 2px;
    }
    .nav-link .icon { font-size: 1rem; width: 22px; text-align: center; flex-shrink: 0; }
    .nav-link:hover { background: rgba(255,255,255,0.05); color: var(--text); }
    .nav-link.active { background: rgba(34,197,94,0.12); color: var(--green); }
    .badge-count { margin-left: auto; background: var(--amber); color: #000; font-size: 0.7rem; font-weight: 700; padding: 1px 7px; border-radius: 20px; }

    .sidebar-footer { padding: 16px 12px; border-top: 1px solid var(--border); }
    .sidebar-footer a {
      display: flex; align-items: center; gap: 10px;
      color: var(--red); text-decoration: none;
      padding: 10px 12px; border-radius: 8px;
      font-size: 0.875rem; font-weight: 600;
      transition: background 0.15s;
    }
    .sidebar-footer a:hover { background: rgba(239,68,68,0.1); }

    /* ── Main ── */
    .main { flex: 1; margin-left: 250px; display: flex; flex-direction: column; min-height: 100vh; min-width: 0; }

    .topbar {
      background: var(--card); border-bottom: 1px solid var(--border);
      padding: 16px 32px; display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 50;
    }
    .topbar h1 { font-size: 1.15rem; font-weight: 700; color: var(--text); }
    .admin-chip {
      background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2);
      color: var(--green); padding: 6px 14px; border-radius: 20px;
      font-size: 0.8rem; font-weight: 600;
    }

    .content { padding: 32px; flex: 1; max-width: 1000px; }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
      .sidebar { left: -250px; }
      .sidebar.open { left: 0; box-shadow: 10px 0 30px rgba(0,0,0,0.5); }
      .main { margin-left: 0; }
      .topbar { padding: 12px 16px; }
      .content { padding: 16px; }
    }

    .menu-toggle {
      display: none; background: rgba(255,255,255,.05); border: 1px solid var(--border);
      color: var(--text); padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: 1.2rem;
    }
    @media (max-width: 1024px) { .menu-toggle { display: block; } }

    .sidebar-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
      z-index: 90; backdrop-filter: blur(2px);
    }
    .sidebar-overlay.active { display: block; }

    /* ── Settings Specific ── */
    .alert { padding: 12px 18px; border-radius: 12px; font-size: .875rem; font-weight: 500; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); }
    
    .settings-grid { display: grid; gap: 24px; }
    .settings-card { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 32px; position: relative; overflow: hidden; }
    .settings-card-header { margin-bottom: 24px; }
    .settings-card-title { font-size: 1.1rem; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
    .settings-card-desc { color: var(--muted); font-size: 0.85rem; line-height: 1.6; }

    .form-group { margin-bottom: 24px; }
    .form-group:last-child { margin-bottom: 0; }
    .form-group label { display: block; color: var(--muted); font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
    
    .input-control { width: 100%; background: #0f172a; border: 1px solid var(--border); border-radius: 12px; color: var(--text); padding: 12px 16px; font-family: inherit; font-size: 0.95rem; transition: all 0.2s; outline: none; }
    .input-control:focus { border-color: var(--green); box-shadow: 0 0 0 4px rgba(34,197,94,0.1); }
    .input-control::placeholder { color: var(--muted); opacity: 0.5; }

    .hint-text { margin-top: 8px; font-size: 0.75rem; color: var(--muted); display: flex; align-items: center; gap: 6px; }

    .btn-save { background: linear-gradient(135deg, var(--green), var(--green-d)); color: #fff; border: none; padding: 14px 32px; border-radius: 12px; font-size: 0.95rem; font-weight: 700; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 10px; box-shadow: 0 4px 12px rgba(34,197,94,0.2); }
    .btn-save:hover { transform: translateY(-2px); filter: brightness(1.1); box-shadow: 0 6px 20px rgba(34,197,94,0.3); }

    .qr-preview { width: 100px; height: 100px; border-radius: 12px; border: 1px solid var(--border); background: #fff; padding: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .qr-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }

    .ai-badge { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); padding: 24px; border-radius: 16px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; margin-bottom: 32px; }
    .ai-badge-content { flex: 1; min-width: 250px; }
    .ai-badge-content h4 { color: var(--green); font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
    .ai-badge-content p { color: var(--muted); font-size: 0.85rem; }
    .ai-badge-link { background: var(--green); color: #fff; padding: 10px 20px; border-radius: 10px; text-decoration: none; font-size: 0.85rem; font-weight: 700; transition: all 0.2s; }
    .ai-badge-link:hover { transform: translateY(-1px); filter: brightness(1.1); }

    .file-input { display: block; width: 100%; font-size: 0.875rem; color: var(--muted); }
    .file-input::file-selector-button { background: rgba(255,255,255,0.05); border: 1px solid var(--border); padding: 8px 16px; border-radius: 8px; color: var(--text); font-weight: 600; cursor: pointer; transition: all 0.2s; margin-right: 16px; }
    .file-input::file-selector-button:hover { background: rgba(255,255,255,0.1); }

    textarea.input-control { height: 100px; resize: vertical; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
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
    <a href="settings.php" class="nav-link active"><span class="icon">⚙️</span> System Settings</a>
    <a href="api_keys.php" class="nav-link"><span class="icon">🔑</span> API Keys</a>
  </nav>
  <div class="sidebar-footer"><a href="logout.php"><span>🚪</span> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>System Settings</h1>
    </div>
    <div class="admin-chip">👤 <?php echo htmlspecialchars($admin_name); ?></div>
  </div>

  <div class="content">

    <?php if ($message): ?>
    <div class="alert">
      <span>✅</span>
      <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <div class="ai-badge">
      <div class="ai-badge-content">
        <h4>🤖 Gemini AI Configuration</h4>
        <p>API keys and model rotation are now managed in the dedicated API Keys section for better limit handling.</p>
      </div>
      <a href="api_keys.php" class="ai-badge-link">Manage Keys 🔑</a>
    </div>

    <form method="POST" enctype="multipart/form-data" class="settings-grid">
      
      <!-- Payment Settings -->
      <div class="settings-card">
        <div class="settings-card-header">
          <div class="settings-card-title">💳 Payment Configuration</div>
          <div class="settings-card-desc">Set up your UPI details for processing user payments.</div>
        </div>

        <div class="form-group">
          <label>UPI ID for Payments</label>
          <input type="text" name="settings[payment_upi_id]" class="input-control" 
                 value="<?php echo htmlspecialchars($settings['payment_upi_id'] ?? 'jagadledayanand2550@okicici'); ?>"
                 placeholder="yourname@upi">
          <div class="hint-text">💡 This UPI ID will be displayed to users on the recharge page.</div>
        </div>

        <div class="form-group">
          <label>Payment QR Code</label>
          <div style="display:flex; gap:24px; align-items:center;">
            <?php if (isset($settings['payment_qr_path'])): ?>
            <div class="qr-preview">
              <img src="../<?php echo htmlspecialchars($settings['payment_qr_path']); ?>" alt="Current QR">
            </div>
            <?php endif; ?>
            <div style="flex:1;">
              <input type="file" name="payment_qr" accept="image/*" class="file-input">
              <div class="hint-text">Allows JPG, PNG or SVG. Max size 2MB.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Website Details -->
      <div class="settings-card">
        <div class="settings-card-header">
          <div class="settings-card-title">🌐 Brand & Contact Info</div>
          <div class="settings-card-desc">Information displayed across the website and PDF reports.</div>
        </div>

        <div class="form-group">
          <label>Application Name</label>
          <input type="text" name="settings[site_name]" class="input-control" 
                 value="<?php echo htmlspecialchars($settings['site_name'] ?? 'InfoCrop AI'); ?>">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Support Phone</label>
            <input type="text" name="settings[contact_phone]" class="input-control" 
                   value="<?php echo htmlspecialchars($settings['contact_phone'] ?? '+91 8010094034'); ?>">
          </div>
          <div class="form-group">
            <label>Support Email</label>
            <input type="email" name="settings[contact_email]" class="input-control" 
                   value="<?php echo htmlspecialchars($settings['contact_email'] ?? 'jagadledayanand2550@gmail.com'); ?>">
          </div>
        </div>

        <div class="form-group">
          <label>Website URL</label>
          <input type="text" name="settings[contact_website]" class="input-control" 
                 value="<?php echo htmlspecialchars($settings['contact_website'] ?? 'infocropai.free.nf'); ?>">
        </div>

        <div class="form-group">
          <label>Office Address</label>
          <input type="text" name="settings[contact_address]" class="input-control" 
                 value="<?php echo htmlspecialchars($settings['contact_address'] ?? 'Agri-Tech Park, Pune, Maharashtra'); ?>">
        </div>

        <div class="form-group">
          <label>Footer Narrative</label>
          <textarea name="settings[contact_footer_text]" class="input-control" 
                    placeholder="Brief description for the footer..."><?php echo htmlspecialchars($settings['contact_footer_text'] ?? 'Empowering Indian Farmers with artificial intelligence.'); ?></textarea>
        </div>
      </div>

      <!-- Plan Configuration -->
      <div class="settings-card">
        <div class="settings-card-header">
          <div class="settings-card-title">📈 Plan Pricing & Limits</div>
          <div class="settings-card-desc">Configure the costs and report limits for user plans.</div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Starter Plan Price (₹)</label>
            <input type="number" name="settings[plan_starter_price]" class="input-control" 
                   value="<?php echo htmlspecialchars($settings['plan_starter_price'] ?? '50'); ?>">
          </div>
          <div class="form-group">
            <label>Starter Plan Limit (Reports)</label>
            <input type="number" name="settings[plan_starter_limit]" class="input-control" 
                   value="<?php echo htmlspecialchars($settings['plan_starter_limit'] ?? '10'); ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Pro Farmer Price (₹)</label>
            <input type="number" name="settings[plan_pro_price]" class="input-control" 
                   value="<?php echo htmlspecialchars($settings['plan_pro_price'] ?? '100'); ?>">
          </div>
          <div class="form-group">
            <label>Pro Farmer Limit (Reports)</label>
            <input type="number" name="settings[plan_pro_limit]" class="input-control" 
                   value="<?php echo htmlspecialchars($settings['plan_pro_limit'] ?? '25'); ?>">
          </div>
        </div>
      </div>

      <div style="margin-top:8px;">
        <button type="submit" class="btn-save">💾 Save All Changes</button>
      </div>
    </form>

  </div>
</div>

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('active');
}
</script>

</body>
</html>

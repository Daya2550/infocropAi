<?php
// admin/referral_links.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['admin_id'];
$success_msg = '';
$error_msg = '';

// Handle Link Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_link'])) {
    $usage_limit = intval($_POST['user_usage_limit'] ?? 1);
    
    if ($usage_limit < 1) {
        $error_msg = "Usage limit must be at least 1.";
    } else {
        try {
            $token = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("INSERT INTO referral_links (token, user_usage_limit, created_by) VALUES (?, ?, ?)");
            if ($stmt->execute([$token, $usage_limit, $admin_id])) {
                $host = $_SERVER['HTTP_HOST'];
                $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $root = str_replace('/admin/referral_links.php', '', $_SERVER['SCRIPT_NAME']);
                $full_link = "$proto://$host$root/signup.php?ref=$token";
                $success_msg = "Link generated: <code id='new-link'>$full_link</code> <button onclick='copyLink()' class='btn-copy'>Copy</button>";
            }
        } catch (Exception $e) {
            $error_msg = "Error: " . $e->getMessage();
        }
    }
}

// Handle Link Deletion/Expiry
if (isset($_GET['delete'])) {
    $link_id = intval($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM referral_links WHERE id = ?");
    $stmt->execute([$link_id]);
    header("Location: referral_links.php");
    exit;
}

// Fetch Links
$links = $pdo->query("SELECT r.*, a.username as creator FROM referral_links r JOIN admins a ON r.created_by = a.id ORDER BY r.created_at DESC")->fetchAll();

$admin_name = $_SESSION['admin_user'] ?? 'Admin';
$site_name = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'")->fetchColumn() ?: 'InfoCrop AI';

// Pending payments for badge
$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Referral Links | <?php echo htmlspecialchars($site_name); ?> Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --dark:   #0f172a;
      --card:   #1e293b;
      --border: #334155;
      --text:   #e2e8f0;
      --muted:  #94a3b8;
      --green:  #22c55e;
      --green-d:#16a34a;
      --blue:   #3b82f6;
      --amber:  #f59e0b;
      --red:    #ef4444;
    }
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; background: var(--dark); color: var(--text); display: flex; min-height: 100vh; overflow-x: hidden; }

    /* ── Sidebar ── */
    .sidebar { width: 250px; flex-shrink: 0; background: linear-gradient(180deg, #0a1628 0%, #0f172a 100%); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; transition: left 0.3s ease; }
    .sidebar-logo { padding: 28px 24px 20px; border-bottom: 1px solid var(--border); }
    .sidebar-logo h2 { font-size: 1rem; font-weight: 700; color: var(--text); margin-top: 4px; }
    .sidebar-logo p  { font-size: 0.7rem; color: var(--green); font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; }
    .sidebar nav { flex: 1; padding: 16px 12px; }
    .nav-label { color: var(--muted); font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; padding: 8px 12px 4px; }
    .nav-link { display: flex; align-items: center; gap: 10px; color: var(--muted); text-decoration: none; padding: 10px 12px; border-radius: 8px; font-size: 0.875rem; font-weight: 500; transition: all 0.15s; margin-bottom: 2px; }
    .nav-link:hover { background: rgba(255,255,255,0.05); color: var(--text); }
    .nav-link.active { background: rgba(34,197,94,0.12); color: var(--green); }
    .badge-count { margin-left: auto; background: var(--amber); color: #000; font-size: 0.7rem; font-weight: 700; padding: 1px 7px; border-radius: 20px; }
    .sidebar-footer { padding: 16px 12px; border-top: 1px solid var(--border); }
    .sidebar-footer a { display: flex; align-items: center; gap: 10px; color: var(--red); text-decoration: none; padding: 10px 12px; border-radius: 8px; font-size: 0.875rem; font-weight: 600; transition: background 0.15s; }
    .sidebar-footer a:hover { background: rgba(239,68,68,0.1); }

    .main { flex: 1; margin-left: 250px; display: flex; flex-direction: column; min-height: 100vh; min-width: 0; }
    .topbar { background: var(--card); border-bottom: 1px solid var(--border); padding: 16px 32px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
    .topbar h1 { font-size: 1.15rem; font-weight: 700; color: var(--text); }
    .admin-chip { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .content { padding: 32px; flex: 1; }

    .panel { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
    .panel-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); }
    .panel-header h2 { font-size: 0.95rem; font-weight: 700; }

    .form-group { margin-bottom: 15px; padding: 24px; }
    .form-group label { display: block; margin-bottom: 8px; font-size: 0.875rem; color: var(--muted); }
    .form-group input { width: 100%; max-width: 300px; padding: 10px 14px; background: var(--dark); border: 1px solid var(--border); border-radius: 8px; color: var(--text); outline: none; }
    .btn-gen { background: var(--green); color: #000; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
    .btn-gen:hover { background: var(--green-d); transform: translateY(-1px); }

    table { width: 100%; border-collapse: collapse; }
    th { padding: 16px 24px; text-align: left; font-size: 0.72rem; color: var(--muted); text-transform: uppercase; background: rgba(255,255,255,0.02); }
    td { padding: 14px 24px; font-size: 0.85rem; border-top: 1px solid var(--border); }
    .badge { padding: 3px 9px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
    .badge-used { background: rgba(239,68,68,0.1); color: var(--red); }
    .badge-active { background: rgba(34,197,94,0.1); color: var(--green); }
    
    .alert { padding: 14px 24px; border-radius: 8px; margin-bottom: 20px; font-size: 0.875rem; }
    .alert-success { background: rgba(34,197,94,0.1); border: 1px solid var(--green); color: var(--green); }
    .alert-error { background: rgba(239,68,68,0.1); border: 1px solid var(--red); color: var(--red); }

    .btn-copy { background: rgba(59,130,246,0.1); color: var(--blue); border: 1px solid var(--blue); padding: 4px 12px; border-radius: 8px; font-size: 0.7rem; cursor: pointer; margin-left: 10px; }
    .btn-delete { color: var(--red); text-decoration: none; font-size: 0.8rem; }
    
    @media (max-width: 1024px) { .sidebar { left: -250px; } .main { margin-left: 0; } }
  </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🌿</div>
    <h2>InfoCrop Admin</h2>
    <p>Control Panel</p>
  </div>
  <nav>
    <div class="nav-label">Menu</div>
    <a href="index.php" class="nav-link">📊 Dashboard</a>
    <a href="users.php" class="nav-link">👥 Manage Users</a>
    <a href="payments.php" class="nav-link">💳 Payments <?php if ($pending_payments > 0): ?><span class="badge-count"><?php echo $pending_payments; ?></span><?php endif; ?></a>
    <a href="referral_links.php" class="nav-link active">🔗 Referral Links</a>
    <a href="reports.php" class="nav-link">📋 Farm Reports</a>
    <a href="settings.php" class="nav-link">⚙️ System Settings</a>
    <a href="api_keys.php" class="nav-link">🔑 API Keys</a>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php">🚪 Logout</a>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <h1>Referral Link Management</h1>
    <div class="admin-chip">👤 <?php echo htmlspecialchars($admin_name); ?></div>
  </div>

  <div class="content">
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-header">
        <h2>Generate One-Time Referral Link</h2>
      </div>
      <form method="POST" class="form-group">
        <label>Usage Limit for New User (Enter the limit for user)</label>
        <div style="display:flex; gap:10px;">
            <input type="number" name="user_usage_limit" value="5" min="1" required>
            <button type="submit" name="generate_link" class="btn-gen">Generate Link</button>
        </div>
        <p style="font-size:0.75rem; color:var(--muted); margin-top:8px;">This link will be valid for <b>one registration only</b>. The registered user will receive the limit you specify above.</p>
      </form>
    </div>

    <div class="panel">
      <div class="panel-header">
        <h2>Existing Referral Links</h2>
      </div>
      <div style="overflow-x:auto;">
        <table>
          <thead>
            <tr>
              <th>Token / Link</th>
              <th>Grant Limit</th>
              <th>Status</th>
              <th>Created By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($links as $link): 
                $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $root = str_replace('/admin/referral_links.php', '', $_SERVER['SCRIPT_NAME']);
                $lnk = "$proto://$host$root/signup.php?ref=" . $link['token'];
            ?>
            <tr>
              <td>
                <div style="font-weight:600; font-family:monospace;"><?php echo $link['token']; ?></div>
                <div style="font-size:0.7rem; color:var(--muted); margin-top:4px;"><?php echo $lnk; ?></div>
              </td>
              <td><b><?php echo $link['user_usage_limit']; ?></b> uses</td>
              <td>
                <?php if ($link['is_used']): ?>
                    <span class="badge badge-used">USED / EXPIRED</span>
                <?php else: ?>
                    <span class="badge badge-active">ACTIVE</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.8rem;"><?php echo htmlspecialchars($link['creator']); ?></td>
              <td>
                <a href="?delete=<?php echo $link['id']; ?>" class="btn-delete" onclick="return confirm('Delete this link?')">Delete</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($links)): ?>
            <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--muted);">No referral links generated yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function copyLink() {
  const linkText = document.getElementById('new-link').innerText;
  navigator.clipboard.writeText(linkText).then(() => {
    alert('Link copied to clipboard!');
  });
}
</script>

</body>
</html>

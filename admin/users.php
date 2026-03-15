<?php
// admin/users.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php"); exit;
}

$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
$message = ''; $msg_type = 'success';

// Handle Actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] === 'suspend') {
        $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$id]);
        $message = "User suspended successfully.";
    } elseif ($_GET['action'] === 'activate') {
        $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
        $message = "User activated successfully.";
    } elseif ($_GET['action'] === 'delete') {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        $message = "User deleted."; $msg_type = 'warning';
    }
    header("Location: users.php?msg=" . urlencode($message) . "&type=" . $msg_type); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_limit'])) {
    $id = (int)$_POST['user_id'];
    $new_limit = max(0,(int)$_POST['limit']);
    $pdo->prepare("UPDATE users SET usage_limit=? WHERE id=?")->execute([$new_limit, $id]);
    $message = "Usage limit updated.";
}

if (isset($_GET['msg'])) { $message = urldecode($_GET['msg']); $msg_type = $_GET['type'] ?? 'success'; }

$search = trim($_GET['q'] ?? '');
if ($search) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (name LIKE ? OR phone LIKE ?) ORDER BY created_at DESC");
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
}
$users = $stmt->fetchAll();
$admin_name = $_SESSION['admin_user'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Users | InfoCrop Admin</title>
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

    .content { padding: 32px; flex: 1; }

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

    /* ── Users Specific ── */
    .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .search-box {
      display: flex; align-items: center; gap: 10px; background: var(--card);
      border: 1px solid var(--border); border-radius: 12px; padding: 10px 16px; min-width: 300px;
      transition: border-color 0.2s;
    }
    .search-box:focus-within { border-color: var(--green); }
    .search-box input { background: none; border: none; outline: none; color: var(--text); font-size: .95rem; font-family: inherit; flex: 1; }
    .search-box input::placeholder { color: var(--muted); }

    .alert { padding: 12px 18px; border-radius: 12px; font-size: .875rem; font-weight: 500; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); }
    .alert-warning { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.2); color: var(--amber); }

    .table-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    th { padding: 16px 20px; font-size: .72rem; font-weight: 600; color: var(--muted); text-align: left; text-transform: uppercase; letter-spacing: .05em; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border); }
    td { padding: 16px 20px; font-size: .875rem; border-bottom: 1px solid rgba(51,65,85,.5); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(255,255,255,0.015); }

    .avatar { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--green), var(--green-d)); display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: .95rem; flex-shrink: 0; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
    .user-info { display: flex; align-items: center; gap: 14px; }
    .user-name { font-weight: 600; font-size: 0.95rem; color: var(--text); }
    .user-phone { font-size: .8rem; color: var(--muted); margin-top: 1px; }

    .usage-wrap { min-width: 120px; }
    .usage-text { font-size: .8rem; color: var(--muted); margin-bottom: 5px; font-weight: 500; }
    .usage-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
    .usage-fill { height: 100%; border-radius: 3px; transition: width 0.3s ease; }

    .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .badge-active { background: rgba(34,197,94,0.15); color: var(--green); }
    .badge-suspended { background: rgba(239,68,68,0.15); color: var(--red); }

    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn { padding: 7px 14px; border-radius: 8px; font-size: .8rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 5px; }
    .btn:hover { transform: translateY(-1px); filter: brightness(1.1); }
    .btn-suspend { background: rgba(245,158,11,0.15); color: var(--amber); }
    .btn-activate { background: rgba(34,197,94,0.15); color: var(--green); }
    .btn-delete { background: rgba(239,68,68,0.15); color: var(--red); }
    .btn-reports { background: rgba(59,130,246,0.15); color: var(--blue); }

    .limit-form { display: flex; gap: 8px; align-items: center; }
    .limit-form input { width: 65px; padding: 6px 10px; background: rgba(15,23,42,0.5); border: 1px solid var(--border); border-radius: 8px; color: var(--text); font-size: .9rem; text-align: center; }
    .btn-save-limit { background: rgba(59,130,246,0.15); color: var(--blue); padding: 6px 12px; border-radius: 8px; border: none; cursor: pointer; font-size: .8rem; font-weight: 600; }

    .empty-row { padding: 60px; text-align: center; color: var(--muted); font-size: .95rem; }

    /* Security Metadata */
    .sec-tag { font-family: monospace; font-size: 0.75rem; color: var(--muted); background: rgba(255,255,255,0.03); padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 4px; border: 1px solid var(--border); }
    .loc-tag { color: var(--green); font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 4px; margin: 4px 0; }
    .loc-tag a { text-decoration: none; color: inherit; border-bottom: 1px dashed var(--green); }
    .loc-tag a:hover { border-bottom-style: solid; }
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
    <a href="users.php" class="nav-link active"><span class="icon">👥</span> Manage Users</a>
    <a href="payments.php" class="nav-link">
      <span class="icon">💳</span> Payments
      <?php if ($pending_payments > 0): ?><span class="badge-count"><?php echo $pending_payments; ?></span><?php endif; ?>
    </a>
    <a href="referral_links.php" class="nav-link">
      <span class="icon">🔗</span> Referral Links
    </a>
    <a href="reports.php"  class="nav-link"><span class="icon">📋</span> Farm Reports</a>
    <a href="settings.php" class="nav-link"><span class="icon">⚙️</span> System Settings</a>
    <a href="api_keys.php" class="nav-link"><span class="icon">🔑</span> API Keys</a>
  </nav>
  <div class="sidebar-footer"><a href="logout.php"><span>🚪</span> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Manage Users</h1>
    </div>
    <div class="admin-chip">👤 <?php echo htmlspecialchars($admin_name); ?></div>
  </div>

  <div class="content">

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
      <span><?php echo $msg_type === 'success' ? '✅' : '⚠️'; ?></span>
      <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <div class="toolbar">
      <form method="GET" class="search-box">
        <span>🔍</span>
        <input type="text" name="q" placeholder="Search by name or phone…" value="<?php echo htmlspecialchars($search); ?>">
      </form>
      <div style="color:var(--muted);font-size:.85rem;font-weight:500"><?php echo count($users); ?> users total</div>
    </div>

    <div class="table-card">
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>User Information</th>
              <th>Usage Stats</th>
              <th>Security & Location</th>
              <th>Status</th>
              <th>Limit</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="6" class="empty-row">👤 No users found matching your search.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u):
              $initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($u['name'])))));
              $initials = substr($initials, 0, 2);
              $pct = $u['usage_limit'] > 0 ? min(100, $u['usage_count'] / $u['usage_limit'] * 100) : 0;
              $bar_color = $pct >= 90 ? 'var(--red)' : ($pct >= 60 ? 'var(--amber)' : 'var(--green)');
            ?>
            <tr>
              <td>
                <div class="user-info">
                  <div class="avatar"><?php echo htmlspecialchars($initials) ?: '?'; ?></div>
                  <div>
                    <div class="user-name"><?php echo htmlspecialchars($u['name']); ?></div>
                    <div class="user-phone"><?php echo htmlspecialchars($u['phone']); ?></div>
                  </div>
                </div>
              </td>
              <td class="usage-wrap">
                <div class="usage-text"><?php echo $u['usage_count']; ?> / <?php echo $u['usage_limit']; ?> plans</div>
                <div class="usage-bar"><div class="usage-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $bar_color; ?>"></div></div>
              </td>
              <td>
                <div style="display:flex; flex-direction:column; gap:2px;">
                  <div style="font-size:0.8rem; font-weight:600; color:var(--text)"><?php echo htmlspecialchars($u['signup_ip'] ?: '0.0.0.0'); ?></div>
                  <?php if ($u['signup_location']): ?>
                    <div style="font-size:0.75rem; color:var(--muted)"><?php echo htmlspecialchars($u['signup_location']); ?></div>
                  <?php endif; ?>
                  <?php if ($u['latitude']): ?>
                    <div class="loc-tag">📍 <a href="https://www.google.com/maps?q=<?php echo $u['latitude']; ?>,<?php echo $u['longitude']; ?>" target="_blank">View on Map</a></div>
                  <?php endif; ?>
                  <div class="sec-tag" title="Device Fingerprint">ID: <?php echo substr(htmlspecialchars($u['device_fingerprint'] ?: 'N/A'), 0, 10); ?>...</div>
                </div>
              </td>
              <td><span class="badge badge-<?php echo $u['status']; ?>"><?php echo strtoupper($u['status']); ?></span></td>
              <td>
                <form method="POST" class="limit-form">
                  <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                  <input type="number" name="limit" value="<?php echo $u['usage_limit']; ?>" min="0" max="9999">
                  <button type="submit" name="update_limit" class="btn-save-limit">Save</button>
                </form>
              </td>
              <td>
                <div class="actions">
                  <a href="reports.php?user_id=<?php echo $u['id']; ?>" class="btn btn-reports">📋 Reports</a>
                  <?php if ($u['status'] === 'active'): ?>
                    <a href="users.php?action=suspend&id=<?php echo $u['id']; ?>" class="btn btn-suspend">Suspend</a>
                  <?php else: ?>
                    <a href="users.php?action=activate&id=<?php echo $u['id']; ?>" class="btn btn-activate">Activate</a>
                  <?php endif; ?>
                  <a href="users.php?action=delete&id=<?php echo $u['id']; ?>"
                     class="btn btn-delete"
                     onclick="return confirm('Delete user <?php echo htmlspecialchars(addslashes($u['name'])); ?>? This action is permanent.')">Delete</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

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

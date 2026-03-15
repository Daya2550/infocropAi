<?php
// admin/index.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// Stats
$user_count       = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$active_users     = $pdo->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
$total_revenue    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved'")->fetchColumn();
try { $total_reports = $pdo->query("SELECT COUNT(*) FROM farm_reports")->fetchColumn(); } catch(Exception $e) { $total_reports = 0; }

// Recent Users
$recent_users = $pdo->query("SELECT name, phone, usage_count, usage_limit, status, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();

// Recent Payments
$recent_payments = $pdo->query("SELECT p.amount, p.status, p.created_at, u.name FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 5")->fetchAll();

// Recent Reports
try {
    $recent_reports = $pdo->query("SELECT r.crop, r.location, r.created_at, u.name as uname FROM farm_reports r JOIN users u ON r.user_id=u.id ORDER BY r.created_at DESC LIMIT 5")->fetchAll();
} catch(Exception $e) { $recent_reports = []; }

$admin_name = $_SESSION['admin_user'] ?? 'Admin';

// Fetch Site Name
$site_name = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'")->fetchColumn() ?: 'InfoCrop AI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | <?php echo htmlspecialchars($site_name); ?> Admin</title>
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
      --purple: #a855f7;
    }
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; background: var(--dark); color: var(--text); display: flex; min-height: 100vh; overflow-x: hidden; }

    /* ── Sidebar ── */
    .sidebar {
      width: 250px; flex-shrink: 0;
      background: linear-gradient(180deg, #0a1628 0%, #0f172a 100%);
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      position: fixed; height: 100vh; z-index: 100;
      transition: left 0.3s ease;
    }
    .sidebar-logo {
      padding: 28px 24px 20px;
      border-bottom: 1px solid var(--border);
    }
    .sidebar-logo .logo-icon { font-size: 1.8rem; }
    .sidebar-logo h2 { font-size: 1rem; font-weight: 700; color: var(--text); margin-top: 4px; }
    .sidebar-logo p  { font-size: 0.7rem; color: var(--green); font-weight: 500; text-transform: uppercase; letter-spacing: 0.1em; }

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
    .badge-count {
      margin-left: auto;
      background: var(--amber); color: #000;
      font-size: 0.7rem; font-weight: 700;
      padding: 1px 7px; border-radius: 20px;
    }

    .sidebar-footer {
      padding: 16px 12px;
      border-top: 1px solid var(--border);
    }
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
      background: var(--card);
      border-bottom: 1px solid var(--border);
      padding: 16px 32px;
      display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 50;
    }
    .topbar h1 { font-size: 1.15rem; font-weight: 700; color: var(--text); }
    .admin-chip {
      background: rgba(34,197,94,0.1);
      border: 1px solid rgba(34,197,94,0.2);
      color: var(--green);
      padding: 6px 14px; border-radius: 20px;
      font-size: 0.8rem; font-weight: 600;
    }

    .content { padding: 32px; flex: 1; }

    /* ── Responsive Overhaul ── */
    @media (max-width: 1024px) {
      .sidebar { left: -250px; }
      .sidebar.open { left: 0; box-shadow: 10px 0 30px rgba(0,0,0,0.5); }
      .main { margin-left: 0; }
      .topbar { padding: 12px 16px; }
      .content { padding: 16px; }
    }

    /* ── Hamburger ── */
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

    /* ── Stats Grid ── */
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 32px; }
    @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
    @media (max-width: 640px) { .stats-grid { grid-template-columns: 1fr; } }

    .stat-card {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 16px; padding: 24px;
      display: flex; flex-direction: column; gap: 10px;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.3); }
    .stat-top { display: flex; align-items: center; justify-content: space-between; }
    .stat-icon {
      width: 48px; height: 48px; border-radius: 14px;
      display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
    }
    .stat-value { font-size: 2rem; font-weight: 800; }
    .stat-label { font-size: 0.8rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }

    .ic-green  { background: rgba(34,197,94,0.15); color: var(--green); }
    .ic-blue   { background: rgba(59,130,246,0.15); color: var(--blue); }
    .ic-amber  { background: rgba(245,158,11,0.15); color: var(--amber); }
    .ic-purple { background: rgba(168,85,247,0.15); color: var(--purple); }
    .ic-red    { background: rgba(239,68,68,0.15); color: var(--red); }

    /* ── Two-col grid ── */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    @media (max-width: 1200px) { .two-col { grid-template-columns: 1fr; } }

    .panel {
      background: var(--card); border: 1px solid var(--border);
      border-radius: 16px; overflow: hidden;
    }
    .panel-header {
      padding: 20px 24px 16px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .panel-header h2 { font-size: 0.95rem; font-weight: 700; }
    .panel-header a  { font-size: 0.8rem; color: var(--green); text-decoration: none; font-weight: 600; }

    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 400px; }
    th { padding: 16px 24px 10px; font-size: 0.72rem; font-weight: 600; color: var(--muted); text-align: left; text-transform: uppercase; letter-spacing: 0.05em; background: rgba(255,255,255,0.02); }
    td { padding: 12px 24px; font-size: 0.85rem; border-top: 1px solid var(--border); color: var(--text); }
    tr:hover td { background: rgba(255,255,255,0.02); }

    .badge {
      display: inline-block; padding: 3px 9px; border-radius: 20px;
      font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    }
    .badge-active    { background: rgba(34,197,94,0.15);  color: var(--green); }
    .badge-suspended { background: rgba(239,68,68,0.15);  color: var(--red); }
    .badge-approved  { background: rgba(34,197,94,0.15);  color: var(--green); }
    .badge-pending   { background: rgba(245,158,11,0.15); color: var(--amber); }
    .badge-rejected  { background: rgba(239,68,68,0.15);  color: var(--red); }

    .usage-bar { height: 5px; background: var(--border); border-radius: 3px; margin-top: 4px; overflow: hidden; }
    .usage-fill { height: 100%; background: var(--green); border-radius: 3px; }
  </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🌿</div>
    <h2>InfoCrop Admin</h2>
    <p>Control Panel</p>
  </div>
  <nav>
    <div class="nav-label">Menu</div>
    <a href="index.php" class="nav-link active">
      <span class="icon">📊</span> Dashboard
    </a>
    <a href="users.php" class="nav-link">
      <span class="icon">👥</span> Manage Users
    </a>
    <a href="payments.php" class="nav-link">
      <span class="icon">💳</span> Payments
      <?php if ($pending_payments > 0): ?>
        <span class="badge-count"><?php echo $pending_payments; ?></span>
      <?php endif; ?>
    </a>
    <a href="referral_links.php" class="nav-link">
      <span class="icon">🔗</span> Referral Links
    </a>
    <a href="reports.php" class="nav-link">
      <span class="icon">📋</span> Farm Reports
    </a>
    <a href="settings.php" class="nav-link">
      <span class="icon">⚙️</span> System Settings
    </a>
    <a href="api_keys.php" class="nav-link">
      <span class="icon">🔑</span> API Keys
    </a>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php"><span>🚪</span> Logout</a>
  </div>
</aside>

<!-- ── Main ── -->
<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Dashboard Overview</h1>
    </div>
    <div class="admin-chip">👤 <?php echo htmlspecialchars($admin_name); ?></div>
  </div>

  <div class="content">

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-top">
          <div>
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?php echo $user_count; ?></div>
          </div>
          <div class="stat-icon ic-green">👥</div>
        </div>
        <div style="font-size:.78rem;color:var(--green);font-weight:600"><?php echo $active_users; ?> active members</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div>
            <div class="stat-label">Pending Payments</div>
            <div class="stat-value" style="color:var(--amber)"><?php echo $pending_payments; ?></div>
          </div>
          <div class="stat-icon ic-amber">⏳</div>
        </div>
        <div style="font-size:.78rem;color:var(--amber);font-weight:600">Awaiting your approval</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value" style="color:var(--purple)">₹<?php echo number_format($total_revenue); ?></div>
          </div>
          <div class="stat-icon ic-purple">💰</div>
        </div>
        <div style="font-size:.78rem;color:var(--purple);font-weight:600">Approved earnings</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div>
            <div class="stat-label">Farm Reports</div>
            <div class="stat-value" style="color:var(--blue)"><?php echo $total_reports; ?></div>
          </div>
          <div class="stat-icon ic-blue">📋</div>
        </div>
        <div style="font-size:.78rem;color:var(--blue);font-weight:600">AI plans generated</div>
      </div>
    </div>

    <!-- Tables -->
    <div class="two-col">

      <!-- Recent Users -->
      <div class="panel">
        <div class="panel-header">
          <h2>Recent Users</h2>
          <a href="users.php">View all →</a>
        </div>
        <div class="table-responsive">
          <table>
            <thead><tr><th>Name</th><th>Usage</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($recent_users as $u): ?>
              <tr>
                <td>
                  <div style="font-weight:600"><?php echo htmlspecialchars($u['name']); ?></div>
                  <div style="font-size:.75rem;color:var(--muted)"><?php echo htmlspecialchars($u['phone']); ?></div>
                </td>
                <td>
                  <div style="font-size:.8rem"><?php echo $u['usage_count']; ?> / <?php echo $u['usage_limit']; ?></div>
                  <div class="usage-bar">
                    <div class="usage-fill" style="width:<?php echo min(100, $u['usage_limit']>0 ? ($u['usage_count']/$u['usage_limit']*100) : 0); ?>%"></div>
                  </div>
                </td>
                <td><span class="badge badge-<?php echo $u['status']; ?>"><?php echo strtoupper($u['status']); ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recent_users)): ?>
              <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:32px;">No users yet in the system</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent Payments -->
      <div class="panel">
        <div class="panel-header">
          <h2>Recent Payments</h2>
          <a href="payments.php">View all →</a>
        </div>
        <div class="table-responsive">
          <table>
            <thead><tr><th>User</th><th>Amount</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($recent_payments as $p): ?>
              <tr>
                <td style="font-weight:600"><?php echo htmlspecialchars($p['name']); ?></td>
                <td style="font-weight:700">₹<?php echo number_format($p['amount'], 2); ?></td>
                <td><span class="badge badge-<?php echo $p['status']; ?>"><?php echo strtoupper($p['status']); ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recent_payments)): ?>
              <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:32px;">No payment activity yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <!-- Recent Reports -->
    <?php if (!empty($recent_reports)): ?>
    <div style="margin-top:24px">
      <div class="panel">
        <div class="panel-header">
          <h2>📋 Latest Farm Reports</h2>
          <a href="reports.php">View all →</a>
        </div>
        <div class="table-responsive">
          <table>
            <thead><tr><th>Farmer</th><th>Crop</th><th>Location</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($recent_reports as $rr): ?>
              <tr>
                <td style="font-weight:600"><?= htmlspecialchars($rr['uname']) ?></td>
                <td><?= htmlspecialchars($rr['crop'] ?: '—') ?></td>
                <td style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($rr['location'] ?: '—') ?></td>
                <td style="color:var(--muted);font-size:.8rem"><?= date('d M Y', strtotime($rr['created_at'])) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

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

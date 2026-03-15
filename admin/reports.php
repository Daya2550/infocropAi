<?php
// admin/reports.php — All farm reports across all users
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php"); exit;
}

$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
$admin_name = $_SESSION['admin_user'] ?? 'Admin';

// Search / filter
$search  = trim($_GET['q']  ?? '');
$user_id_filter = (int)($_GET['user_id'] ?? 0);

$where  = [];
$params = [];

if ($search) {
    $where[]  = "(u.name LIKE ? OR u.phone LIKE ? OR r.crop LIKE ? OR r.location LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($user_id_filter) {
    $where[]  = "r.user_id = ?";
    $params[] = $user_id_filter;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$reports = $pdo->prepare("
    SELECT r.*, u.name AS user_name, u.phone AS user_phone
    FROM farm_reports r
    JOIN users u ON r.user_id = u.id
    $where_sql
    ORDER BY r.created_at DESC
");
$reports->execute($params);
$reports = $reports->fetchAll();

$total_reports = $pdo->query("SELECT COUNT(*) FROM farm_reports")->fetchColumn();

// Fetch user for filter label
$filter_user_name = '';
if ($user_id_filter) {
    $fu = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $fu->execute([$user_id_filter]);
    $fu = $fu->fetch();
    $filter_user_name = $fu['name'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Farm Reports | InfoCrop Admin</title>
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

    /* ── Reports Specific ── */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }
    .stat-card {
      background: var(--card); border: 1px solid var(--border);
      padding: 24px; border-radius: 20px;
      display: flex; align-items: center; gap: 20px;
      transition: transform 0.2s, border-color 0.2s;
    }
    .stat-card:hover { transform: translateY(-3px); border-color: rgba(34,197,94,0.4); }
    .stat-icon {
      width: 54px; height: 54px; border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem; flex-shrink: 0;
    }
    .stat-info h3 { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
    .stat-info p { font-size: 1.5rem; font-weight: 800; color: var(--text); }

    .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .search-box {
      display: flex; align-items: center; gap: 10px; background: var(--card);
      border: 1px solid var(--border); border-radius: 12px; padding: 10px 16px; min-width: 300px;
      transition: border-color 0.2s;
    }
    .search-box:focus-within { border-color: var(--green); }
    .search-box input { background: none; border: none; outline: none; color: var(--text); font-size: .95rem; font-family: inherit; flex: 1; }
    .search-box input::placeholder { color: var(--muted); }

    .filter-badge {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.2);
      color: var(--blue); padding: 6px 14px; border-radius: 20px;
      font-size: 0.8rem; font-weight: 600;
    }
    .filter-badge a { color: var(--muted); text-decoration: none; font-size: 1.1rem; line-height: 1; margin-left: 2px; }
    .filter-badge a:hover { color: var(--red); }

    .table-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 1000px; }
    th { padding: 16px 20px; font-size: .72rem; font-weight: 600; color: var(--muted); text-align: left; text-transform: uppercase; letter-spacing: .05em; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border); }
    td { padding: 16px 20px; font-size: .875rem; border-bottom: 1px solid rgba(51,65,85,.5); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(255,255,255,0.015); }

    .crop-badge { background: rgba(34,197,94,0.12); color: var(--green); padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
    .land-tag { color: var(--amber); font-weight: 700; font-family: monospace; }

    .btn-action {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;
      text-decoration: none; transition: all 0.2s; border: 1px solid transparent;
    }
    .btn-user { background: rgba(59,130,246,0.1); color: var(--blue); border-color: rgba(59,130,246,0.15); }
    .btn-user:hover { background: rgba(59,130,246,0.2); }
    .btn-pdf { background: rgba(34,197,94,0.12); color: var(--green); border-color: rgba(34,197,94,0.15); }
    .btn-pdf:hover { background: rgba(34,197,94,0.2); }

    .no-pdf { color: var(--muted); font-size: 0.75rem; font-style: italic; opacity: 0.7; }
    .btn-del { background: rgba(239,68,68,0.12); color: var(--red); border-color: rgba(239,68,68,0.2); }
    .btn-del:hover { background: rgba(239,68,68,0.25); }
    .empty-row { padding: 100px 40px; text-align: center; color: var(--muted); font-size: 1rem; }
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
    <a href="reports.php"  class="nav-link active"><span class="icon">📋</span> Farm Reports</a>
    <a href="settings.php" class="nav-link"><span class="icon">⚙️</span> System Settings</a>
    <a href="api_keys.php" class="nav-link"><span class="icon">🔑</span> API Keys</a>
  </nav>
  <div class="sidebar-footer"><a href="logout.php"><span>🚪</span> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <h1>Farm Reports</h1>
    </div>
    <div class="admin-chip">👤 <?php echo htmlspecialchars($admin_name); ?></div>
  </div>

  <div class="content">

    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(34,197,94,0.1); color:var(--green);">📋</div>
        <div class="stat-info">
          <h3>Total Reports</h3>
          <p><?php echo number_format($total_reports); ?></p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(59,130,246,0.1); color:var(--blue);">🔍</div>
        <div class="stat-info">
          <h3>Showing</h3>
          <p><?php echo count($reports); ?></p>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <form method="GET" class="search-box">
        <?php if ($user_id_filter): ?><input type="hidden" name="user_id" value="<?php echo $user_id_filter; ?>"><?php endif; ?>
        <span>🔍</span>
        <input type="text" name="q" placeholder="Search by user, crop, or location…" value="<?php echo htmlspecialchars($search); ?>">
      </form>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <?php if ($user_id_filter && $filter_user_name): ?>
          <span class="filter-badge">
            👤 User: <?php echo htmlspecialchars($filter_user_name); ?>
            <a href="reports.php" title="Clear filter">×</a>
          </span>
        <?php endif; ?>
        <span style="color:var(--muted);font-size:.85rem;font-weight:500"><?php echo count($reports); ?> results found</span>
      </div>
    </div>

    <div class="table-card">
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th style="width: 60px;">ID</th>
              <th>User Details</th>
              <th>Crop Type</th>
              <th>Location & Season</th>
              <th>Area</th>
              <th>Created On</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($reports)): ?>
            <tr><td colspan="7" class="empty-row">📋 No reports found. Try a different search!</td></tr>
            <?php endif; ?>
            <?php foreach ($reports as $r): ?>
            <tr>
              <td style="color:var(--muted);font-family:monospace;font-weight:600">#<?php echo $r['id']; ?></td>
              <td>
                <div style="font-weight:600;color:var(--text)"><?php echo htmlspecialchars($r['user_name']); ?></div>
                <div style="font-size:.75rem;color:var(--muted)"><?php echo htmlspecialchars($r['user_phone']); ?></div>
              </td>
              <td><span class="crop-badge"><?php echo htmlspecialchars($r['crop'] ?: 'N/A'); ?></span></td>
              <td>
                <div style="font-weight:500;color:var(--text)"><?php echo htmlspecialchars($r['location'] ?: 'Unknown'); ?></div>
                <div style="font-size:.72rem;color:var(--muted);margin-top:2px"><?php echo htmlspecialchars($r['season'] ?: '—'); ?></div>
              </td>
              <td><span class="land-tag"><?php echo htmlspecialchars($r['land_area'] ?: '0'); ?></span> <span style="font-size:0.75rem;color:var(--muted)">Acres</span></td>
              <td>
                <div style="font-weight:500;color:var(--text)"><?php echo date('d M Y', strtotime($r['created_at'])); ?></div>
                <div style="font-size:.72rem;color:var(--muted);margin-top:2px"><?php echo date('h:i A', strtotime($r['created_at'])); ?></div>
              </td>
              <td>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <a href="reports.php?user_id=<?php echo $r['user_id']; ?>" class="btn-action btn-user" title="Filter by this user">👤 Profile</a>
                  <?php if ($r['pdf_filename'] && file_exists(dirname(__DIR__) . '/uploads/reports/' . $r['pdf_filename'])): ?>
                    <a href="../serve_report.php?id=<?php echo $r['id']; ?>" class="btn-action btn-pdf" target="_blank">📄 Download</a>
                  <?php else: ?>
                    <span class="no-pdf">PDF Missing</span>
                  <?php endif; ?>
                  <button class="btn-action btn-del" onclick="deleteReport(<?php echo $r['id']; ?>, '<?php echo addslashes(htmlspecialchars($r['crop'] ?? 'this report', ENT_QUOTES)); ?>', this)">🗑 Delete</button>
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

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('active');
}
function deleteReport(reportId, cropName, btn) {
  Swal.fire({
    title: '⚠️ Delete Report?',
    html: `<p>Permanently delete the plan for <strong>${cropName}</strong>?</p><br><p style="color:#ef4444;font-size:0.85rem;">⚠️ This removes all linked smart checks, tasks, and expenses. <strong>Cannot be undone.</strong></p>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    cancelButtonColor: '#475569',
    confirmButtonText: '🗑 Delete It',
    cancelButtonText: 'Cancel',
    background: '#1e293b',
    color: '#e2e8f0'
  }).then((result) => {
    if (result.isConfirmed) {
      const formData = new FormData();
      formData.append('report_id', reportId);
      fetch('../ajax_delete_report.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            const row = btn.closest('tr');
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(20px)';
            setTimeout(() => row.remove(), 320);
            Swal.fire({ title: 'Deleted!', icon: 'success', timer: 1800, showConfirmButton: false, background: '#1e293b', color: '#e2e8f0' });
          } else {
            Swal.fire('Error', data.error || 'Failed to delete.', 'error', { background: '#1e293b', color: '#e2e8f0' });
          }
        }).catch(() => Swal.fire('Error', 'Connection failed.', 'error'));
    }
  });
}
</script>
</body>
</html>

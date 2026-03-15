<?php
// admin/payments.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php"); exit;
}

$pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
$message = ''; $msg_type = 'success';

if (isset($_POST['action'])) {
    $payment_id = (int)$_POST['payment_id'];
    $action = $_POST['action'];
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id=?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();

    if ($payment) {
        if ($action === 'approve') {
            $pdo->prepare("UPDATE payments SET status='approved' WHERE id=?")->execute([$payment_id]);
            $pdo->prepare("UPDATE users SET usage_limit = usage_limit + ? WHERE id=?")->execute([$payment['new_limit'], $payment['user_id']]);
            $message = "✅ Payment approved — user limit increased by {$payment['new_limit']}.";
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE payments SET status='rejected' WHERE id=?")->execute([$payment_id]);
            $message = "Payment rejected."; $msg_type = 'warning';
        }
    }
}

$tab = $_GET['tab'] ?? 'pending';
$pending  = $pdo->query("SELECT p.*, u.name as user_name, u.phone FROM payments p JOIN users u ON p.user_id=u.id WHERE p.status='pending'  ORDER BY p.created_at DESC")->fetchAll();
$approved = $pdo->query("SELECT p.*, u.name as user_name FROM payments p JOIN users u ON p.user_id=u.id WHERE p.status='approved' ORDER BY p.created_at DESC LIMIT 20")->fetchAll();
$rejected = $pdo->query("SELECT p.*, u.name as user_name FROM payments p JOIN users u ON p.user_id=u.id WHERE p.status='rejected' ORDER BY p.created_at DESC LIMIT 20")->fetchAll();
$admin_name = $_SESSION['admin_user'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payments | InfoCrop Admin</title>
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

    /* ── Payments Specific ── */
    .alert { padding: 12px 18px; border-radius: 12px; font-size: .875rem; font-weight: 500; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); color: var(--green); }
    .alert-warning { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.2); color: var(--amber); }

    .tabs { display: flex; gap: 4px; margin-bottom: 24px; background: var(--card); border: 1px solid var(--border); padding: 5px; border-radius: 14px; width: fit-content; }
    .tab-btn { padding: 10px 22px; border-radius: 10px; border: none; background: none; color: var(--muted); font-size: .875rem; font-weight: 600; cursor: pointer; transition: all 0.2s; text-decoration: none; display: flex; align-items: center; gap: 8px; }
    .tab-btn.active { background: var(--border); color: var(--text); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    .tab-btn:hover:not(.active) { color: var(--text); background: rgba(255,255,255,0.03); }
    .tab-count { background: var(--amber); color: #000; font-size: .7rem; font-weight: 700; padding: 1px 7px; border-radius: 20px; }

    .table-card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { width: 100%; border-collapse: collapse; min-width: 800px; }
    th { padding: 16px 20px; font-size: .72rem; font-weight: 600; color: var(--muted); text-align: left; text-transform: uppercase; letter-spacing: .05em; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border); }
    td { padding: 16px 20px; font-size: .875rem; border-bottom: 1px solid rgba(51,65,85,.5); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: rgba(255,255,255,0.015); }

    .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: .65rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .badge-pending { background: rgba(245,158,11,0.15); color: var(--amber); }
    .badge-approved { background: rgba(34,197,94,0.15); color: var(--green); }
    .badge-rejected { background: rgba(239,68,68,0.15); color: var(--red); }

    .btn { padding: 8px 16px; border-radius: 8px; font-size: .8rem; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
    .btn:hover { transform: translateY(-1px); filter: brightness(1.1); }
    .btn-approve { background: var(--green); color: #fff; }
    .btn-reject { background: rgba(239,68,68,0.1); color: var(--red); }

    .txn-code { font-family: 'JetBrains Mono', monospace; font-size: .75rem; background: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 6px; color: var(--blue); border: 1px solid rgba(59,130,246,0.2); }
    .ss-link { display: inline-flex; align-items: center; gap: 6px; color: var(--green); text-decoration: none; font-size: .8rem; font-weight: 600; background: rgba(34,197,94,0.1); padding: 5px 12px; border-radius: 8px; transition: all 0.2s; }
    .ss-link:hover { background: rgba(34,197,94,0.2); }

    .empty-row { padding: 80px 40px; text-align: center; color: var(--muted); font-size: .95rem; }
    .amount-pill { font-weight: 700; color: var(--green); font-size: 1rem; }
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
    <a href="payments.php" class="nav-link active">
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
      <h1>Payment Management</h1>
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

    <div class="tabs">
      <a href="payments.php?tab=pending" class="tab-btn <?php echo $tab==='pending' ? 'active' : ''; ?>">
        ⏳ Pending <?php if (count($pending) > 0): ?><span class="tab-count"><?php echo count($pending); ?></span><?php endif; ?>
      </a>
      <a href="payments.php?tab=approved" class="tab-btn <?php echo $tab==='approved' ? 'active' : ''; ?>">✅ Approved</a>
      <a href="payments.php?tab=rejected" class="tab-btn <?php echo $tab==='rejected' ? 'active' : ''; ?>">❌ Rejected</a>
    </div>

    <!-- Tab Panels -->
    <?php if ($tab === 'pending'): ?>
    <div class="table-card">
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>User Information</th>
              <th>Amount</th>
              <th>Transaction ID</th>
              <th>Document</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pending)): ?>
            <tr><td colspan="6" class="empty-row">🎉 No pending requests. You're all caught up!</td></tr>
            <?php endif; ?>
            <?php foreach ($pending as $p): ?>
            <tr>
              <td>
                <div style="font-weight:600;color:var(--text)"><?php echo htmlspecialchars($p['user_name']); ?></div>
                <div style="font-size:.75rem;color:var(--muted)"><?php echo htmlspecialchars($p['phone']); ?></div>
              </td>
              <td><span class="amount-pill">₹<?php echo number_format($p['amount'], 2); ?></span></td>
              <td><span class="txn-code"><?php echo htmlspecialchars($p['transaction_id'] ?: 'N/A'); ?></span></td>
              <td>
                <?php if ($p['screenshot']): ?>
                  <a href="../<?php echo htmlspecialchars($p['screenshot']); ?>" target="_blank" class="ss-link"><span>📎</span> View Proof</a>
                <?php else: ?>
                  <span style="color:var(--muted);font-size:.8rem">No attachment</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted);font-size:.85rem"><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></td>
              <td>
                <form method="POST" style="display:flex;gap:8px">
                  <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                  <button type="submit" name="action" value="approve" class="btn btn-approve">Approve</button>
                  <button type="submit" name="action" value="reject" class="btn btn-reject">Reject</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($tab === 'approved'): ?>
    <div class="table-card">
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>User</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Processed On</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($approved)): ?>
            <tr><td colspan="4" class="empty-row">No approved payments found.</td></tr>
            <?php endif; ?>
            <?php foreach ($approved as $p): ?>
            <tr>
              <td style="font-weight:600;color:var(--text)"><?php echo htmlspecialchars($p['user_name']); ?></td>
              <td><span class="amount-pill">₹<?php echo number_format($p['amount'], 2); ?></span></td>
              <td><span class="badge badge-approved">APPROVED</span></td>
              <td style="color:var(--muted);font-size:.85rem"><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($tab === 'rejected'): ?>
    <div class="table-card">
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>User</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Processed On</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rejected)): ?>
            <tr><td colspan="4" class="empty-row">No rejected payments found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rejected as $p): ?>
            <tr>
              <td style="font-weight:600;color:var(--text)"><?php echo htmlspecialchars($p['user_name']); ?></td>
              <td><span style="font-weight:700;color:var(--muted)">₹<?php echo number_format($p['amount'], 2); ?></span></td>
              <td><span class="badge badge-rejected">REJECTED</span></td>
              <td style="color:var(--muted);font-size:.85rem"><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
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

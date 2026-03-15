<?php
// report_history.php — User's saved farm plan history
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch user info
$user = $pdo->prepare("SELECT name, usage_limit, usage_count FROM users WHERE id = ?");
$user->execute([$user_id]);
$user = $user->fetch();
$credits_remaining = round((float)($user['usage_limit'] ?? 1) - (float)($user['usage_count'] ?? 0), 2);

// Fetch reports
$stmt = $pdo->prepare("SELECT * FROM farm_reports WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$reports = $stmt->fetchAll();

// Fetch system settings
$sys = [];
foreach ($pdo->query("SELECT * FROM settings") as $s) $sys[$s['setting_key']] = $s['setting_value'];
$site_name = $sys['site_name'] ?? 'InfoCrop AI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Farm Reports — <?= htmlspecialchars($site_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/global.css">
  <style>
    :root {
      --green-dark  : #1b5e20;
      --green-mid   : #2e7d32;
      --green-light : #43a047;
      --green-pale  : #e8f5e9;
      --green-border: #c8e6c9;
      --gray-100    : #f8faf8;
      --white       : #ffffff;
      --shadow-md   : 0 6px 28px rgba(0,0,0,.10);
      --radius      : 16px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f7f0, #e8f4e8); min-height: 100vh; color: #444; }

    /* Nav */
    .nav { background: linear-gradient(135deg, var(--green-dark), #2e7d32); padding: 0 24px; display: flex; align-items: center; justify-content: space-between; height: 58px; position: sticky; top: 0; z-index: 999; box-shadow: 0 3px 20px rgba(0,0,0,.3); }
    .nav .brand { color: #fff; text-decoration: none; font-size: 1.15rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .nav .nav-btn { color: #fff; background: rgba(255,255,255,.18); border: 1.5px solid rgba(255,255,255,.3); text-decoration: none; font-size: .82rem; font-weight: 600; padding: 6px 16px; border-radius: 20px; transition: background .2s; }
    .nav .nav-btn:hover { background: rgba(255,255,255,.28); }

    /* Wrapper */
    .wrap { max-width: 1000px; margin: 0 auto; padding: 36px 20px 60px; }

    /* Header */
    .page-header { text-align: center; margin-bottom: 36px; }
    .page-header h1 { font-size: 2rem; font-weight: 800; color: var(--green-dark); letter-spacing: -.4px; }
    .page-header p  { color: #666; margin-top: 8px; font-size: .95rem; }
    .page-header .badge { display: inline-flex; align-items: center; gap: 6px; background: var(--green-pale); color: var(--green-dark); font-size: .76rem; font-weight: 700; padding: 4px 14px; border-radius: 20px; margin-top: 10px; border: 1px solid var(--green-border); }

    /* Stats bar */
    .stats-bar { display: flex; align-items: center; justify-content: space-between; background: var(--white); border: 1.5px solid var(--green-border); border-radius: var(--radius); padding: 16px 24px; margin-bottom: 28px; box-shadow: var(--shadow-md); flex-wrap: wrap; gap: 12px; }
    .stat-item { text-align: center; }
    .stat-val { font-size: 1.5rem; font-weight: 800; color: var(--green-dark); }
    .stat-lbl { font-size: .72rem; color: #888; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }

    /* Report cards grid */
    .reports-grid { display: grid; gap: 20px; }

    .report-card {
      background: var(--white);
      border: 1.5px solid var(--green-border);
      border-radius: var(--radius);
      box-shadow: var(--shadow-md);
      padding: 24px 28px;
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 16px;
      align-items: center;
      transition: transform .2s, box-shadow .2s;
    }
    .report-card:hover { transform: translateY(-3px); box-shadow: 0 12px 36px rgba(0,0,0,.12); }

    .report-num {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 10px;
      background: linear-gradient(135deg, var(--green-light), var(--green-mid));
      color: #fff; font-weight: 800; font-size: .9rem; flex-shrink: 0;
    }
    .report-title-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .report-title { font-size: 1.05rem; font-weight: 700; color: var(--green-dark); }
    .report-meta { display: flex; flex-wrap: wrap; gap: 10px; }
    .meta-pill {
      display: inline-flex; align-items: center; gap: 5px;
      background: var(--green-pale); color: var(--green-dark);
      font-size: .76rem; font-weight: 600;
      padding: 4px 12px; border-radius: 20px;
      border: 1px solid var(--green-border);
    }
    .report-date { font-size: .75rem; color: #888; margin-top: 8px; }

    .btn-download {
      display: inline-flex; align-items: center; gap: 8px;
      background: linear-gradient(135deg, var(--green-mid), var(--green-dark));
      color: #fff; font-size: .82rem; font-weight: 700;
      padding: 9px 16px; border-radius: 25px; text-decoration: none;
      box-shadow: 0 4px 14px rgba(46,125,50,.3);
      transition: all .2s; white-space: nowrap; flex-shrink: 0;
    }
    .btn-download:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(46,125,50,.4); }
    .btn-smart {
      display: inline-flex; align-items: center; gap: 6px;
      background: linear-gradient(135deg, #e65100, #bf360c);
      color: #fff; font-size: .78rem; font-weight: 700;
      padding: 9px 14px; border-radius: 25px; text-decoration: none;
      box-shadow: 0 4px 12px rgba(230,81,0,.3);
      transition: all .2s; white-space: nowrap; flex-shrink: 0;
    }
    .btn-smart:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(230,81,0,.4); }

    /* Empty state */
    .empty-state {
      background: var(--white); border: 1.5px dashed var(--green-border); border-radius: var(--radius);
      text-align: center; padding: 60px 24px;
    }
    .empty-state .icon { font-size: 4rem; margin-bottom: 16px; }
    .empty-state h2 { font-size: 1.4rem; font-weight: 700; color: var(--green-dark); margin-bottom: 10px; }
    .empty-state p { color: #666; font-size: .95rem; line-height: 1.6; margin-bottom: 24px; }
    .btn-start {
      display: inline-flex; align-items: center; gap: 8px;
      background: linear-gradient(135deg, var(--green-mid), var(--green-dark));
      color: #fff; font-size: .95rem; font-weight: 700;
      padding: 13px 30px; border-radius: 25px; text-decoration: none;
      box-shadow: 0 4px 14px rgba(46,125,50,.3); transition: all .2s;
    }
    .btn-start:hover { transform: translateY(-2px); }

    .btn-delete {
      display: inline-flex; align-items: center; gap: 6px;
      background: linear-gradient(135deg, #c62828, #b71c1c);
      color: #fff; font-size: .78rem; font-weight: 700;
      padding: 8px 14px; border-radius: 25px; border: none;
      cursor: pointer; box-shadow: 0 4px 12px rgba(198,40,40,.3);
      transition: all .2s; white-space: nowrap; flex-shrink: 0;
    }
    .btn-delete:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(198,40,40,.45); }

    @media (max-width: 600px) {
      .report-card { grid-template-columns: 1fr; }
      .btn-download { width: 100%; justify-content: center; }
    }
  </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="wrap">

  <div class="page-header">
    <h1>📋 My Farm Reports</h1>
    <p>All your AI-generated crop plans — saved and ready to download</p>
    <div class="badge">👤 <?= htmlspecialchars($user['name'] ?? 'Farmer') ?></div>
  </div>

  <!-- Stats -->
  <div class="stats-bar">
    <div class="stat-item">
      <div class="stat-val"><?= number_format($credits_remaining, 2) ?></div>
      <div class="stat-lbl">Credits Remaining</div>
    </div>
    <div class="stat-item">
      <div class="stat-val"><?= count($reports) ?></div>
      <div class="stat-lbl">Total Reports</div>
    </div>
    <?php if (!empty($reports)): ?>
    <div class="stat-item" style="text-align:right">
      <div class="stat-val" style="font-size:1rem;color:#555"><?= htmlspecialchars($reports[0]['crop'] ?? '—') ?></div>
      <div class="stat-lbl">Latest Crop</div>
    </div>
    <div class="stat-item" style="text-align:right">
      <div class="stat-val" style="font-size:1rem;color:#555"><?= date('d M Y', strtotime($reports[0]['created_at'])) ?></div>
      <div class="stat-lbl">Last Plan Date</div>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="smart_planner.php" class="btn-start" style="font-size:.82rem;padding:9px 18px;background:linear-gradient(135deg,#e65100,#bf360c)">⚡ Smart Check <small style="opacity:.8">(0.25cr)</small></a>
      <a href="index.php" class="btn-start" style="font-size:.82rem;padding:9px 18px">+ New Plan</a>
    </div>
  </div>

  <!-- Reports list -->
  <?php if (empty($reports)): ?>
  <div class="empty-state">
    <div class="icon">🌾</div>
    <h2>No reports yet</h2>
    <p>Complete your first 10-stage Farm Planner AI wizard and<br>your report will be saved here automatically.</p>
    <a href="index.php" class="btn-start">🌱 Start My First Plan</a>
  </div>

  <?php else: ?>
  <div class="reports-grid">
    <?php foreach ($reports as $i => $r): ?>
    <div class="report-card">
      <div>
        <div class="report-title-row">
          <span class="report-num"><?= count($reports) - $i ?></span>
          <span class="report-title">
            <?= htmlspecialchars($r['farmer_name'] ?? 'Farm Plan') ?> —
            <?= htmlspecialchars($r['crop'] ?? 'Crop Plan') ?>
          </span>
        </div>
        <div class="report-meta">
          <?php if ($r['location']): ?>
            <span class="meta-pill">📍 <?= htmlspecialchars($r['location']) ?></span>
          <?php endif; ?>
          <?php if ($r['season']): ?>
            <span class="meta-pill">🌤 <?= htmlspecialchars($r['season']) ?></span>
          <?php endif; ?>
          <?php if ($r['land_area']): ?>
            <span class="meta-pill">🌾 <?= htmlspecialchars($r['land_area']) ?> Acres</span>
          <?php endif; ?>
        </div>
        <div class="report-date">📅 Generated: <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></div>
      </div>
      <?php if ($r['pdf_filename'] && file_exists(__DIR__ . '/uploads/reports/' . $r['pdf_filename'])): ?>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
          <a href="serve_report.php?id=<?= $r['id'] ?>" class="btn-download">
            ⬇ Download PDF
          </a>
          <a href="smart_planner.php?step=1&prefill_report=<?= $r['id'] ?>" class="btn-smart">
            ⚡ Smart Update
          </a>
          <button class="btn-delete" onclick="deleteReport(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars_decode($r['crop'] ?? 'this report')) ?>', this)">
            🗑 Delete
          </button>
        </div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
          <span style="font-size:.78rem;color:#aaa;font-style:italic">PDF unavailable</span>
          <a href="smart_planner.php?step=1&prefill_report=<?= $r['id'] ?>" class="btn-smart">
            ⚡ Smart Update
          </a>
          <button class="btn-delete" onclick="deleteReport(<?= $r['id'] ?>, '<?= addslashes(htmlspecialchars_decode($r['crop'] ?? 'this report')) ?>', this)">
            🗑 Delete
          </button>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  </div>
</div>
</main>
<?php include 'partials/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function deleteReport(reportId, cropName, btn) {
  Swal.fire({
    title: '⚠️ Delete This Report?',
    html: `<p>You are about to permanently delete the plan for <strong>${cropName}</strong>.</p><br><p style="color:#c62828;font-size:0.88rem;">⚠️ This will also delete all related smart checks, tasks, and expenses. <strong>This cannot be undone.</strong></p>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#c62828',
    cancelButtonColor: '#6c757d',
    confirmButtonText: '🗑 Yes, Delete It',
    cancelButtonText: 'Cancel'
  }).then((result) => {
    if (result.isConfirmed) {
      const formData = new FormData();
      formData.append('report_id', reportId);
      fetch('ajax_delete_report.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            const card = btn.closest('.report-card');
            card.style.transition = 'all 0.4s ease';
            card.style.opacity = '0';
            card.style.transform = 'scale(0.95) translateY(-10px)';
            setTimeout(() => card.remove(), 420);
            Swal.fire({ title: 'Deleted!', text: 'The report has been removed.', icon: 'success', timer: 2000, showConfirmButton: false });
          } else {
            Swal.fire('Error', data.error || 'Failed to delete.', 'error');
          }
        }).catch(() => Swal.fire('Error', 'Connection failed.', 'error'));
    }
  });
}
</script>
</body>
</html>

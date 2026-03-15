<?php
// admin/login.php
session_start();
require_once '../db.php';

if (isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_user'] = $admin['username'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid username or password. Please try again.";
        }
    }
}

// Fetch Site Name
$site_name = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'")->fetchColumn() ?: 'InfoCrop AI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login | <?php echo htmlspecialchars($site_name); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --green:   #22c55e;
      --green-d: #16a34a;
      --dark:    #0f172a;
      --card:    #1e293b;
      --border:  #334155;
      --text:    #e2e8f0;
      --muted:   #94a3b8;
      --error:   #f87171;
    }

    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--dark);
      min-height: 100vh;
      display: flex;
      align-items: stretch;
    }

    /* ── Left branding panel ── */
    .brand-panel {
      flex: 1;
      background: linear-gradient(145deg, #052e16 0%, #166534 50%, #052e16 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 50px;
      position: relative;
      overflow: hidden;
    }
    .brand-panel::before {
      content: '';
      position: absolute;
      width: 400px; height: 400px;
      background: radial-gradient(circle, rgba(34,197,94,0.15) 0%, transparent 70%);
      top: -100px; left: -100px;
    }
    .brand-panel::after {
      content: '';
      position: absolute;
      width: 300px; height: 300px;
      background: radial-gradient(circle, rgba(34,197,94,0.1) 0%, transparent 70%);
      bottom: -50px; right: -50px;
    }
    .brand-logo { font-size: 5rem; margin-bottom: 20px; position: relative; z-index: 1; }
    .brand-panel h1 {
      color: white;
      font-size: 2.4rem;
      font-weight: 900;
      letter-spacing: -0.03em;
      position: relative; z-index: 1;
    }
    .brand-panel p {
      color: rgba(255,255,255,0.65);
      font-size: 1rem;
      margin-top: 12px;
      line-height: 1.7;
      text-align: center;
      max-width: 320px;
      position: relative; z-index: 1;
    }
    .brand-features {
      margin-top: 40px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      position: relative; z-index: 1;
    }
    .brand-feature {
      display: flex;
      align-items: center;
      gap: 12px;
      color: rgba(255,255,255,0.8);
      font-size: 0.9rem;
    }
    .brand-feature span:first-child {
      width: 32px; height: 32px;
      background: rgba(34,197,94,0.2);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
      flex-shrink: 0;
    }

    /* ── Right login panel ── */
    .login-panel {
      width: 480px;
      flex-shrink: 0;
      background: var(--card);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 60px 50px;
      border-left: 1px solid var(--border);
    }
    .login-box { width: 100%; }

    .login-title {
      color: var(--text);
      font-size: 1.75rem;
      font-weight: 800;
      margin-bottom: 6px;
    }
    .login-sub {
      color: var(--muted);
      font-size: 0.9rem;
      margin-bottom: 36px;
    }

    .error-box {
      background: rgba(248,113,113,0.1);
      border: 1px solid rgba(248,113,113,0.3);
      color: var(--error);
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 0.875rem;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .form-group { margin-bottom: 22px; }
    .form-group label {
      display: block;
      color: var(--muted);
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 8px;
    }
    .input-wrap { position: relative; }
    .input-wrap .icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 1rem;
      opacity: 0.5;
      pointer-events: none;
    }
    .input-wrap input {
      width: 100%;
      background: var(--dark);
      border: 1.5px solid var(--border);
      border-radius: 12px;
      color: var(--text);
      font-family: 'Inter', sans-serif;
      font-size: 0.95rem;
      padding: 13px 16px 13px 42px;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .input-wrap input:focus {
      border-color: var(--green);
      box-shadow: 0 0 0 3px rgba(34,197,94,0.15);
    }
    .toggle-pw {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--muted);
      cursor: pointer;
      font-size: 1rem;
      padding: 4px;
      line-height: 1;
    }
    .toggle-pw:hover { color: var(--green); }

    .btn-login {
      width: 100%;
      background: linear-gradient(135deg, var(--green) 0%, var(--green-d) 100%);
      color: white;
      border: none;
      border-radius: 12px;
      padding: 14px;
      font-family: 'Inter', sans-serif;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.2s, transform 0.2s;
      margin-top: 8px;
    }
    .btn-login:hover { opacity: 0.92; transform: translateY(-1px); }
    .btn-login:active { transform: translateY(0); }

    .hint {
      margin-top: 28px;
      padding: 14px;
      background: rgba(34,197,94,0.07);
      border: 1px solid rgba(34,197,94,0.15);
      border-radius: 10px;
      color: var(--muted);
      font-size: 0.8rem;
      line-height: 1.6;
    }
    .hint strong { color: var(--green); }

    @media (max-width: 820px) {
      .brand-panel { display: none; }
      .login-panel { width: 100%; }
    }
  </style>
</head>
<body>

  <!-- Left: Branding -->
  <div class="brand-panel">
    <div class="brand-logo">🌿</div>
    <h1>InfoCrop Admin</h1>
    <p>Manage users, review payments, and configure system settings from one powerful dashboard.</p>
    <div class="brand-features">
      <div class="brand-feature">
        <span>📊</span>
        <span>Real-time usage analytics</span>
      </div>
      <div class="brand-feature">
        <span>👥</span>
        <span>User account management</span>
      </div>
      <div class="brand-feature">
        <span>💳</span>
        <span>Payment approval workflow</span>
      </div>
      <div class="brand-feature">
        <span>⚙️</span>
        <span>API key & system settings</span>
      </div>
    </div>
  </div>

  <!-- Right: Login form -->
  <div class="login-panel">
    <div class="login-box">
      <div class="login-title">Welcome back</div>
      <div class="login-sub">Sign in to your admin account</div>

      <?php if ($error): ?>
      <div class="error-box">⚠️ <?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">
        <div class="form-group">
          <label>Username</label>
          <div class="input-wrap">
            <span class="icon">👤</span>
            <input type="text" name="username"
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                   placeholder="Enter admin username" required autofocus>
          </div>
        </div>

        <div class="form-group">
          <label>Password</label>
          <div class="input-wrap">
            <span class="icon">🔒</span>
            <input type="password" name="password" id="pwField"
                   placeholder="Enter your password" required>
            <button type="button" class="toggle-pw" onclick="togglePw()" title="Show/hide password">👁️</button>
          </div>
        </div>

        <button type="submit" class="btn-login">🔐 Sign In to Dashboard</button>
      </form>
    </div>
  </div>

<script>
function togglePw() {
  const f = document.getElementById('pwField');
  f.type = f.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>

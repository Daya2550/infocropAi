<?php
// admin/reset_admin.php
// ONE-TIME USE: Resets or creates admin accounts with correct password hashes
// DELETE THIS FILE AFTER RUNNING!

require_once '../db.php';

$accounts = [
    ['username' => 'admin', 'password' => 'admin123'],
    ['username' => 'dj',    'password' => '12345678'],
];

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<title>Admin Reset</title>
<link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap' rel='stylesheet'>
<style>
  body { font-family: Inter, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .card { background: #1e293b; padding: 40px; border-radius: 16px; max-width: 480px; width: 100%; }
  h2 { color: #22c55e; margin-bottom: 20px; }
  .row { background: #0f172a; border-radius: 8px; padding: 14px 18px; margin-bottom: 12px; }
  .ok { color: #22c55e; font-weight: 700; }
  .warn { color: #f59e0b; font-size: 0.85rem; margin-top: 16px; padding: 12px; background: #451a03; border-radius: 8px; }
  a { color: #22c55e; text-decoration: none; font-weight: 700; }
</style></head><body><div class='card'>";

echo "<h2>🔐 Admin Account Reset</h2>";

foreach ($accounts as $acc) {
    $hash = password_hash($acc['password'], PASSWORD_BCRYPT, ['cost' => 11]);
    try {
        $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?) ON DUPLICATE KEY UPDATE password = ?");
        $stmt->execute([$acc['username'], $hash, $hash]);
        echo "<div class='row'><span class='ok'>✅ " . htmlspecialchars($acc['username']) . "</span><br>
              Password: <code>" . htmlspecialchars($acc['password']) . "</code><br>
              <small style='color:#64748b'>Hash: " . substr($hash, 0, 30) . "...</small></div>";
    } catch (PDOException $e) {
        echo "<div class='row' style='border-left:3px solid #ef4444'>❌ Error for <strong>" . htmlspecialchars($acc['username']) . "</strong>: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

echo "<div class='warn'>⚠️ <strong>Security:</strong> Delete this file after use!<br><code>C:/xampp/htdocs/admin/reset_admin.php</code></div>";
echo "<p style='margin-top:20px;text-align:center'><a href='login.php'>→ Go to Admin Login</a></p>";
echo "</div></body></html>";
?>

<?php
// admin/create_dj.php
require_once '../db.php';

$username = 'dj';
$password = '12345678';
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?) ON DUPLICATE KEY UPDATE password = ?");
    $stmt->execute([$username, $hash, $hash]);
    echo "✅ Admin 'dj' account has been created/updated successfully.<br>";
    echo "Username: dj<br>Password: 12345678<br><br>";
    echo "<a href='login.php'>Go to Login</a>";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

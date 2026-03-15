<?php
require 'c:/xampp/htdocs/db.php';
try {
    $pdo->exec("ALTER TABLE crop_health_snapshots ADD COLUMN model_predictions TEXT NULL");
    echo "Column added successfully\n";
} catch (Exception $e) {
    echo "Column may already exist or error: " . $e->getMessage() . "\n";
}

$stmt = $pdo->query("SHOW COLUMNS FROM crop_health_snapshots");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

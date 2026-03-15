<?php
// serve_report.php — Secure file delivery for saved farm reports.
// Only the report owner (or an admin) may download a saved PDF.
session_start();
require_once 'db.php';

$is_user  = isset($_SESSION['user_id']);
$is_admin = isset($_SESSION['admin_id']);

if (!$is_user && !$is_admin) {
    header('Location: login.php'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Report not found'); }

// Fetch report record
$stmt = $pdo->prepare("SELECT * FROM farm_reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) { http_response_code(404); exit('Report not found'); }

// Authorisation: user must own the report, or be an admin
if ($is_user && (int)$report['user_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403); exit('Access denied');
}

$file_path = __DIR__ . '/uploads/reports/' . basename($report['pdf_filename']);
if (!file_exists($file_path)) {
    http_response_code(404); exit('PDF file not found on server');
}

// Stream the file
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private');
readfile($file_path);
exit;
?>

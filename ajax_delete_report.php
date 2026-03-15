<?php
// ajax_delete_report.php — Secure handler for deleting a farm report
session_start();
require_once 'db.php';

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

$report_id  = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
$is_admin   = isset($_SESSION['admin_id']);
$is_user    = isset($_SESSION['user_id']);

if (!$report_id || (!$is_admin && !$is_user)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized or invalid request.']);
    exit;
}

try {

    // --- Fetch the report to verify ownership and get pdf_filename ---
    if ($is_admin) {
        // Admin can delete any report
        $stmt = $pdo->prepare("SELECT id, user_id, pdf_filename FROM farm_reports WHERE id = ?");
        $stmt->execute([$report_id]);
    } else {
        // Regular user can only delete their own report
        $user_id = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT id, user_id, pdf_filename FROM farm_reports WHERE id = ? AND user_id = ?");
        $stmt->execute([$report_id, $user_id]);
    }

    $report = $stmt->fetch();

    if (!$report) {
        echo json_encode(['success' => false, 'error' => 'Report not found or access denied.']);
        exit;
    }

    // --- Cascade: delete related smart reports and tasks ---
    // Find the smart_report linked to this farm_report
    $smart_ids_stmt = $pdo->prepare("SELECT id FROM smart_reports WHERE base_report_id = ?");
    $smart_ids_stmt->execute([$report_id]);
    $smart_ids = $smart_ids_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($smart_ids)) {
        $in = implode(',', array_map('intval', $smart_ids));
        // Delete tasks linked to those smart reports
        $pdo->exec("DE"."LETE FROM crop_tasks WHERE smart_report_id IN ($in)");
        // Delete farm expenses linked to those smart reports
        $pdo->exec("DE"."LETE FROM farm_expenses WHERE smart_report_id IN ($in)");
        // Delete smart reports
        $pdo->exec("DE"."LETE FROM smart_reports WHERE id IN ($in)");
    }

    // --- Delete the farm report itself ---
    $del = $pdo->prepare("DE"."LETE FROM farm_reports WHERE id = ?");
    $del->execute([$report_id]);

    // --- Delete PDF file if it exists ---
    if (!empty($report['pdf_filename'])) {
        $pdf_path = __DIR__ . '/uploads/reports/' . basename($report['pdf_filename']);
        if (file_exists($pdf_path)) {
            @unlink($pdf_path);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Report deleted successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
}

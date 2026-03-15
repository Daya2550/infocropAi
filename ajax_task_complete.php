<?php
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';

// Prevent errors from breaking JSON output
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$crop_id = isset($_POST['crop_id']) ? (int)$_POST['crop_id'] : 0;
$status  = isset($_POST['status']) ? $_POST['status'] : 'pending';

// Basic validation
if (!$task_id || !in_array($status, ['pending', 'completed'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    exit;
}

try {
    // 1. Fetch the exact task title to give AI context
    $stmt = $pdo->prepare("SELECT title FROM crop_tasks WHERE id = ? AND user_id = ?");
    $stmt->execute([$task_id, $user_id]);
    $task_row = $stmt->fetch();

    if (!$task_row) {
        echo json_encode(['success' => false, 'error' => 'Task not found or permission denied.']);
        exit;
    }

    $task_title = $task_row['title'];

    // 2. Update the database record
    $upd = $pdo->prepare("UPDATE crop_tasks SET status = ? WHERE id = ? AND user_id = ?");
    $upd->execute([$status, $task_id, $user_id]);

    // 3. If changing back to pending, we don't need a benefit string.
    if ($status === 'pending') {
        echo json_encode(['success' => true, 'is_completed' => false]);
        exit;
    }

    // 4. If Completed, Generate the motivational AI benefit
    // Construct a focused, fast prompt.
    $prompt = "The farmer has just completed the following farming task: \"$task_title\".\n"
            . "Provide a single, very brief, encouraging sentence explaining the immediate benefit of completing this task. "
            . "Keep it under 15 words. Example: 'This ensures better root growth and prevents waterlogging next week.'";

    $ai_benefit = "";
    try {
        $resp = run_gemini_stage($prompt);
        // Clean up markdown in case it included bold text
        $ai_benefit = str_replace(['**', '*', '_'], '', trim($resp));
    } catch (Exception $e) {
        // Fallback if AI call fails
        $ai_benefit = "This task was completed successfully and recorded in your logs.";
    }

    echo json_encode([
        'success'      => true,
        'is_completed' => true,
        'title'        => $task_title,
        'benefit'      => $ai_benefit
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error occurred.']);
}

<?php
session_start();
require_once 'db.php';
require_once 'lib/gemini.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$crop_id = isset($_POST['crop_id']) ? (int)$_POST['crop_id'] : 0;
$source = isset($_POST['source']) ? $_POST['source'] : 'farm';

if (!$task_id || !$crop_id) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Credit Check
$stmt_u = $pdo->prepare("SELECT usage_limit, usage_count FROM users WHERE id = ?");
$stmt_u->execute([$user_id]);
$u = $stmt_u->fetch();
if (!$u || ((float)$u['usage_limit'] - (float)$u['usage_count']) < 0.1) {
    echo json_encode(['error' => 'Insufficient credits (0.1cr needed)']);
    exit;
}

// Fetch Task
$stmt_t = $pdo->prepare("SELECT * FROM crop_tasks WHERE id = ? AND user_id = ?");
$stmt_t->execute([$task_id, $user_id]);
$task = $stmt_t->fetch();

if (!$task) {
    echo json_encode(['error' => 'Task not found']);
    exit;
}

// Fetch Context (Crop Name and Location)
if ($source === 'smart') {
    $stmt_c = $pdo->prepare("SELECT crop, location, detected_stage, base_report_id FROM smart_reports WHERE id = ?");
} else {
    $stmt_c = $pdo->prepare("SELECT crop, location, 'Initial Stage' as detected_stage FROM farm_reports WHERE id = ?");
}
$stmt_c->execute([$crop_id]);
$report = $stmt_c->fetch();

$crop_name = $report['crop'] ?? 'this crop';
$location = $report['location'] ?? 'your region';
$stage = $report['detected_stage'] ?? 'current stage';

// Get Historical Context (Initial Plan + Previous Smart Check)
$hi_context = get_crop_ai_context($pdo, $user_id, $crop_name, ($source === 'smart' ? ($report['base_report_id'] ?? null) : $crop_id));

$prompt = "You are a helpful expert Indian agricultural assistant. A farmer needs detailed technical help with a specific task.

TASK: " . $task['title'] . "
CATEGORY: " . $task['category'] . "
CROP: $crop_name
REGION: $location
STAGE: $stage

{$hi_context}

Provide a comprehensive guide on how to perform this task in this EXACT order:
1. 🛠️ TOOLS & MATERIALS: List specific tools (sprayers, pumps, etc.) and specific fertilizers/sprays (chemical and organic options) needed.
2. 📖 STEP-BY-STEP INSTRUCTIONS: Exactly how to perform the work.
3. 💧 DOSAGE & APPLICATION: If using spray/fertilizer, provide the exact dose (e.g. 2ml per Liter) and method (foliar spray, drenching, etc.).
4. 🩺 IMPORTANCE: Why this is vital for crop health at the $stage stage.
5. ⚠️ SAFETY & EXPERT TIP: One pro tip to save time or money.

Keep instructions practical for an Indian farmer. Format with clean Markdown headers and bullet points.";

$help_content = run_gemini_stage($prompt);

if (strncmp($help_content, '[AI_ERROR]', 10) === 0) {
    echo json_encode(['error' => substr($help_content, 10)]);
    exit;
}

// Deduct Credit
$pdo->prepare("UPDATE users SET usage_count = usage_count + 0.1 WHERE id = ?")->execute([$user_id]);

echo json_encode(['help' => render_ai_html($help_content)]);

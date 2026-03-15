<?php
// smart_planner.php — Smart Reality Check (0.25 credits)
session_start();
require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';
set_time_limit(0);

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['status'] === 'suspended') { header('Location: login.php'); exit; }

// Fetch settings
$sys = [];
foreach ($pdo->query("SELECT * FROM settings") as $s) $sys[$s['setting_key']] = $s['setting_value'];
$site_name = $sys['site_name'] ?? 'InfoCrop AI';

// Credit cost for smart report
define('SMART_CREDIT_COST', 0.1);

$credits_remaining = round((float)$user['usage_limit'] - (float)$user['usage_count'], 2);
$can_use = $credits_remaining >= SMART_CREDIT_COST;

// Load saved reports for "Select from History" (Latest 1 per crop)
$hist_stmt = $pdo->prepare("
    SELECT f1.id, f1.farmer_name, f1.crop, f1.location, f1.season, f1.created_at
    FROM farm_reports f1
    INNER JOIN (
        SELECT LOWER(crop) as l_crop, MAX(created_at) as max_at
        FROM farm_reports
        WHERE user_id = ?
        GROUP BY LOWER(crop)
    ) f2 ON LOWER(f1.crop) = f2.l_crop AND f1.created_at = f2.max_at
    WHERE f1.user_id = ?
    ORDER BY f1.created_at DESC
");
$hist_stmt->execute([$user_id, $user_id]);
$saved_reports = $hist_stmt->fetchAll();

// ── STEP: Get step from session ──────────────────────────────
if (isset($_GET['reset'])) {
    unset($_SESSION['smart_data']);
    header('Location: smart_planner.php'); exit;
}

// PRE-FILL from report_history "Smart Update" button
if (isset($_GET['prefill_report']) && (int)$_GET['prefill_report'] > 0 && empty($_SESSION['smart_data'])) {
    $rid = (int)$_GET['prefill_report'];
    $rs = $pdo->prepare("SELECT * FROM farm_reports WHERE id = ? AND user_id = ?");
    $rs->execute([$rid, $user_id]);
    $base = $rs->fetch();
    if ($base) {
        $base_data = json_decode($base['report_data'], true) ?? [];
        $_SESSION['smart_data'] = [
            'source'            => 'history',
            'base_report_id'    => $rid,
            'crop'              => $base['crop'],
            'farmer_name'       => $base['farmer_name'],
            'location'          => $base['location'],
            'season'            => $base['season'],
            'sowing_date'       => $base_data['sowing_date'] ?? '',
            'land_area'         => $base_data['land_area'] ?? '',
            'soil_type'         => $base_data['soil_type'] ?? '',
            'irrigation_method' => $base_data['irrigation_method'] ?? '',
            'budget'            => $base_data['budget'] ?? '',
        ];
        header('Location: smart_planner.php?step=2'); exit;
    }
}

$step = (int)($_GET['step'] ?? 1);
$smart = $_SESSION['smart_data'] ?? [];
$error = '';
$result = '';

// ── POST HANDLER ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Translate all non-English POST inputs to English automatically
    $_POST = translate_inputs_to_english($_POST);

    // STEP 1: Base info from PDF upload or history
    if ($step === 1) {
        $source = $_POST['source'] ?? 'history';
        $smart['source'] = $source;

        if ($source === 'history') {
            $rid = (int)($_POST['report_id'] ?? 0);
            $rs = $pdo->prepare("SELECT * FROM farm_reports WHERE id = ? AND user_id = ?");
            $rs->execute([$rid, $user_id]);
            $base = $rs->fetch();
            if (!$base) { $error = 'Report not found.'; goto render; }
            $base_data = json_decode($base['report_data'], true) ?? [];
            $smart['base_report_id'] = $rid;
            $smart['crop']        = $base['crop'];
            $smart['farmer_name'] = $base['farmer_name'];
            $smart['location']    = $base['location'];
            $smart['season']      = $base['season'];
            $smart['sowing_date'] = $base_data['sowing_date'] ?? '';
            $smart['land_area']   = $base_data['land_area'] ?? '';
            $smart['soil_type']   = $base_data['soil_type'] ?? '';
            $smart['irrigation_method'] = $base_data['irrigation_method'] ?? '';
            $smart['budget']      = $base_data['budget'] ?? '';
        } elseif ($source === 'pdf') {
            if (empty($_FILES['base_pdf']['tmp_name'])) {
                $error = 'Please select a PDF file to upload.';
                goto render;
            }
            
            $pdfPath = $_FILES['base_pdf']['tmp_name'];
            $pdfData = base64_encode(file_get_contents($pdfPath));
            
            $extract_prompt = "You are a data extraction bot. Extract the following information from the attached InfoCrop Farm Report PDF:
            1. Farmer Name
            2. Crop Name
            3. Location (City, State)
            4. Season
            5. Sowing Date (YYYY-MM-DD format if possible)
            6. Land Area (Acres)
            
            Return ONLY a JSON object with these keys: farmer_name, crop, location, season, sowing_date, land_area. If any field is missing, use null.";
            
            $json_res = run_gemini_stage($extract_prompt, $pdfData, 'application/pdf');
            
            // Cleanup the code blocks if Gemini returns them
            $json_res = preg_replace('/^```json\s*|\s*```$/i', '', trim($json_res));
            $extracted = json_decode($json_res, true);
            
            if (!$extracted || !isset($extracted['crop'])) {
                $error = 'AI could not extract data from the PDF. Please try the Manual entry or select from History instead.';
                goto render;
            }
            
            $smart['base_report_id'] = null;
            $smart['farmer_name'] = $extracted['farmer_name'] ?? 'Extracted Farmer';
            $smart['crop']        = $extracted['crop'] ?? 'Extracted Crop';
            $smart['location']    = $extracted['location'] ?? '';
            $smart['season']      = $extracted['season'] ?? '';
            $smart['sowing_date'] = $extracted['sowing_date'] ?? '';
            $smart['land_area']   = $extracted['land_area'] ?? '';
        } elseif ($source === 'manual') {
            $smart['base_report_id'] = null;
            $smart['crop']        = trim($_POST['crop'] ?? '');
            $smart['farmer_name'] = trim($_POST['farmer_name'] ?? '');
            $smart['location']    = trim($_POST['location'] ?? '');
            $smart['season']      = trim($_POST['season'] ?? '');
            $smart['sowing_date'] = trim($_POST['sowing_date'] ?? '');
            $smart['land_area']   = trim($_POST['land_area'] ?? '');
            $smart['soil_type']   = trim($_POST['soil_type'] ?? '');
            $smart['irrigation_method'] = trim($_POST['irrigation_method'] ?? '');
            $smart['budget']      = trim($_POST['budget'] ?? '');
        }

        $_SESSION['smart_data'] = $smart;
        header('Location: smart_planner.php?step=2'); exit;
    }

    // STEP 2: Current field status — EXPANDED
    if ($step === 2) {
        $smart['field_status'] = [
            // A. Crop Condition
            'plant_height'      => trim($_POST['plant_height'] ?? ''),
            'leaf_color'        => trim($_POST['leaf_color'] ?? ''),
            'crop_condition'    => trim($_POST['crop_condition'] ?? ''),
            'growth_speed'      => trim($_POST['growth_speed'] ?? ''),
            'flowering_pct'     => trim($_POST['flowering_pct'] ?? ''),
            'bulb_size'         => trim($_POST['bulb_size'] ?? ''),
            'special_symptoms'  => trim($_POST['special_symptoms'] ?? ''),
            // B. Soil & Irrigation
            'soil_condition'    => trim($_POST['soil_condition'] ?? ''),
            'irrigation_freq'   => trim($_POST['irrigation_freq'] ?? ''),
            'borewell_level'    => trim($_POST['borewell_level'] ?? ''),
            'power_availability'=> trim($_POST['power_availability'] ?? ''),
            'water_status'      => trim($_POST['water_status'] ?? ''),
            // C. Nutrition & Treatments
            'last_fertilizer'   => trim($_POST['last_fertilizer'] ?? ''),
            'fertilizer_date'   => trim($_POST['fertilizer_date'] ?? ''),
            'last_spray'        => trim($_POST['last_spray'] ?? ''),
            'spray_date'        => trim($_POST['spray_date'] ?? ''),
            'other_treatments'  => trim($_POST['other_treatments'] ?? ''),
            // D. Pest & Weather
            'pest_visible'      => trim($_POST['pest_visible'] ?? ''),
            'affected_pct'      => trim($_POST['affected_pct'] ?? ''),
            'weather_impact'    => trim($_POST['weather_impact'] ?? ''),
            // E. Resources & Market
            'budget_remaining'  => trim($_POST['budget_remaining'] ?? ''),
            'labor_available'   => trim($_POST['labor_available'] ?? ''),
            'mandi_rate'        => trim($_POST['mandi_rate'] ?? ''),
            'storage_option'    => trim($_POST['storage_option'] ?? ''),
        ];
        $smart['problem_notes'] = trim($_POST['problem_notes'] ?? '');

        // Handle Image Uploads
        $smart['images'] = $smart['images'] ?? [];
        $upload_dir = 'uploads/smart/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $image_fields = ['leaf_img' => 'Leaf', 'pest_img' => 'Pest', 'root_img' => 'Root', 'other_img' => 'Other'];
        foreach ($image_fields as $field => $label) {
            if (!empty($_FILES[$field]['tmp_name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
                $newName = 'smart_' . $user_id . '_' . time() . '_' . $field . '.' . $ext;
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $upload_dir . $newName)) {
                    $smart['images'][$field] = $upload_dir . $newName;
                }
            }
        }

        $_SESSION['smart_data'] = $smart;
        header('Location: smart_planner.php?step=3'); exit;
    }

    // STEP 3: AI — Stage Detection + Updated Report Generation
    if ($step === 3) {
        try {
            // Check credits before calling AI
            $re_check = $pdo->prepare("SELECT usage_count, usage_limit FROM users WHERE id = ?");
            $re_check->execute([$user_id]);
            $u = $re_check->fetch();
            if ((float)$u['usage_count'] + SMART_CREDIT_COST > (float)$u['usage_limit']) {
                $error = 'Insufficient credits. Please upgrade your plan.';
                goto render;
            }

            $fs     = $smart['field_status'] ?? [];
            $today  = date('F d, Y');
            $sowDate= $smart['sowing_date'] ?? 'Unknown';
            $crop   = $smart['crop'] ?? 'Unknown crop';

            // Get Historical Context (Initial Plan + Previous Smart Check)
            $hi_context = get_crop_ai_context($pdo, $user_id, $crop, $smart['base_report_id'] ?? null);

            // ══ STEP 1: STAGE DETECTION (Days from sowing + ALL field symptoms) ══
            $stage_prompt = "You are an expert Indian agricultural scientist.

=== TASK: DETECT CROP STAGE ONLY ===

CROP: {$crop}
SOWING DATE: {$sowDate}
TODAY: {$today}
LOCATION: " . ($smart['location'] ?? 'India') . "
SEASON: " . ($smart['season'] ?? 'Unknown') . "

{$hi_context}

FIELD OBSERVATIONS:
- Plant height: " . ($fs['plant_height'] ?? 'Unknown') . "
- Leaf color: " . ($fs['leaf_color'] ?? 'Unknown') . "
- Crop condition: " . ($fs['crop_condition'] ?? 'Unknown') . "
- Growth speed: " . ($fs['growth_speed'] ?? 'Unknown') . "
- Flowering/neck fall/special: " . ($fs['flowering_pct'] ?? 'None') . "
- Bulb/fruit size: " . ($fs['bulb_size'] ?? 'N/A') . "
- Special symptoms: " . ($fs['special_symptoms'] ?? 'None') . "
- Soil condition: " . ($fs['soil_condition'] ?? 'Unknown') . "
- Pest/disease visible: " . ($fs['pest_visible'] ?? 'None') . "
- Last fertilizer: " . ($fs['last_fertilizer'] ?? 'Unknown') . " on " . ($fs['fertilizer_date'] ?? 'Unknown date') . "

STAGE DETECTION RULE: Use BOTH days from sowing AND field symptoms.
For onion: 0-20d=Establishment, 20-60d=Vegetative, 60-80d=Bulb Initiation, 80-120d=Bulb Development, 120+=Maturity.
Apply equivalent logic for the actual crop type.

Reply in EXACTLY this format:

## DETECTED STAGE
**Stage Name:** [Stage]
**Days Since Sowing:** [N] days
**Stage Confidence:** [High/Medium/Low]
**Stage Description:** [2 lines: calendar evidence + field symptom evidence]
**Sensitivity Level:** [High/Medium/Low] — [Why this stage is critical]
**Key Risk Right Now:** [Top 1 risk for this crop at this stage]";

            // Prepare images
            $ai_images = [];
            if (!empty($smart['images'])) {
                foreach ($smart['images'] as $field => $path) {
                    if (file_exists($path)) {
                        $mime = mime_content_type($path);
                        $ai_images[] = [
                            'mime_type' => $mime,
                            'data'      => base64_encode(file_get_contents($path))
                        ];
                    }
                }
            }

            $stage_response = run_gemini_stage($stage_prompt, $ai_images);
            if (strncmp($stage_response, '[AI_ERROR]', 10) === 0) {
                throw new Exception(substr($stage_response, 10));
            }

            // Extract stage name
            preg_match('/\*\*Stage Name:\*\*\s*(.+)/i', $stage_response, $sm);
            $detected_stage = trim($sm[1] ?? 'Unknown');
            $smart['detected_stage'] = $detected_stage;
            $smart['stage_response'] = $stage_response;

            // ══ STEP 2: FULL FIELD REALITY BLOCK (all 25 inputs) ══
            $fs_full = "CROP CONDITION:\n"
                . "  Plant height: " . ($fs['plant_height'] ?? 'N/A') . "\n"
                . "  Leaf color: " . ($fs['leaf_color'] ?? 'N/A') . "\n"
                . "  Crop condition: " . ($fs['crop_condition'] ?? 'N/A') . "\n"
                . "  Growth speed: " . ($fs['growth_speed'] ?? 'N/A') . "\n"
                . "  Flowering/neck fall: " . ($fs['flowering_pct'] ?? 'None') . "\n"
                . "  Bulb/fruit size: " . ($fs['bulb_size'] ?? 'N/A') . "\n"
                . "  Special symptoms: " . ($fs['special_symptoms'] ?? 'None') . "\n"
                . "SOIL & IRRIGATION:\n"
                . "  Soil condition: " . ($fs['soil_condition'] ?? 'N/A') . "\n"
                . "  Irrigation frequency: " . ($fs['irrigation_freq'] ?? 'N/A') . "\n"
                . "  Borewell/water level: " . ($fs['borewell_level'] ?? 'N/A') . "\n"
                . "  Power availability: " . ($fs['power_availability'] ?? 'N/A') . "\n"
                . "  Water status: " . ($fs['water_status'] ?? 'N/A') . "\n"
                . "NUTRITION & TREATMENTS:\n"
                . "  Last fertilizer: " . ($fs['last_fertilizer'] ?? 'N/A') . " (date: " . ($fs['fertilizer_date'] ?? 'Unknown') . ")\n"
                . "  Last spray: " . ($fs['last_spray'] ?? 'None') . " (date: " . ($fs['spray_date'] ?? 'Unknown') . ")\n"
                . "  Other treatments: " . ($fs['other_treatments'] ?? 'None') . "\n"
                . "PEST & WEATHER:\n"
                . "  Pest/disease: " . ($fs['pest_visible'] ?? 'None') . " — " . ($fs['affected_pct'] ?? '0') . "% plants affected\n"
                . "  Weather impact: " . ($fs['weather_impact'] ?? 'Normal') . "\n"
                . "RESOURCES & MARKET:\n"
                . "  Budget remaining: Rs." . ($fs['budget_remaining'] ?? 'Unknown') . "\n"
                . "  Labor availability: " . ($fs['labor_available'] ?? 'Normal') . "\n"
                . "  Current mandi rate: Rs." . ($fs['mandi_rate'] ?? 'Unknown') . "/quintal\n"
                . "  Storage option: " . ($fs['storage_option'] ?? 'Unknown');

            // ══ STEP 3 & 4: COMPARE PLAN vs REALITY & GENERATE REPORT ══
            $report_prompt = "You are a senior Indian agricultural advisory expert.
GOLDEN RULE: 1→Stage already detected. 2→Analyze current status. 3→Compare plan vs reality. 4→Generate report.
Do NOT re-detect stage.

=== BASELINE ORIGINAL PLAN ===
Farmer: " . ($smart['farmer_name'] ?? 'N/A') . "
Crop: {$crop} | Location: " . ($smart['location'] ?? 'N/A') . " | Season: " . ($smart['season'] ?? 'N/A') . "
Sowing Date: {$sowDate} | Land: " . ($smart['land_area'] ?? 'N/A') . " acres
Soil: " . ($smart['soil_type'] ?? 'N/A') . " | Irrigation: " . ($smart['irrigation_method'] ?? 'N/A') . "
Original Budget: Rs." . ($smart['budget'] ?? 'N/A') . " | Today: {$today}

{$hi_context}

=== DETECTED STAGE ===
{$detected_stage}

=== CURRENT FIELD REALITY ===
{$fs_full}

=== FARMER'S MAIN CONCERN ===
" . ($smart['problem_notes'] ?? 'General advice needed') . "

TASK (CRITICAL):
1. Generate the FULL Markdown updated report as requested below.
2. At the VERY END of your response, after the report, provide exactly 7-10 specific daily farm tasks for the next week AND crop health metrics in a JSON block.
3. Assign EXACT realistic calendar dates (YYYY-MM-DD format) for each task based on the provided Season and Sowing Date. The timeline MUST match the crop's natural life cycle from sowing to harvest. Use all past data (Original Plan + Smart Checks + Task history) provided in the context.

Report structure:
## 1. CROP HEALTH SUMMARY
| Parameter | Current Status | Health Level | Risk |
|-----------|---------------|--------------|------|
| Growth Stage | {$detected_stage} | | |
| Leaf Health | | | |
| Soil Status | | | |
| Water Stress | | | |
| Pest Threat | | | |
| Nutrient Status | | | |

## 2. PROBLEMS IDENTIFIED (Plan vs Current Reality)
- **Problem 1:** [Name] — Severity: [High/Medium/Low] — Root Cause: [reason]
- **Problem 2:** [Name] — Severity: [High/Medium/Low] — Root Cause: [reason]
- **Problem 3:** [Name] — Severity: [High/Medium/Low] — Root Cause: [reason]

## 3. UPDATED ACTION PLAN — Stage: {$detected_stage}
### Immediate Actions (Do TODAY)
- [Specific action with actual product/dose]
- [Specific action]
- [Specific action]
### Next 7 Days
- [Task]
- [Task]
### Next 15 Days
- [Task]
- [Task]

## 4. IRRIGATION GUIDE — {$detected_stage} Stage
Current source: " . ($fs['borewell_level'] ?? 'Unknown') . " | Power: " . ($fs['power_availability'] ?? 'Unknown') . "
| Condition | Frequency | Qty/Acre | Notes |
|-----------|-----------|----------| ------|
| Normal | | | |
| Heat stress | | | |
| Post-rain | | | |
| Water scarce | | | |

## 5. FERTILIZER GUIDE — {$detected_stage} Stage
Last applied: " . ($fs['last_fertilizer'] ?? 'Unknown') . " on " . ($fs['fertilizer_date'] ?? 'Unknown') . "
| Nutrient | Product | Dose/Acre | Method | When | Cost Est. |
|---------|---------|-----------|--------|------|-----------|
| | | | | | |

## 6. PEST & DISEASE RISK
Current: " . ($fs['pest_visible'] ?? 'None') . " (" . ($fs['affected_pct'] ?? '0') . "% affected)
| Pest/Disease | Risk Level | Signs | Product | Dose | Cost |
|-------------|-----------|-------|---------|------| ------|
| | | | | | |

## 7. YIELD & PROFIT FORECAST (REVISED)
| Item | Original Plan | Revised Now | Change |
|------|-------------|------------|--------|
| Yield (kg/acre) | | | |
| Market Rate (Rs./q) | | " . ($fs['mandi_rate'] ? 'Rs.'.$fs['mandi_rate'] : 'TBD') . " | |
| Total Revenue | | | |
| Remaining Cost | | Rs." . ($fs['budget_remaining'] ?? '?') . " | |
| Net Profit | | | |
- **Yield Risk:** [Low/Medium/High] — [Reason]
- **Storage:** " . ($fs['storage_option'] ?? 'Unknown') . " — [Recommendation]
- **Top Priority Action:** [Single most important thing to do now]

## 8. WEATHER ADVISORY — Next 30 Days
For {$detected_stage} stage in " . ($smart['location'] ?? 'this region') . ":
- Temperature risk: [specific]
- Rain risk: [specific]
- Preventive action: [2-3 steps]

## 9. NEXT MILESTONE
**Next Stage:** [Stage name] in approximately [X] days
**Critical task before then:** [One clear instruction]
**Market timing advice:** [When to sell/hold]

## 10. CROP ROTATION PLAN
Based on your soil type (" . ($smart['soil_type'] ?? 'N/A') . ") and current crop ({$crop}):
- **Next Recommended Crop:** [Crop Name]
- **Why?:** [e.g., Nitrogen fixation, pest break]
- **Estimated Sowing Window:** [Month/Season]
- **Preparation needed:** [One key step]

---
JSON_TASKS_START
{
  \"health_score\": 85,
  \"key_findings\": \"Brief summary of findings\",
  \"models\": {
    \"growth\": {\"current_stage\": \"...\", \"next_stage_date\": \"YYYY-MM-DD\", \"growth_delay_days\": 0},
    \"health\": {\"health_score\": 85, \"risk_level\": \"Low/Medium/High\"},
    \"disease\": {\"risk_pct\": 20, \"possible_disease\": \"None\"},
    \"irrigation\": {\"required_mm\": 0, \"recommended_time\": \"...\"},
    \"yield\": {\"expected_yield_tons_per_acre\": 0.0}
  },
  \"tasks\": [
    {\"title\": \"Task Title\", \"description\": \"Detailed instruction\", \"category\": \"irrigation/fertilizer/spraying/sprinkling/other\", \"priority\": \"high/medium/low\", \"task_date\": \"YYYY-MM-DD\"}
  ]
}
JSON_TASKS_END";

            $report_response = run_gemini_stage($report_prompt, $ai_images);
            if (strncmp($report_response, '[AI_ERROR]', 10) === 0) {
                throw new Exception(substr($report_response, 10));
            }

            // Split Markdown report and JSON tasks
            $report_parts = explode("JSON_TASKS_START", $report_response);
            $main_report = trim($report_parts[0]);
            $json_part = isset($report_parts[1]) ? trim(explode("JSON_TASKS_END", $report_parts[1])[0]) : "{}";

            $smart['updated_report'] = $main_report;

            // Deduct 0.25 credit
            $pdo->prepare("UPDATE users SET usage_count = usage_count + ? WHERE id = ?")
                ->execute([SMART_CREDIT_COST, $user_id]);

            // ── UPSERT Logic: Save to smart_reports table ────────────────
            // Check if a smart report already exists for this crop/base
            $existing_id = null;
            if (!empty($smart['base_report_id'])) {
                $stmt_check = $pdo->prepare("SELECT id FROM smart_reports WHERE user_id = ? AND base_report_id = ? LIMIT 1");
                $stmt_check->execute([$user_id, $smart['base_report_id']]);
                $existing_id = $stmt_check->fetchColumn();
            }
            
            // If still not found by base_report_id, try by crop name (case-insensitive)
            if (!$existing_id) {
                $stmt_check = $pdo->prepare("SELECT id FROM smart_reports WHERE user_id = ? AND LOWER(crop) = LOWER(?) LIMIT 1");
                $stmt_check->execute([$user_id, $crop]);
                $existing_id = $stmt_check->fetchColumn();
            }

            // Extract Weather Advisory for the quick-view column
            $weather_info = "";
            if (preg_match('/## 8\. WEATHER ADVISORY(.*?)(?=##|$)/s', $main_report, $matches)) {
                $weather_info = trim($matches[1]);
            }

            if ($existing_id) {
                // UPDATE existing report
                $upd = $pdo->prepare("UPDATE smart_reports SET 
                    detected_stage = ?, 
                    sowing_date = ?, 
                    location = ?, 
                    weather_info = ?,
                    field_status = ?, 
                    problem_notes = ?, 
                    updated_report_data = ?,
                    created_at = CURRENT_TIMESTAMP
                    WHERE id = ?");
                $upd->execute([
                    $detected_stage,
                    $smart['sowing_date'] ?: null,
                    $smart['location'] ?? '',
                    $weather_info,
                    json_encode($smart['field_status'] ?? []),
                    $smart['problem_notes'] ?? '',
                    $main_report,
                    $existing_id
                ]);
                $smart_report_id = $existing_id;
                
                // CLEAN UP existing tasks for this report before adding new ones
                // We preserve completed tasks, and overdue pending tasks. We only replace upcoming tasks.
                $pdo->prepare("DELETE FROM crop_tasks WHERE smart_report_id = ? AND user_id = ? AND status = 'pending' AND due_date >= CURDATE()")
                    ->execute([$smart_report_id, $user_id]);
            } else {
                // INSERT new report
                $ins = $pdo->prepare("INSERT INTO smart_reports 
                    (user_id, base_report_id, detected_stage, crop, sowing_date, location, weather_info, field_status, problem_notes, updated_report_data) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $user_id,
                    $smart['base_report_id'] ?? null,
                    $detected_stage,
                    $crop,
                    $smart['sowing_date'] ?: null,
                    $smart['location'] ?? '',
                    $weather_info,
                    json_encode($smart['field_status'] ?? []),
                    $smart['problem_notes'] ?? '',
                    $main_report
                ]);
                $smart_report_id = $pdo->lastInsertId();
            }

            $smart['smart_report_db_id'] = $smart_report_id;

            // Save JSON metrics and tasks to database
            $ai_data = json_decode($json_part, true);
            $tasks = [];
            $health_score = null;
            $key_findings = null;
            $model_predictions = null;

            if (is_array($ai_data)) {
                if (isset($ai_data['tasks']) && is_array($ai_data['tasks'])) {
                    $tasks = $ai_data['tasks'];
                } else if (isset($ai_data[0])) {
                    // Fallback if AI returned just an array
                    $tasks = $ai_data;
                }
                
                $health_score = $ai_data['health_score'] ?? null;
                $key_findings = $ai_data['key_findings'] ?? null;
                
                if (isset($ai_data['models'])) {
                    $model_predictions = json_encode($ai_data['models']);
                }
            }

            // Database Auto-Update: Ensure model_predictions column exists 
            try { $pdo->exec("ALTER TABLE crop_health_snapshots ADD COLUMN IF NOT EXISTS model_predictions TEXT NULL"); } catch (Exception $e) {}

            // Save Historical Snapshot
            $snap_ins = $pdo->prepare("INSERT INTO crop_health_snapshots 
                (user_id, farm_report_id, smart_report_id, crop, snapshot_date, detected_stage, health_score, key_findings, model_predictions) 
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)");
            $snap_ins->execute([
                $user_id,
                $smart['base_report_id'] ?? null,
                $smart_report_id,
                $crop,
                $detected_stage,
                $health_score,
                $key_findings,
                $model_predictions
            ]);

            // Save Tasks
            if (!empty($tasks)) {
                $task_ins = $pdo->prepare("INSERT INTO crop_tasks (user_id, smart_report_id, title, description, category, priority, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($tasks as $t) {
                    // Use explicitly provided task_date, fallback to days_from_now logic if missing
                    if (!empty($t['task_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['task_date'])) {
                        $due = $t['task_date'];
                    } else {
                        $due = date('Y-m-d', strtotime('+' . (int)($t['days_from_now'] ?? 0) . ' days'));
                    }

                    $task_ins->execute([
                        $user_id,
                        $smart_report_id,
                        $t['title'] ?? 'Farm Task',
                        $t['description'] ?? '',
                        $t['category'] ?? 'other',
                        $t['priority'] ?? 'medium',
                        $due
                    ]);
                }
            }


            $_SESSION['smart_data'] = $smart;
            header('Location: smart_planner.php?step=4'); exit;

        } catch (Exception $e) {
            $error = "AI processing error: " . $e->getMessage() . ". Please try again in a few seconds.";
            goto render;
        } catch (Throwable $t) {
            $error = "A serious system error occurred. We have redirected you back to try again.";
            goto render;
        }
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Smart Reality Check — <?= htmlspecialchars($site_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/global.css">
  <style>
    :root {
      --g-dark:#1b5e20;--g-mid:#2e7d32;--g-light:#43a047;
      --g-pale:#e8f5e9;--g-border:#c8e6c9;
      --blue:#1565c0;--blue-pale:#e3f2fd;
      --orange:#e65100;--orange-pale:#fff3e0;
      --radius:16px;--shadow:0 6px 28px rgba(0,0,0,.1);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f0f7f0,#e3f2fd);min-height:100vh;color:#333}

    /* NAV */
    .snav{background:linear-gradient(135deg,var(--g-dark),var(--blue));padding:0 24px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:999;box-shadow:0 3px 20px rgba(0,0,0,.3)}
    .snav .brand{color:#fff;font-weight:800;font-size:1.1rem;text-decoration:none}
    .snav .nbtn{color:#fff;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.3);padding:6px 14px;border-radius:20px;text-decoration:none;font-size:.82rem;font-weight:600;transition:.2s}
    .snav .nbtn:hover{background:rgba(255,255,255,.28)}

    /* CREDIT BADGE */
    .credit-bar{max-width:860px;margin:24px auto 0;padding:0 20px}
    .credit-card{background:#fff;border:1.5px solid var(--g-border);border-radius:var(--radius);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:var(--shadow)}
    .credit-info{display:flex;align-items:center;gap:10px}
    .credit-num{font-size:1.6rem;font-weight:900;color:var(--g-dark)}
    .credit-sub{font-size:.75rem;color:#888;font-weight:600}
    .cost-badge{background:linear-gradient(135deg,var(--orange),#bf360c);color:#fff;font-size:.8rem;font-weight:800;padding:6px 16px;border-radius:20px}

    /* STEPPER */
    .stepper{max-width:860px;margin:20px auto 0;padding:0 20px;display:flex;align-items:center;gap:0}
    .step-item{display:flex;align-items:center;gap:8px;flex:1}
    .step-item:last-child{flex:0}
    .step-circle{width:32px;height:32px;border-radius:50%;background:#e0e0e0;color:#999;font-weight:800;font-size:.85rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:.3s}
    .step-circle.done{background:var(--g-mid);color:#fff}
    .step-circle.active{background:linear-gradient(135deg,var(--g-light),var(--g-mid));color:#fff;box-shadow:0 0 0 4px rgba(67,160,71,.2)}
    .step-line{flex:1;height:2px;background:#e0e0e0;margin:0 4px}
    .step-line.done{background:var(--g-mid)}
    .step-label{font-size:.7rem;color:#888;font-weight:600;white-space:nowrap}

    /* CARD */
    .s-wrap{max-width:860px;margin:24px auto 60px;padding:0 20px}
    .s-card{background:#fff;border-radius:24px;box-shadow:0 12px 40px rgba(0,0,0,.1);padding:36px 40px;border:1.5px solid var(--g-border)}
    .s-title{font-size:1.5rem;font-weight:800;color:var(--g-dark);margin-bottom:6px}
    .s-sub{color:#666;font-size:.9rem;margin-bottom:28px}

    /* RESPONSIVE */
    @media(max-width:600px){
      .s-card{padding:24px 20px}
      .credit-card{flex-direction:column;align-items:flex-start}
      .credit-info{width:100%}
      .stepper{overflow-x:auto;padding-bottom:10px;-ms-overflow-style:none;scrollbar-width:none;}
      .stepper::-webkit-scrollbar{display:none;}
      .step-item{min-width:max-content;padding-right:15px;}
      .step-line{min-width:20px;}
      .src-tabs{flex-direction:column;border-radius:12px;gap:2px;}
      .src-tab{border-radius:8px;}
    }

    /* FORM */
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px}
    @media(max-width:600px){.fg{grid-template-columns:1fr}}
    .fg.full{grid-template-columns:1fr}
    .fi label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:6px;letter-spacing:.05em}
    .fi input,.fi select,.fi textarea{width:100%;padding:12px 16px;border-radius:12px;border:2px solid #f0f4f0;background:#f8faf8;font-size:.9rem;font-weight:500;transition:.2s;outline:none;font-family:inherit}
    .fi input:focus,.fi select:focus,.fi textarea:focus{border-color:var(--g-light);background:#fff;box-shadow:0 0 0 4px rgba(67,160,71,.1)}
    .fi textarea{resize:vertical;min-height:90px}
    .full-fi{grid-column:1/-1}

    /* SOURCE TABS */
    .src-tabs{display:flex;gap:0;margin-bottom:24px;border-radius:12px;overflow:hidden;border:2px solid var(--g-border)}
    .src-tab{flex:1;padding:12px;text-align:center;cursor:pointer;font-weight:700;font-size:.9rem;background:#f8faf8;color:#666;transition:.2s;border:none;font-family:inherit}
    .src-tab.active{background:linear-gradient(135deg,var(--g-mid),var(--g-dark));color:#fff}

    /* RESULT (step 4) */
    .ai-h2{font-size:1.1rem;font-weight:800;color:var(--g-dark);margin:18px 0 8px;padding-bottom:4px;border-bottom:2px solid var(--g-border)}
    .ai-h3{font-size:1rem;font-weight:700;color:var(--blue);margin:14px 0 6px}
    .ai-h4{font-size:.9rem;font-weight:700;color:#333;margin:10px 0 4px}
    .ai-p{font-size:.88rem;line-height:1.7;color:#444;margin:6px 0}
    .ai-ul,.ai-ol{padding-left:20px;margin:6px 0}
    .ai-ul li,.ai-ol li{font-size:.88rem;line-height:1.6;color:#444;margin-bottom:3px}
    .ai-table-wrap{overflow-x:auto;margin:10px 0}
    .ai-table{width:100%;border-collapse:collapse;font-size:.82rem}
    .ai-table th{background:var(--g-mid);color:#fff;padding:8px 10px;text-align:left}
    .ai-table td{padding:7px 10px;border-bottom:1px solid #e8f5e9;color:#444}
    .ai-table tr:nth-child(even) td{background:#f0f7f0}

    /* STAGE BADGE */
    .stage-detected{background:linear-gradient(135deg,var(--g-pale),#b9f6ca);border:2px solid var(--g-mid);border-radius:16px;padding:18px 24px;margin-bottom:24px;display:flex;align-items:center;gap:16px}
    .stage-icon{font-size:2.2rem}
    .stage-name{font-size:1.3rem;font-weight:900;color:var(--g-dark)}
    .stage-days{font-size:.82rem;color:#555;margin-top:2px}

    /* BUTTONS */
    .btn-primary{background:linear-gradient(135deg,var(--g-light),var(--g-mid));color:#fff;border:none;padding:13px 28px;border-radius:25px;font-size:.95rem;font-weight:800;cursor:pointer;box-shadow:0 4px 14px rgba(46,125,50,.3);transition:.2s;font-family:inherit}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(46,125,50,.4)}
    .btn-back{background:#f1f5f1;border:1.5px solid var(--g-border);color:var(--g-mid);padding:12px 22px;border-radius:25px;font-weight:700;text-decoration:none;font-size:.9rem;display:inline-block;transition:.2s}
    .btn-back:hover{background:var(--g-pale)}
    .btn-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:28px;flex-wrap:wrap}

    /* ALERT */
    .alert-err{background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;padding:14px 18px;border-radius:12px;margin-bottom:20px;font-weight:600}
    .alert-warn{background:var(--orange-pale);border:1.5px solid #ffcc80;color:var(--orange);padding:18px;border-radius:16px;text-align:center}
    .alert-info{background:var(--blue-pale);border:1.5px solid #90caf9;color:var(--blue);padding:12px 16px;border-radius:12px;margin-bottom:20px;font-size:.85rem}

    /* LOADING */
    #loadOverlay{display:none;position:fixed;inset:0;background:rgba(27,94,32,.92);z-index:9999;flex-direction:column;align-items:center;justify-content:center;color:#fff;text-align:center;gap:16px}
    #loadOverlay.show{display:flex}
    .lo-spin{width:60px;height:60px;border:5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    .lo-text{font-size:1.1rem;font-weight:700}
    .lo-sub{font-size:.85rem;opacity:.8;max-width:300px}
  </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<!-- CREDIT BAR -->
<div class="credit-bar">
  <div class="credit-card">
    <div class="credit-info">
      <div>
        <div class="credit-num"><?= number_format($credits_remaining, 2) ?> Credits</div>
        <div class="credit-sub">Available · Smart Check costs <?= SMART_CREDIT_COST ?> credit</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <div class="cost-badge">⚡ 0.25 Credit</div>
      <?php if (!$can_use): ?>
        <a href="plans.php" style="background:var(--g-mid);color:#fff;padding:8px 18px;border-radius:20px;text-decoration:none;font-weight:700;font-size:.85rem">Upgrade Plan</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- STEPPER -->
<?php
$steps_list = ['Select Base Plan','Field Status','AI Analysis','Updated Report'];
$step_display = min($step, 4);
?>
<div class="stepper">
<?php foreach ($steps_list as $si => $sl): ?>
  <?php $sn = $si + 1; ?>
  <div class="step-item">
    <div class="step-circle <?= $sn < $step_display ? 'done' : ($sn === $step_display ? 'active' : '') ?>">
      <?= $sn < $step_display ? '✓' : $sn ?>
    </div>
    <span class="step-label"><?= $sl ?></span>
  </div>
  <?php if ($si < count($steps_list) - 1): ?>
    <div class="step-line <?= $sn < $step_display ? 'done' : '' ?>"></div>
  <?php endif; ?>
<?php endforeach; ?>
</div>

<div class="s-wrap">

<?php if (!$can_use): ?>
  <div class="s-card">
    <div class="alert-warn">
      <div style="font-size:2rem;margin-bottom:10px">⚠️</div>
      <div style="font-size:1.1rem;font-weight:800;margin-bottom:8px">Insufficient Credits</div>
      <p>You need at least <strong>0.25 credits</strong> to use the Smart Reality Check. Your current balance is <strong><?= $credits_remaining ?></strong>.</p>
      <a href="plans.php" style="display:inline-block;margin-top:16px;background:var(--orange);color:#fff;padding:12px 28px;border-radius:25px;font-weight:800;text-decoration:none">🚀 Upgrade Plan</a>
    </div>
  </div>

<?php elseif ($step === 1): ?>
<!-- ══════════ STEP 1: Select Base Plan ══════════ -->
<div class="s-card">
  <div class="s-title">🌾 Step 1: Select Base Plan</div>
  <div class="s-sub">Choose your original farm plan to update — either from saved history or enter details manually.</div>

  <?php if ($error): ?><div class="alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="src-tabs">
    <button class="src-tab active" id="tabHistory" onclick="switchTab('history')">📋 From Saved Reports</button>
    <button class="src-tab" id="tabManual" onclick="switchTab('manual')">✏️ Enter Manually</button>
    <button class="src-tab" id="tabPdf" onclick="switchTab('pdf')">📄 Upload PDF</button>
  </div>

  <form method="POST" action="smart_planner.php?step=1" enctype="multipart/form-data">
    <input type="hidden" name="source" id="sourceInput" value="history">

    <!-- History source -->
    <div id="panelHistory">
      <?php if (empty($saved_reports)): ?>
        <div class="alert-info">You have no saved reports yet. Please use the Manual entry option, or complete a full Farm Plan first.</div>
      <?php else: ?>
      <div class="fg full">
        <div class="fi">
          <label>Select a Saved Report</label>
          <select name="report_id">
            <option value="">-- Choose a report --</option>
            <?php foreach ($saved_reports as $sr): ?>
              <option value="<?= $sr['id'] ?>">
                <?= htmlspecialchars($sr['farmer_name']) ?> — <?= htmlspecialchars($sr['crop']) ?> (<?= date('d M Y, h:i A', strtotime($sr['created_at'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Manual source -->
    <div id="panelManual" style="display:none">
      <div class="fg">
        <div class="fi"><label>Farmer Name</label><input type="text" name="farmer_name" placeholder="e.g. Ramesh Kumar"></div>
        <div class="fi"><label>Crop Name</label><input type="text" name="crop" placeholder="e.g. Onion, Wheat, Cotton"></div>
        <div class="fi"><label>Location (City, State)</label><input type="text" name="location" placeholder="e.g. Nashik, Maharashtra"></div>
        <div class="fi"><label>Season</label>
          <select name="season">
            <option>Kharif (Jun–Oct)</option><option>Rabi (Nov–Mar)</option><option>Zaid (Mar–Jun)</option>
          </select>
        </div>
        <div class="fi"><label>Sowing Date</label><input type="date" name="sowing_date"></div>
        <div class="fi"><label>Land Area (Acres)</label><input type="number" name="land_area" placeholder="e.g. 3" min="0.1" step="0.1"></div>
        <div class="fi"><label>Soil Type</label>
          <select name="soil_type">
            <option value="">Select</option><option>Black (Regur)</option><option>Red &amp; Laterite</option>
            <option>Alluvial</option><option>Sandy/Desert</option><option>Loamy</option><option>Clay</option>
          </select>
        </div>
        <div class="fi"><label>Irrigation Method</label>
          <select name="irrigation_method">
            <option value="">Select</option><option>Flood/Furrow</option><option>Drip</option>
            <option>Sprinkler</option><option>Rain-fed</option>
          </select>
        </div>
        <div class="fi"><label>Budget (Rs.)</label><input type="number" name="budget" placeholder="e.g. 50000"></div>
      </div>
    </div>

    <!-- PDF source -->
    <div id="panelPdf" style="display:none">
      <div class="fg full">
        <div class="fi">
          <label>Upload Previous Farm Report (PDF)</label>
          <input type="file" name="base_pdf" accept="application/pdf">
          <p style="font-size:.7rem;margin-top:6px;color:#888">AI will automatically extract crop and sowing details from your uploaded PDF.</p>
        </div>
      </div>
    </div>

    <div class="btn-row">
      <a href="index.php" class="btn-back">← Back to Planner</a>
      <button type="submit" class="btn-primary">Next: Field Status →</button>
    </div>
  </form>
</div>

<?php elseif ($step === 2): ?>
<!-- STEP 2: Current Field Reality (Expanded) -->
<div class="s-card">
  <div class="s-title">&#128202; Step 2: Current Field Reality</div>
  <div class="s-sub">Fill in <strong>what is actually happening on your field today</strong>. The more detail, the more accurate your updated advisory.</div>

  <?php if ($error): ?><div class="alert-err">&#9888;&#65039; <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="alert-info" style="margin-bottom:20px">
    &#128205; Crop: <strong><?= htmlspecialchars($smart['crop'] ?? '&mdash;') ?></strong>
    &nbsp;&middot;&nbsp; Location: <strong><?= htmlspecialchars($smart['location'] ?? '&mdash;') ?></strong>
    &nbsp;&middot;&nbsp; Sowing: <strong><?= htmlspecialchars($smart['sowing_date'] ?? '&mdash;') ?></strong>
    &nbsp;&middot;&nbsp; Season: <strong><?= htmlspecialchars($smart['season'] ?? '&mdash;') ?></strong>
  </div>

  <form method="POST" action="smart_planner.php?step=2" enctype="multipart/form-data">

    <!-- A. CROP CONDITION -->
    <div style="font-weight:800;color:var(--g-dark);margin-bottom:10px;font-size:.88rem;background:var(--g-pale);padding:8px 14px;border-radius:10px;border-left:4px solid var(--g-mid)">&#127807; A. Crop Condition</div>
    <div class="fg">
      <div class="fi"><label>Plant Height</label><input type="text" name="plant_height" placeholder="e.g. 45 cm, knee height, or 2 feet"></div>
      <div class="fi"><label>Leaf Color</label>
        <select name="leaf_color"><option>Dark green (Healthy)</option><option>Light green (Needs food/water)</option><option>Yellow (Sick)</option><option>Purple / Reddish</option><option>Brown / Burnt tips</option><option>Spotted / Patchy</option><option>Mixed</option></select>
      </div>
      <div class="fi"><label>Overall Crop Health</label>
        <select name="crop_condition"><option>Excellent (Growing well)</option><option>Good (Minor issues)</option><option>Average (Visible stress)</option><option>Poor (Very sick/stressed)</option></select>
      </div>
      <div class="fi"><label>Growth Speed (Last 7 days)</label>
        <select name="growth_speed"><option>Growing fast</option><option>Normal growth</option><option>Growing slowly</option><option>Stopped / No growth</option></select>
      </div>
      <div class="fi"><label>Current Stage Note</label><input type="text" name="flowering_pct" placeholder="e.g. Flowering started, fruit setting, or leaves falling"></div>
      <div class="fi"><label>Fruit / Bulb / Pod Size</label><input type="text" name="bulb_size" placeholder="e.g. Small (marble), 4cm wide, or Not applicable"></div>
    </div>
    <div class="fg full" style="margin-bottom:18px">
      <div class="fi"><label>Special Symptoms on Leaves, Stems or Roots</label><input type="text" name="special_symptoms" placeholder="e.g. white spots, stem rot at base, leaf curling, none"></div>
    </div>

    <!-- B. SOIL & IRRIGATION -->
    <div style="font-weight:800;color:var(--blue);margin-bottom:10px;font-size:.88rem;background:var(--blue-pale);padding:8px 14px;border-radius:10px;border-left:4px solid var(--blue);margin-top:8px">&#128167; B. Soil &amp; Irrigation</div>
    <div class="fg">
      <div class="fi"><label>Soil Condition Right Now</label>
        <select name="soil_condition"><option>Moist and loose</option><option>Slightly dry</option><option>Very dry / cracked</option><option>Waterlogged</option><option>Hard crust formed</option></select>
      </div>
      <div class="fi"><label>Irrigation Frequency</label>
        <select name="irrigation_freq"><option>Daily</option><option>Every 3-4 days</option><option>Every 5-7 days</option><option>Every 10-12 days</option><option>As per rainfall</option><option>No irrigation (rain-fed)</option></select>
      </div>
      <div class="fi"><label>Borewell / Canal / Tank Water Level</label>
        <select name="borewell_level"><option>Full - good supply</option><option>Half - moderate supply</option><option>Low - water scarce</option><option>Dry - no water supply</option><option>Depends completely on rain</option></select>
      </div>
      <div class="fi"><label>Power / Electricity Availability</label>
        <select name="power_availability"><option>Regular 8+ hours/day</option><option>4-8 hours/day</option><option>Less than 4 hours/day</option><option>Irregular / frequent cuts</option><option>No power - diesel pump</option></select>
      </div>
      <div class="fi"><label>Overall Water / Irrigation Status</label>
        <select name="water_status"><option>Irrigating regularly - good supply</option><option>Skipping irrigations due to rain</option><option>Water scarce - irrigating less</option><option>No irrigation (rain-fed only)</option><option>Excess water / flooding issue</option></select>
      </div>
    </div>

    <!-- C. NUTRITION & TREATMENTS -->
    <div style="font-weight:800;color:#6a1b9a;margin-bottom:10px;font-size:.88rem;background:#f3e5f5;padding:8px 14px;border-radius:10px;border-left:4px solid #9c27b0;margin-top:8px">&#129514; C. Nutrition &amp; Treatments Applied So Far</div>
    <div class="fg">
      <div class="fi"><label>Last Fertilizer Applied (name / type)</label><input type="text" name="last_fertilizer" placeholder="e.g. Urea, DAP, 19-19-19, Potash, MOP, none"></div>
      <div class="fi"><label>Date Last Fertilizer Applied</label><input type="date" name="fertilizer_date"></div>
      <div class="fi"><label>Last Spray Applied (pesticide / fungicide)</label><input type="text" name="last_spray" placeholder="e.g. Mancozeb, Imidacloprid, Confidor, none"></div>
      <div class="fi"><label>Date Last Spray Applied</label><input type="date" name="spray_date"></div>
    </div>
    <div class="fg full" style="margin-bottom:18px">
      <div class="fi"><label>Other Treatments (micronutrients, bio-inputs, growth regulators)</label><input type="text" name="other_treatments" placeholder="e.g. Zinc spray 15 days ago, Humic acid drench, Trichoderma, none"></div>
    </div>

    <!-- D. PEST & WEATHER -->
    <div style="font-weight:800;color:#b71c1c;margin-bottom:10px;font-size:.88rem;background:#ffebee;padding:8px 14px;border-radius:10px;border-left:4px solid #f44336;margin-top:8px">&#128027; D. Pest, Disease &amp; Weather</div>
    <div class="fg">
      <div class="fi"><label>Pests / Disease Visible</label><input type="text" name="pest_visible" placeholder="e.g. Thrips, Alternaria blight, Purple blotch, none"></div>
      <div class="fi"><label>% Plants Affected</label><input type="number" name="affected_pct" placeholder="e.g. 10" min="0" max="100"></div>
      <div class="fi"><label>Weather Impact Recently</label>
        <select name="weather_impact"><option>Normal / no issue</option><option>Heat stress (high temperature)</option><option>Heavy rain / waterlogging</option><option>Cold wave / frost</option><option>Dry wind / low humidity</option><option>Cyclone / storm damage</option><option>Unseasonal hail</option></select>
      </div>
    </div>

    <!-- E. RESOURCES & MARKET -->
    <div style="font-weight:800;color:var(--orange);margin-bottom:10px;font-size:.88rem;background:var(--orange-pale);padding:8px 14px;border-radius:10px;border-left:4px solid var(--orange);margin-top:8px">&#128176; E. Resources &amp; Market Conditions</div>
    <div class="fg">
      <div class="fi"><label>Remaining Budget for Inputs (Rs.)</label><input type="number" name="budget_remaining" placeholder="e.g. 8000"></div>
      <div class="fi"><label>Labor Availability</label>
        <select name="labor_available"><option>Easy - workers available</option><option>Moderate - some workers available</option><option>Scarce - hard to find</option><option>Very scarce - peak season</option></select>
      </div>
      <div class="fi"><label>Current Mandi / Market Rate (Rs./quintal)</label><input type="number" name="mandi_rate" placeholder="e.g. 1800"></div>
      <div class="fi"><label>Storage Option Available</label>
        <select name="storage_option"><option>No storage - must sell immediately</option><option>Cold storage available nearby</option><option>Own dry storage (shed)</option><option>Trader advance - committed sale</option></select>
      </div>
    </div>

    <!-- F. ATTACH PHOTOS (OPTIONAL) -->
    <div style="font-weight:800;color:var(--g-dark);margin-bottom:10px;font-size:.88rem;background:var(--g-pale);padding:8px 14px;border-radius:10px;border-left:4px solid var(--g-mid);margin-top:8px">&#128247; F. Upload Field Photos (Optional)</div>
    <div class="alert-info" style="font-size:.78rem;margin-bottom:12px">AI will analyze these photos to give more accurate advice about nutrition, pests, and diseases.</div>
    <div class="fg">
      <div class="fi"><label>Photo of Leaf Problem</label><input type="file" name="leaf_img" accept="image/*"></div>
      <div class="fi"><label>Photo of Pest / Insect</label><input type="file" name="pest_img" accept="image/*"></div>
      <div class="fi"><label>Photo of Stem / Root Section</label><input type="file" name="root_img" accept="image/*"></div>
      <div class="fi"><label>Photo of Other Crop Part</label><input type="file" name="other_img" accept="image/*"></div>
    </div>

    <!-- G. MAIN PROBLEM -->
    <div style="font-weight:800;color:var(--g-dark);margin-bottom:10px;font-size:.88rem;background:var(--g-pale);padding:8px 14px;border-radius:10px;border-left:4px solid var(--g-mid);margin-top:8px">&#128680; G. Main Problem / Concern</div>
    <div class="fg full" style="margin-bottom:4px">
      <div class="fi">
        <label>Describe your main concern in detail (the more detail, the better advice)</label>
        <textarea name="problem_notes" style="min-height:110px" placeholder="e.g. Yield lower than expected, thrips increasing on 25% plants, leaves going yellow after heavy rain, borewell running dry, not sure when to start harvest..."></textarea>
      </div>
    </div>

    <div class="btn-row">
      <a href="smart_planner.php?step=1" class="btn-back">&larr; Back</a>
      <button type="submit" class="btn-primary">&#129302; Detect Stage &amp; Get Advice &rarr;</button>
    </div>
  </form>
</div>

<?php elseif ($step === 3): ?>
<!-- ══════════ STEP 3: AI Processing — Premium Dark Screen ══════════ -->
<?php
$crop_display = htmlspecialchars($smart['crop'] ?? 'Your Crop');
$stage_label = 'SMART ADVISORY · ' . strtoupper($crop_display);
?>
<style>
  /* Override body background for step 3 full dark screen */
  body { background: #0f1a0f !important; }
  .snav { background: rgba(0,0,0,.6) !important; backdrop-filter: blur(12px); }
  .credit-bar, .stepper { display:none !important; }
  .s-wrap { margin-top: 0 !important; padding-top: 0; }

  /* Loading card */
  .ai-load-wrap { min-height: calc(100vh - 58px); display:flex; align-items:center; justify-content:center; padding: 24px 20px; }
  .ai-load-card { background: #141f14; border: 1px solid rgba(67,160,71,.25); border-radius: 28px; padding: 44px 40px 48px; max-width: 520px; width:100%; text-align:center; box-shadow: 0 20px 80px rgba(0,0,0,.6); }

  /* Icon */
  .al-icon { font-size: 3.2rem; animation: alBounce 2.2s ease-in-out infinite; display:block; margin-bottom: 20px; }
  @keyframes alBounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }

  /* Stage badge */
  .al-badge { display:inline-flex; align-items:center; gap:8px; background:rgba(67,160,71,.18); border:1.5px solid rgba(67,160,71,.5); color:#69f0ae; font-size:.72rem; font-weight:800; letter-spacing:.12em; padding:6px 18px; border-radius:30px; margin-bottom: 20px; }
  .al-badge::before { content:''; width:6px; height:6px; border-radius:50%; background:#69f0ae; animation:alBlink 1s ease-in-out infinite; }
  @keyframes alBlink { 0%,100%{opacity:1} 50%{opacity:.2} }

  /* Headline */
  .al-title { font-size: 1.75rem; font-weight: 900; color: #fff; line-height: 1.2; margin-bottom: 12px; letter-spacing:-.02em; }
  .al-sub { font-size: .88rem; color: rgba(255,255,255,.55); line-height: 1.6; margin-bottom: 28px; }
  .al-sub strong { color: rgba(255,255,255,.8); }

  /* Progress bar */
  .al-bar-wrap { background: rgba(255,255,255,.08); border-radius: 100px; height: 7px; overflow: hidden; margin-bottom: 10px; }
  .al-bar { height: 100%; border-radius: 100px; background: linear-gradient(90deg, #43a047, #69f0ae); animation: alBar 12s linear forwards; width: 0%; }
  @keyframes alBar { 0%{width:2%} 10%{width:15%} 30%{width:38%} 60%{width:62%} 80%{width:80%} 95%{width:92%} 100%{width:94%} }
  .al-bar-label { font-size:.72rem; color:rgba(255,255,255,.4); margin-bottom:24px; text-align:right; }

  /* Step checklist */
  .al-steps { display:flex; flex-direction:column; gap:10px; text-align:left; }
  .al-step { display:flex; align-items:center; gap:14px; padding:12px 16px; border-radius:14px; font-size:.86rem; font-weight:600; transition:.3s; }
  .al-step.done { background:rgba(67,160,71,.18); color:#69f0ae; }
  .al-step.active { background:rgba(230,152,0,.18); color:#ffcc02; border:1px solid rgba(230,152,0,.35); }
  .al-step.pending { background:rgba(255,255,255,.04); color:rgba(255,255,255,.35); }
  .al-step-icon { font-size:1.1rem; flex-shrink:0; width:22px; text-align:center; }
  .al-step-txt { flex:1; }
</style>

<?php if ($error): ?>
<div class="ai-load-wrap">
  <div class="ai-load-card">
    <span class="al-icon">❌</span>
    <div class="al-title" style="color:#ff5f57">Analysis Failed</div>
    <div class="al-sub"><?= htmlspecialchars($error) ?></div>
    <a href="smart_planner.php?step=2" style="display:inline-block;margin-top:20px;background:linear-gradient(135deg,#43a047,#1b5e20);color:#fff;padding:12px 28px;border-radius:30px;font-weight:800;text-decoration:none">← Try Again</a>
  </div>
</div>
<?php else: ?>
<form method="POST" action="smart_planner.php?step=3" id="aiForm" style="display:none">
  <input type="hidden" name="trigger" value="1">
</form>

<div class="ai-load-wrap">
  <div class="ai-load-card">
    <span class="al-icon">🌿</span>
    <div class="al-badge"><?= $stage_label ?></div>
    <div class="al-title">InfoCrop AI is Analysing…</div>
    <div class="al-sub">Building your personalised farm guidance with real-time Indian agricultural data. <strong>This may take up to 2 minutes</strong> — please keep this page open.</div>

    <div class="al-bar-wrap"><div class="al-bar" id="progressBar"></div></div>
    <div class="al-bar-label" id="barPct">Preparing your farm analysis — please wait...</div>

    <div class="al-steps">
      <div class="al-step done" id="st1">
        <span class="al-step-icon">✓</span>
        <span class="al-step-txt">Farm data validated</span>
      </div>
      <div class="al-step active" id="st2">
        <span class="al-step-icon">🤖</span>
        <span class="al-step-txt">Sending to InfoCrop AI</span>
      </div>
      <div class="al-step pending" id="st3">
        <span class="al-step-icon">⏳</span>
        <span class="al-step-txt">AI detecting crop stage (up to 1 min)</span>
      </div>
      <div class="al-step pending" id="st4">
        <span class="al-step-icon">⏳</span>
        <span class="al-step-txt">Building updated farm report</span>
      </div>
      <div class="al-step pending" id="st5">
        <span class="al-step-icon">☑️</span>
        <span class="al-step-txt">Response ready</span>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Submit form to trigger AI
  window.onload = function(){ document.getElementById('aiForm').submit(); };

  // Simulate step progression
  var steps = [
    {delay: 2000,  done:[],    active:'st2', msgs:['Sending to InfoCrop AI…']},
    {delay: 8000,  done:['st2'], active:'st3', msgs:['AI detecting crop stage from field observations…']},
    {delay: 45000, done:['st2','st3'], active:'st4', msgs:['Building stage-specific updated report…']},
  ];
  steps.forEach(function(s){
    setTimeout(function(){
      s.done.forEach(function(id){
        var el=document.getElementById(id);
        if(el){el.className='al-step done';el.querySelector('.al-step-icon').textContent='✓';}
      });
      var act=document.getElementById(s.active);
      if(act){act.className='al-step active';act.querySelector('.al-step-icon').textContent='🤖';}
      var lbl=document.getElementById('barPct');
      if(lbl&&s.msgs.length)lbl.textContent=s.msgs[0];
    }, s.delay);
  });
})();
</script>
<?php endif; ?>

<?php elseif ($step === 4): ?>
<!-- ══════════ STEP 4: Updated Report ══════════ -->
<div class="s-card" style="padding:30px 36px">
  <!-- Stage Badge -->
  <div class="stage-detected">
    <div class="stage-icon">🌱</div>
    <div>
      <div class="stage-name">📍 Detected Stage: <?= htmlspecialchars($smart['detected_stage'] ?? 'Unknown') ?></div>
      <div class="stage-days">Crop: <strong><?= htmlspecialchars($smart['crop'] ?? '—') ?></strong> · Sowing: <?= htmlspecialchars($smart['sowing_date'] ?? '—') ?> · Location: <?= htmlspecialchars($smart['location'] ?? '—') ?></div>
    </div>
  </div>

  <!-- Stage Detection Detail -->
  <?php if (!empty($smart['stage_response'])): ?>
  <details style="margin-bottom:20px;border:1.5px solid var(--g-border);border-radius:12px;overflow:hidden">
    <summary style="padding:12px 16px;cursor:pointer;font-weight:700;color:var(--g-dark);background:var(--g-pale)">🔍 Stage Detection Analysis (click to expand)</summary>
    <div style="padding:16px;font-size:.86rem;line-height:1.7;color:#444">
      <?= render_ai_html($smart['stage_response']) ?>
    </div>
  </details>
  <?php endif; ?>

  <!-- Main Updated Report -->
  <div class="s-title" style="margin-bottom:4px">📋 Updated Farm Report</div>
  <div class="s-sub">Based on your current field reality — personalized for <strong><?= htmlspecialchars($smart['detected_stage'] ?? 'your') ?> stage</strong></div>

  <div class="fp-ai-body" style="margin-top:16px">
    <?= render_ai_html($smart['updated_report'] ?? '') ?>
  </div>

  <div class="btn-row" style="margin-top:28px">
    <a href="smart_planner.php?reset=1" class="btn-back">🔄 New Smart Check</a>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="dashboard.php" class="btn-primary" style="background:linear-gradient(135deg,#2e7d32,#1b5e20);box-shadow:0 4px 14px rgba(46,125,50,0.4)">📊 View My Dashboard</a>
      <a href="smart_download.php" class="btn-primary" style="background:linear-gradient(135deg,#1565c0,#0d47a1)">📥 Download PDF</a>
      <a href="index.php" class="btn-primary">🌱 Full New Plan</a>
    </div>
  </div>

  <div style="margin-top:16px;background:#f8faf8;border-radius:12px;padding:12px 16px;font-size:.78rem;color:#888;">
    ⚡ <strong>0.1 credit</strong> was deducted for this Smart Reality Check. Remaining: <strong><?= number_format(round((float)$user['usage_limit'] - (float)$user['usage_count'] - SMART_CREDIT_COST, 2), 2) ?> credits</strong>
  </div>
</div>

<?php endif; ?>

</div><!-- /.s-wrap -->

</main>

<script>
function switchTab(which) {
  document.getElementById('sourceInput').value = which;
  document.getElementById('panelHistory').style.display = which === 'history' ? '' : 'none';
  document.getElementById('panelManual').style.display = which === 'manual' ? '' : 'none';
  document.getElementById('panelPdf').style.display = which === 'pdf' ? '' : 'none';
  
  document.getElementById('tabHistory').classList.toggle('active', which === 'history');
  document.getElementById('tabManual').classList.toggle('active', which === 'manual');
  document.getElementById('tabPdf').classList.toggle('active', which === 'pdf');
}
// Show loading overlay on form submit (steps 1 & 2)
document.querySelectorAll('form').forEach(function(f){
  f.addEventListener('submit', function(){
    if(f.id !== 'aiForm') {
        document.getElementById('loadOverlay').classList.add('show');
    }
  });
});
</script>
<?php include 'partials/footer.php'; ?>
</body>
</html>

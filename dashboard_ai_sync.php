<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';
require_once 'config.php';
require_once 'lib/gemini.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

define('AI_SYNC_CREDIT_COST', 0.1);

$user_id = (int)$_SESSION['user_id'];
$crop_id = isset($_GET['crop_id']) ? (int)$_GET['crop_id'] : null;
$source = isset($_GET['source']) ? $_GET['source'] : 'smart'; // 'smart' or 'farm'

if (!$crop_id) {
    echo json_encode(['success' => false, 'error' => 'No crop selected']); exit;
}

try {
    // 0. Credit Check
    $stmt_u = $pdo->prepare("SELECT usage_limit, usage_count FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $user = $stmt_u->fetch();
    if (!$user || ((float)$user['usage_limit'] - (float)$user['usage_count']) < AI_SYNC_CREDIT_COST) {
        throw new Exception("Insufficient credits (0.1cr needed). Please top up.");
    }

    $crop = "";
    $location = "";
    $stage = "";
    $season = "";
    $sowing_date = "";
    $today = date('Y-m-d');
    $smart_report_id = $crop_id;
    $farm_report_id = null;

    if ($source === 'farm') {
        // --- AUTO-INITIALIZE MANAGEMENT FROM FARM PLANNER ---
        $stmt = $pdo->prepare("SELECT * FROM farm_reports WHERE id = ? AND user_id = ?");
        $stmt->execute([$crop_id, $user_id]);
        $farm = $stmt->fetch();
        if (!$farm) throw new Exception("Farm report not found");

        $farm_report_id = $crop_id;
        $crop = $farm['crop'];
        $location = $farm['location'];
        $season = $farm['season'] ?? '';
        $stage = "Initial/Seedling Stage";

        // Create a bridge smart_report entry if none exists for this farm report
        $stmt_check = $pdo->prepare("SELECT id FROM smart_reports WHERE base_report_id = ? AND user_id = ? LIMIT 1");
        $stmt_check->execute([$crop_id, $user_id]);
        $existing_bridge = $stmt_check->fetch();

        if ($existing_bridge) {
            $smart_report_id = $existing_bridge['id'];
        } else {
            $ins = $pdo->prepare("INSERT INTO smart_reports 
                (user_id, base_report_id, detected_stage, crop, location, updated_report_data, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $user_id,
                $crop_id,
                $stage,
                $crop,
                $location,
                "Initial AI Management Setup based on Farm Planner data.",
                date('Y-m-d H:i:s')
            ]);
            $smart_report_id = $pdo->lastInsertId();
        }
    } else {
        // --- WEATHER-AWARE ADJUSTMENT FOR EXISTING SMART REPORT ---
        $stmt = $pdo->prepare("SELECT * FROM smart_reports WHERE id = ? AND user_id = ?");
        $stmt->execute([$crop_id, $user_id]);
        $smart = $stmt->fetch();
        if (!$smart) throw new Exception("Smart report not found");

        $farm_report_id = $smart['base_report_id'] ?? null;
        $crop = $smart['crop'];
        $location = $smart['location'];
        $stage = $smart['detected_stage'];
        $sowing_date = $smart['sowing_date'] ?? '';
        
        if ($farm_report_id) {
            $stmt_fr = $pdo->prepare("SELECT season FROM farm_reports WHERE id = ?");
            $stmt_fr->execute([$farm_report_id]);
            $season = $stmt_fr->fetchColumn() ?: '';
        }
    }

    // Get Historical Context (Initial Plan + Previous Smart Check)
    $hi_context = get_crop_ai_context($pdo, $user_id, $crop, ($source === 'smart' ? ($smart['base_report_id'] ?? null) : $crop_id));

    // --- FETCH LIVE WEATHER FROM OPENMETEO ---
    $weather_context = "Live weather data unavailable. Please use general climate knowledge for $location.";
    try {
        if (!empty($location)) {
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $geo_url = "https://geocoding-api.open-meteo.com/v1/search?name=" . urlencode($location) . "&count=1&language=en&format=json";
            $geo_json = @file_get_contents($geo_url, false, $ctx);
            
            if ($geo_json) {
                $geo_data = json_decode($geo_json, true);
                if (!empty($geo_data['results'][0])) {
                    $lat = $geo_data['results'][0]['latitude'];
                    $lon = $geo_data['results'][0]['longitude'];
                    
                    $weather_url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&daily=temperature_2m_max,temperature_2m_min,precipitation_sum&timezone=auto";
                    $weather_json = @file_get_contents($weather_url, false, $ctx);
                    
                    if ($weather_json) {
                        $w_data = json_decode($weather_json, true);
                        if (!empty($w_data['daily'])) {
                            $weather_context = "=== 7-DAY LIVE WEATHER FORECAST FOR " . strtoupper($location) . " ===\n";
                            for ($i = 0; $i < 7; $i++) {
                                if (!isset($w_data['daily']['time'][$i])) break;
                                $date = $w_data['daily']['time'][$i];
                                $tmax = $w_data['daily']['temperature_2m_max'][$i];
                                $tmin = $w_data['daily']['temperature_2m_min'][$i];
                                $rain = $w_data['daily']['precipitation_sum'][$i];
                                $weather_context .= "- {$date}: Max {$tmax}°C, Min {$tmin}°C, Rain {$rain}mm\n";
                            }
                            $weather_context .= "CRITICAL INSTRUCTION: You MUST use the exact rainfall and temperature figures above to schedule or delay tasks.\n";
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Fallback gracefully on error
    }

    // Unified Prompt: Always generate a fresh 7-day task list
    $prompt = "You are a smart Indian agricultural AI. 
    Provide weather-aware updates and a fresh task list for:
    CROP: $crop | STAGE: $stage | LOCATION: $location

    {$hi_context}
    
    {$weather_context}
    
    TASK (CRITICAL):
    1. Summarize the requested current weather situation in 1-2 short sentences.
    2. 3-5 alerts/news about $crop.
    3. Generate exactly 7-10 specific daily farm tasks (irrigation, fertilizer, spraying, sprinkling, other) for the upcoming lifecycle, explicitly citing the weather context if applicable (e.g., 'Delay irrigation due to 12mm rain on Tuesday').
    4. Provide the EXACT calendar date (`task_date`: 'YYYY-MM-DD') for each task, matching the crop's natural life timeline based on the SOWING DATE and SEASON. Do NOT just schedule everything for tomorrow.
    5. Evaluate current crop health and provide a 0-100 `health_score`, plus generate the 5 AI tracking models using the context.
    CRITICAL: DO NOT repeat any tasks that are already marked as 'Completed' or 'Done' in the Historical Context unless they are naturally recurring (like regular watering). If a one-time task (like initial sowing or specific fertilizer drops) is completed, advance to the next management stage. Prioritize catching up on any 'Missed/Overdue' tasks.
    
    RESPONSE STRUCTURE:
    {
      \"weather\": \"...\",
      \"news\": [ {\"title\":\"...\", \"content\":\"...\"} ],
      \"health_score\": 85,
      \"key_findings\": \"Focus on weather and alert impact...\",
      \"models\": {
        \"growth\": {\"current_stage\": \"...\", \"next_stage_date\": \"YYYY-MM-DD\", \"growth_delay_days\": 0},
        \"health\": {\"health_score\": 85, \"risk_level\": \"Low/Medium/High\"},
        \"disease\": {\"risk_pct\": 20, \"possible_disease\": \"None\"},
        \"irrigation\": {\"required_mm\": 0, \"recommended_time\": \"...\"},
        \"yield\": {\"expected_yield_tons_per_acre\": 0.0}
      },
      \"tasks\": [ {\"title\":\"...\", \"description\":\"...\", \"category\":\"irrigation/fertilizer/spraying/sprinkling/other\", \"priority\":\"high/medium/low\", \"task_date\": \"YYYY-MM-DD\"} ]
    }
    ONLY return JSON.";

    $response = run_gemini_stage($prompt);
    if (strncmp($response, '[AI_ERROR]', 10) === 0) throw new Exception(substr($response, 10));

    $response = preg_replace('/^```json|```$/m', '', $response);
    $data = json_decode(trim($response), true);
    if (!$data) throw new Exception("Invalid AI response. Please try again.");

    // 1. Deduct Credit
    $pdo->prepare("UPDATE users SET usage_count = usage_count + ? WHERE id = ?")
        ->execute([AI_SYNC_CREDIT_COST, $user_id]);

    // 1.5 Update Smart Report Weather Info
    if (!empty($data['weather'])) {
        $pdo->prepare("UPDATE smart_reports SET weather_info = ? WHERE id = ?")
            ->execute([$data['weather'], $smart_report_id]);
    }

    // 2. Clear old news and save new news
    $pdo->prepare("DELETE FROM crop_news WHERE crop_name = ?")->execute([$crop]);
    $ins_news = $pdo->prepare("INSERT INTO crop_news (crop_name, title, content, created_at) VALUES (?, ?, ?, ?)");
    foreach (($data['news'] ?? []) as $n) {
        $ins_news->execute([$crop, $n['title'] ?? 'Alert', $n['content'] ?? '', $today]);
    }

    // Save Historical Snapshot
    if (isset($data['health_score'])) {
        $model_predictions = null;
        if (isset($data['models'])) {
            $model_predictions = json_encode($data['models']);
        }
        
        // Database Auto-Update: Ensure model_predictions column exists 
        try { $pdo->exec("ALTER TABLE crop_health_snapshots ADD COLUMN IF NOT EXISTS model_predictions TEXT NULL"); } catch (Exception $e) {}

        $snap_ins = $pdo->prepare("INSERT INTO crop_health_snapshots 
            (user_id, farm_report_id, smart_report_id, crop, snapshot_date, detected_stage, health_score, key_findings, model_predictions) 
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?)");
        $snap_ins->execute([
            $user_id,
            $farm_report_id,
            $smart_report_id,
            $crop,
            $stage,
            $data['health_score'],
            $data['key_findings'] ?? '',
            $model_predictions
        ]);
    }

    // 3. Update Tasks (Always refresh the 7-day window)
    if (isset($data['tasks']) && is_array($data['tasks'])) {
        // We only clear PENDING upcoming tasks to avoid deleting history and overdue tasks
        $pdo->prepare("DELETE FROM crop_tasks WHERE smart_report_id = ? AND status = 'pending' AND due_date >= CURDATE()")->execute([$smart_report_id]);
        
        $task_ins = $pdo->prepare("INSERT INTO crop_tasks (user_id, smart_report_id, title, description, category, priority, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['tasks'] as $t) {
            $cat = strtolower(trim($t['category'] ?? 'other'));
            $prio = strtolower(trim($t['priority'] ?? 'medium'));
            
            if (!empty($t['task_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['task_date'])) {
                $due = $t['task_date'];
            } else {
                $due = date('Y-m-d', strtotime('+' . (int)($t['days_from_now'] ?? 0) . ' days'));
            }
            
            $task_ins->execute([$user_id, $smart_report_id, $t['title'] ?? 'Task', $t['description'] ?? '', $cat, $prio, $due]);
        }
    }

    echo json_encode(['success' => true, 'weather' => $data['weather'] ?? 'Unknown', 'smart_report_id' => $smart_report_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * InfoCrop AI — Multi-Platform Key Rotation Engine
 *
 * Supports: Gemini, OpenAI, DeepSeek, Groq, OpenRouter, Together,
 *           Moonshot/Kimi, xAI/Grok, Cohere, and any OpenAI-compatible API.
 *
 * ┌──────────────────────────────────────────────────────────┐
 * │  HOW IT WORKS                                            │
 * │  1. All active keys are fetched from the database.       │
 * │  2. Keys exhausted today are filtered out.               │
 * │  3. Remaining keys are SHUFFLED (randomized).            │
 * │  4. Each key is tried in random order.                   │
 * │  5. If a key fails (429, 401, 403, 503, timeout, etc.),  │
 * │     it is SKIPPED and the next random key is tried.      │
 * │  6. 429/401/403 errors mark the key as "exhausted today".│
 * │  7. Only if ALL keys fail does the user see an error.    │
 * └──────────────────────────────────────────────────────────┘
 */

require_once __DIR__ . '/../db.php';

// Fallback: if db.php doesn't define pdo_ping (old version on server)
if (!function_exists('pdo_ping')) {
    function pdo_ping() {
        global $pdo;
        try { $pdo->query('SELECT 1'); }
        catch (Exception $e) {
            // Attempt reconnect using get_pdo_connection if available
            if (function_exists('get_pdo_connection')) {
                $pdo = get_pdo_connection();
            }
        }
    }
}

// ── One-time migration guard (runs only once per PHP process) ────
function _ensure_api_keys_table() {
    static $done = false;
    if ($done) return;
    global $pdo;

    $pdo->exec("CREATE TABLE IF NOT EXISTS gemini_api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(100) NOT NULL DEFAULT 'Key',
        api_key VARCHAR(255) NOT NULL,
        platform VARCHAR(50) NOT NULL DEFAULT 'gemini',
        model VARCHAR(100) NOT NULL DEFAULT 'gemini-1.5-flash',
        base_url VARCHAR(255) NULL DEFAULT NULL,
        exhausted_date DATE NULL DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        calls_today INT NOT NULL DEFAULT 0,
        last_call_date DATE NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Add columns to existing tables (safe upgrade path)
    try {
        $cols = $pdo->query("DESCRIBE gemini_api_keys")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('platform', $cols)) {
            $pdo->exec("ALTER TABLE gemini_api_keys ADD COLUMN platform VARCHAR(50) NOT NULL DEFAULT 'gemini' AFTER api_key");
        }
        if (!in_array('base_url', $cols)) {
            $pdo->exec("ALTER TABLE gemini_api_keys ADD COLUMN base_url VARCHAR(255) NULL DEFAULT NULL AFTER model");
        }
    } catch (Exception $e) { /* ignore if already exists */ }

    $done = true;
}

/**
 * Main entry point — call this from index.php / smart_planner.php.
 * Returns the AI response text, or an [AI_ERROR] message.
 */
function run_gemini_stage($prompt, $files = []) {
    global $pdo;

    _ensure_api_keys_table();

    $today = date('Y-m-d');

    // ── 1. Fetch all active keys ──────────────────────────────────
    $keys = $pdo->query(
        "SELECT * FROM gemini_api_keys WHERE is_active = 1 ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: legacy config constant
    if (empty($keys)) {
        $legacyKey   = defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : '';
        $legacyModel = defined('GEMINI_MODEL')   ? GEMINI_MODEL   : 'gemini-1.5-flash';
        if (empty($legacyKey)) {
            return "[AI_ERROR] System is unable to process requests currently. Please contact the administrator.";
        }
        $keys = [['id' => 0, 'api_key' => $legacyKey, 'platform' => 'gemini', 'model' => $legacyModel,
                   'base_url' => null, 'exhausted_date' => null, 'calls_today' => 0, 'last_call_date' => null]];
    }

    // ── 2. Filter out exhausted-today keys ────────────────────────
    $available = [];
    foreach ($keys as $k) {
        if ($k['exhausted_date'] !== $today) {
            $available[] = $k;
        }
    }

    if (empty($available)) {
        return "[AI_ERROR] The AI engine has reached its daily limit or is experiencing high demand. Please contact the administrator.";
    }

    // ── 3. SHUFFLE for random allocation across users ─────────────
    shuffle($available);

    // ── 4. Try each key — NEVER stop until all are tried ──────────
    $lastError = '';
    foreach ($available as $keyRow) {

        // Dispatch call based on platform
        $platform = $keyRow['platform'] ?? 'gemini';
        if ($platform === 'gemini') {
            $result = _call_gemini($keyRow['api_key'], $keyRow['model'], $prompt, $files);
        } else {
            $result = _call_openai_compatible($keyRow, $prompt);
        }

        // ── SUCCESS: Update counter and return response ────────────
        if ($result['status'] === 'ok') {
            if ($keyRow['id'] > 0) {
                _safe_db_execute(
                    "UPDATE gemini_api_keys SET 
                        calls_today = CASE WHEN last_call_date = ? THEN calls_today + 1 ELSE 1 END,
                        last_call_date = ?, 
                        exhausted_date = NULL 
                     WHERE id = ?",
                    [$today, $today, $keyRow['id']]
                );
            }
            return $result['text'];
        }

        // ── FAILURE: Mark exhausted if quota/expired, then try next ─
        if ($keyRow['id'] > 0) {
            // Quota (429), Unauthorized (401), Forbidden/Leaked (403) → mark exhausted
            if (in_array($result['status'], ['quota', 'expired'])) {
                _safe_db_execute(
                    "UPDATE gemini_api_keys SET exhausted_date = ? WHERE id = ?",
                    [$today, $keyRow['id']]
                );
            }
        }
        $lastError = $result['text'];
        // Continue to next key — NEVER return error here
    }

    // All keys failed
    return $lastError ?: "[AI_ERROR] System is overloaded and unable to process the request. Please contact the administrator.";
}

/**
 * Execute a DB query SAFELY after a long API call.
 * Handles "MySQL server has gone away" by reconnecting and retrying once.
 */
function _safe_db_execute($sql, $params = []) {
    global $pdo;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $pdo->prepare($sql)->execute($params);
            return true;
        } catch (Exception $e) {
            // First attempt failed — reconnect and retry
            if ($attempt === 0) {
                try {
                    if (function_exists('get_pdo_connection')) {
                        $pdo = get_pdo_connection();
                    } else {
                        // Last-resort: re-include db.php
                        $pdo = null;
                        require __DIR__ . '/../db.php';
                    }
                } catch (Exception $reconErr) {
                    // Reconnect also failed — give up silently
                    return false;
                }
            }
        }
    }
    return false;
}


/* ══════════════════════════════════════════════════════════════════
 *  PLATFORM CALLERS
 * ══════════════════════════════════════════════════════════════════ */

/**
 * OpenAI-Compatible Caller
 * Works for: OpenAI, DeepSeek, Groq, OpenRouter, Together, Moonshot/Kimi, xAI/Grok, Cohere
 */
function _call_openai_compatible($keyRow, $prompt) {
    $apiKey    = $keyRow['api_key'];
    $model     = $keyRow['model'] ?: 'gpt-3.5-turbo';
    $platform  = $keyRow['platform'];

    // ── Determine endpoint URL ────────────────────────────────────
    $url = $keyRow['base_url'];
    if (!$url) {
        $endpoints = [
            'openai'     => "https://api.openai.com/v1/chat/completions",
            'deepseek'   => "https://api.deepseek.com/chat/completions",
            'openrouter' => "https://openrouter.ai/api/v1/chat/completions",
            'groq'       => "https://api.groq.com/openai/v1/chat/completions",
            'together'   => "https://api.together.xyz/v1/chat/completions",
            'moonshot'   => "https://api.moonshot.ai/v1/chat/completions",
            'xai'        => "https://api.x.ai/v1/chat/completions",
            'cohere'     => "https://api.cohere.com/v2/chat/completions",
            'meta'       => "https://api.llama.com/v1/chat/completions",
        ];
        $url = $endpoints[$platform] ?? "https://api.openai.com/v1/chat/completions";
    } else {
        if (strpos($url, '/chat/completions') === false) {
            $url = rtrim($url, '/') . '/chat/completions';
        }
    }

    $systemInstruction = "You are an expert Indian agricultural advisor with deep knowledge of crop science, soil management, irrigation, pest control, farm economics, and Indian government schemes. Provide structured, actionable advice with headings and bullet points. Be specific to the farmer's region.";

    $data = [
        "model"    => $model,
        "messages" => [
            ["role" => "system", "content" => $systemInstruction],
            ["role" => "user",   "content" => $prompt]
        ],
        "temperature" => 0.4,
        "top_p"       => 0.9
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];
    if ($platform === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://infocropai.free.nf';
        $headers[] = 'X-Title: InfoCrop AI';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 'error', 'text' => "[AI_ERROR] ($platform) Connection failed: $err"];
    }
    curl_close($ch);

    // ── HTTP 429 = Rate limit / Quota exceeded ────────────────────
    if ($httpCode === 429) return ['status' => 'quota', 'text' => ''];

    // ── HTTP 401/403 = Key expired, revoked, or leaked ────────────
    if ($httpCode === 401 || $httpCode === 403) {
        return ['status' => 'expired', 'text' => "[AI_ERROR] ($platform) API key invalid or expired."];
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $result['error']['message'] ?? ($result['error'] ?? 'Unknown Error');
        if (is_array($msg)) $msg = json_encode($msg);
        return ['status' => 'error', 'text' => "[AI_ERROR] ($platform) $msg"];
    }

    if (!empty($result['choices'][0]['message']['content'])) {
        return ['status' => 'ok', 'text' => trim($result['choices'][0]['message']['content'])];
    }

    return ['status' => 'error', 'text' => "[AI_ERROR] ($platform) Unexpected response format."];
}

/**
 * Google Gemini REST Caller
 * Returns ['status' => 'ok'|'quota'|'expired'|'error', 'text' => string]
 */
function _call_gemini($apiKey, $model, $prompt, $files = []) {
    $model = $model ?: 'gemini-1.5-flash';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $systemInstruction = "You are an expert Indian agricultural advisor with deep knowledge of crop science, soil management, irrigation, pest control, post-harvest handling, and farm economics. You specialize in Indian farming conditions, regional climate patterns, government schemes (MSP, KCC, PMFBY, PM-KISAN), APMC mandi markets, and ICAR recommendations. Always provide structured, detailed, actionable advice formatted clearly with headings, bullet points, and tables. Your answers must be specific to the farmer's region and data — never give generic advice. Include realistic financial estimates in Indian Rupees. Flag any assumptions you make. Where data is uncertain (e.g. market forecasts), say so clearly with the word 'Estimated' or 'Probabilistic'.";

    $contents = [
        ["parts" => [["text" => $prompt]]]
    ];

    if (!empty($files) && is_array($files)) {
        foreach ($files as $f) {
            if (!empty($f['data']) && !empty($f['mime_type'])) {
                $contents[0]['parts'][] = [
                    "inline_data" => [
                        "mime_type" => $f['mime_type'],
                        "data"      => $f['data']
                    ]
                ];
            }
        }
    }

    $data = [
        "system_instruction" => ["parts" => [["text" => $systemInstruction]]],
        "contents"           => $contents,
        "generationConfig"   => [
            "temperature"     => 0.4,
            "topK"            => 40,
            "topP"            => 0.92,
            "maxOutputTokens" => 8192,
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 'error', 'text' => "[AI_ERROR] (gemini) Connection failed: $err"];
    }
    curl_close($ch);

    // 429 = Rate limit → mark exhausted, try next key
    if ($httpCode === 429) {
        return ['status' => 'quota', 'text' => ''];
    }
    // 401/403 = Leaked, revoked, or invalid key → mark exhausted, try next key
    if ($httpCode === 401 || $httpCode === 403) {
        return ['status' => 'expired', 'text' => '[AI_ERROR] (gemini) API key invalid, leaked, or expired.'];
    }
    // 503 = Server overloaded → still an error, try next key
    if ($httpCode === 503) {
        return ['status' => 'error', 'text' => '[AI_ERROR] (gemini) Server overloaded.'];
    }
    // 400 = Bad request (usually prompt issue, but still try next key)
    if ($httpCode === 400) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? 'Bad Request';
        return ['status' => 'error', 'text' => "[AI_ERROR] (gemini) $msg"];
    }
    // Any other non-200
    if ($httpCode !== 200) {
        return ['status' => 'error', 'text' => "[AI_ERROR] (gemini) HTTP $httpCode"];
    }

    $result = json_decode($response, true);
    if (!empty($result['candidates'][0]['content']['parts'][0]['text'])) {
        return ['status' => 'ok',
                'text'   => trim($result['candidates'][0]['content']['parts'][0]['text'])];
    }
    if (!empty($result['candidates'][0]['finishReason']) &&
        $result['candidates'][0]['finishReason'] === 'SAFETY') {
        return ['status' => 'error',
                'text'   => "[AI_ERROR] The AI response was blocked for safety reasons. Please rephrase your inputs."];
    }

    return ['status' => 'error',
            'text'   => "[AI_ERROR] (gemini) Unexpected response format."];
}


/**
 * Replace placeholders in prompt with actual data.
 */
function format_prompt($prompt_template, $data) {
    $data['date'] = date('F d, Y');
    foreach ($data as $key => $value) {
        if (is_array($value)) continue;
        $prompt_template = str_replace("{" . $key . "}", $value ?: 'N/A', $prompt_template);
    }
    return preg_replace('/\{[a-z_]+\}/', 'N/A', $prompt_template);
}

/**
 * Retrieves a compressed summary of all previous AI advice and reality checks
 * for a specific crop to provide context for the next AI generation.
 */
function get_crop_ai_context($pdo, $user_id, $crop_name, $base_report_id = null) {
    if (empty($crop_name)) return '';

    $context = "=== HISTORICAL AI CONTEXT & RECOMMENDATIONS ===\n";
    $found_any = false;

    // 1. Get Initial Farm Plan Data
    if ($base_report_id) {
        $stmt = $pdo->prepare("SELECT report_data FROM farm_reports WHERE id = ? AND user_id = ?");
        $stmt->execute([$base_report_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("SELECT report_data FROM farm_reports WHERE LOWER(crop) = LOWER(?) AND user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$crop_name, $user_id]);
    }
    
    $farm = $stmt->fetch();
    if ($farm) {
        $data = json_decode($farm['report_data'], true) ?? [];
        $found_any = true;
        $context .= "[Initial Plan Recommendations]:\n";
        
        // Extract key AI advice from stages
        $keys = [
            'gemini_crop_recommendation' => 'Stage 1 (Advisory)',
            'gemini_seed_advice' => 'Stage 2 (Seed/Variety)',
            'gemini_soil_health' => 'Stage 3 (Soil/Fertilizer)',
            'gemini_water_management' => 'Stage 4 (Irrigation)',
            'gemini_pest_management' => 'Stage 5 (Pest/Disease)',
            'gemini_farming_schedule' => 'Stage 8 (Calendar)'
        ];

        foreach ($keys as $k => $label) {
            if (!empty($data[$k])) {
                $raw = $data[$k];
                // Compress: strip headers and extra spaces
                $raw = preg_replace('/^#{1,4}\s+/m', '', $raw);
                $raw = str_replace('**', '', $raw);
                $summary = mb_substr(trim($raw), 0, 300);
                $context .= "- {$label}: {$summary}...\n";
            }
        }
    }

    // 2. Get Latest Smart Reality Check
    $stmt = $pdo->prepare("SELECT id, updated_report_data, detected_stage, created_at FROM smart_reports WHERE LOWER(crop) = LOWER(?) AND user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$crop_name, $user_id]);
    $smart = $stmt->fetch();
    $smart_report_id = null;
    if ($smart) {
        $smart_report_id = $smart['id'];
        $found_any = true;
        $context .= "\n[Latest Reality Check ({$smart['created_at']})]:\n";
        $context .= "Detected Stage: {$smart['detected_stage']}\n";
        $raw = preg_replace('/^#{1,4}\s+/m', '', $smart['updated_report_data']);
        $raw = str_replace('**', '', $raw);
        $summary = mb_substr(trim($raw), 0, 500);
        $context .= "Summary: {$summary}...\n";
    }

    // 3. Get Task History Context
    if ($smart_report_id) {
        $stmt_tasks = $pdo->prepare("SELECT title, status, due_date FROM crop_tasks WHERE smart_report_id = ? AND user_id = ? ORDER BY due_date ASC");
        $stmt_tasks->execute([$smart_report_id, $user_id]);
        $tasks = $stmt_tasks->fetchAll();
        
        if ($tasks) {
            $context .= "\n[Recent Task History]:\n";
            $completed = [];
            $overdue = [];
            $upcoming = [];
            
            $today = date('Y-m-d');
            
            foreach ($tasks as $t) {
                if ($t['status'] === 'completed') {
                    $completed[] = "{$t['title']} (Done)";
                } else if ($t['status'] === 'pending' && $t['due_date'] < $today) {
                    $overdue[] = "{$t['title']} (Missed/Overdue since {$t['due_date']})";
                } else {
                    $upcoming[] = "{$t['title']} (Due: {$t['due_date']})";
                }
            }
            
            if (!empty($completed)) {
                $context .= "Completed: " . implode(", ", $completed) . "\n";
            }
            if (!empty($overdue)) {
                $context .= "Overdue/Missed: " . implode(", ", $overdue) . "\n";
            }
            if (!empty($upcoming)) {
                $context .= "Upcoming: " . implode(", ", $upcoming) . "\n";
            }
            
            $context .= "CRITICAL INSTRUCTION: Analyze the completed vs missed tasks. Generate the next 7-day schedule to catch up on missed critical tasks and advance the management based on completed tasks.\n";
        }
    }

    // 4. Get Tracked Data (Tasks & Expenses) for this crop
    // First, find all smart_report_ids for this crop_name
    $stmt_ids = $pdo->prepare("SELECT id FROM smart_reports WHERE LOWER(crop) = LOWER(?) AND user_id = ?");
    $stmt_ids->execute([$crop_name, $user_id]);
    $s_ids = $stmt_ids->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($s_ids)) {
        $in_clause = str_repeat('?,', count($s_ids) - 1) . '?';
        
        // Expenses
        $stmt_exp = $pdo->prepare("SELECT category, SUM(amount) as cost FROM farm_expenses WHERE smart_report_id IN ($in_clause) GROUP BY category");
        $stmt_exp->execute($s_ids);
        $expenses = $stmt_exp->fetchAll();
        
        if (!empty($expenses)) {
            $found_any = true;
            $context .= "\n[Farmer's Logged Expenses So Far]:\n";
            foreach ($expenses as $ex) {
                $context .= "- {$ex['category']}: Rs. {$ex['cost']}\n";
            }
        }

        // Pending/Overdue Tasks
        $stmt_ts = $pdo->prepare("SELECT title, due_date, status FROM crop_tasks WHERE smart_report_id IN ($in_clause) AND status = 'pending' ORDER BY due_date ASC LIMIT 5");
        $stmt_ts->execute($s_ids);
        $tasks = $stmt_ts->fetchAll();
        
        if (!empty($tasks)) {
            $found_any = true;
            $context .= "\n[Current Pending/Overdue Tasks]:\n";
            $today = date('Y-m-d');
            foreach ($tasks as $t) {
                $status = ($t['due_date'] < $today) ? "OVERDUE" : "Upcoming";
                $context .= "- {$t['title']} (Due: {$t['due_date']} - $status)\n";
            }
        }
    }

    return $found_any ? $context . "\n" : "";
}

function render_ai_html($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $text = preg_replace('/^###\s+(.+)$/m', '<h4 class="ai-h4">$1</h4>', $text);
    $text = preg_replace('/^##\s+(.+)$/m',  '<h3 class="ai-h3">$1</h3>', $text);
    $text = preg_replace('/^#\s+(.+)$/m',   '<h2 class="ai-h2">$1</h2>', $text);

    $text = preg_replace('/^\d+\.\s+\*\*(.+?)\*\*\s*$/m', '<h4 class="ai-h4">$1</h4>', $text);

    $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/',     '<strong>$1</strong>',          $text);
    $text = preg_replace('/\*(.+?)\*/',         '<em>$1</em>',                  $text);

    $text = preg_replace_callback(
        '/^(\|.+\|[ \t]*\n)((?:\|[-:| ]+\|[ \t]*\n))?((?:\|.+\|[ \t]*\n?)+)/m',
        function($m) {
            $header_row = trim($m[1]);
            $body_rows  = trim($m[3]);
            $headers = array_map('trim', explode('|', trim($header_row, '|')));
            $html  = '<div class="ai-table-wrap"><table class="ai-table"><thead><tr>';
            foreach ($headers as $h) $html .= '<th>' . $h . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach (explode("\n", $body_rows) as $row) {
                $row = trim($row);
                if ($row === '' || preg_match('/^\|[-:| ]+\|$/', $row)) continue;
                $cells = array_map('trim', explode('|', trim($row, '|')));
                $html .= '<tr>';
                foreach ($cells as $c) $html .= '<td>' . $c . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
            return $html;
        },
        $text
    );

    $text = preg_replace_callback(
        '/(^[ \t]*[-*]\s+\[[ x]\]\s+.+(?:\n[ \t]*[-*]\s+\[[ x]\]\s+.+)*)/m',
        function($m) {
            $items = preg_split('/\n/', trim($m[0]));
            $html = '<ul class="ai-checklist">';
            foreach ($items as $item) {
                $checked = preg_match('/\[x\]/i', $item);
                $item = preg_replace('/^[ \t]*[-*]\s+\[[ x]\]\s+/i', '', $item);
                $html .= '<li class="' . ($checked ? 'checked' : '') . '">'
                       . '<span class="check-icon">' . ($checked ? '✅' : '☐') . '</span> '
                       . $item . '</li>';
            }
            return $html . '</ul>';
        },
        $text
    );

    $text = preg_replace_callback(
        '/(^[ \t]*[-*]\s+.+(?:\n[ \t]*[-*]\s+.+)*)/m',
        function($m) {
            $items = preg_split('/\n/', trim($m[0]));
            $html = '<ul class="ai-ul">';
            foreach ($items as $item) {
                $item = preg_replace('/^[ \t]*[-*]\s+/', '', $item);
                $html .= '<li>' . $item . '</li>';
            }
            return $html . '</ul>';
        },
        $text
    );

    $text = preg_replace_callback(
        '/(^[ \t]*\d+\.\s+.+(?:\n[ \t]*\d+\.\s+.+)*)/m',
        function($m) {
            $items = preg_split('/\n/', trim($m[0]));
            $html = '<ol class="ai-ol">';
            foreach ($items as $item) {
                $item = preg_replace('/^[ \t]*\d+\.\s+/', '', $item);
                $html .= '<li>' . $item . '</li>';
            }
            return $html . '</ol>';
        },
        $text
    );

    $blocks = preg_split('/\n{2,}/', $text);
    $html = '';
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;
        if (preg_match('/^<(h[2-4]|ul|ol|div|table)/i', $block)) {
            $html .= $block;
        } else {
            $html .= '<p class="ai-p">' . str_replace("\n", '<br>', $block) . '</p>';
        }
    }

    return $html;
}

/**
 * Translates an array of user inputs to English using Gemini API.
 * This ensures the AI model processes inputs effectively without language barriers.
 */
function translate_inputs_to_english($inputs) {
    if (empty($inputs)) return $inputs;

    $to_translate = [];
    $exclude_words = ['Select', 'Unknown', 'None'];

    foreach ($inputs as $key => $val) {
        if (is_string($val)) {
            $val = trim($val);
            if ($val !== '' && !is_numeric($val) && !in_array($val, $exclude_words, true)) {
                $to_translate[$key] = $val;
            }
        }
    }

    if (empty($to_translate)) {
        return $inputs;
    }

    $json_in = json_encode($to_translate, JSON_UNESCAPED_UNICODE);
    
    $prompt = "You are a professional agricultural translator. I will provide a JSON object of user inputs. 
If any value is NOT in English (e.g., Hindi, Marathi, Gujarati, etc.), translate it accurately to English. 
If it is already in English, leave it as is. 
Return ONLY valid JSON with the exact same keys and the translated values. NEVER return markdown formatting like ```json, ONLY the raw JSON object string.

Input JSON:
" . $json_in;

    $response = run_gemini_stage($prompt);

    if (strncmp($response, '[AI_ERROR]', 10) === 0) {
        // Fallback: return original inputs if AI fails
        return $inputs;
    }

    // Attempt to parse JSON
    $response = preg_replace('/^```json\s*|\s*```$/i', '', trim($response));
    $translated = json_decode($response, true);

    if (is_array($translated)) {
        foreach ($translated as $key => $val) {
            if (isset($inputs[$key])) {
                $inputs[$key] = $val;
            }
        }
    }

    return $inputs;
}
?>

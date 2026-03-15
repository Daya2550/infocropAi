<?php
// signup.php
session_start();
require_once 'db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($phone) || empty($password)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (isset($_COOKIE['ic_reg_lock'])) {
        $error = "Security Notice: You have already created an account from this browser.";
    } else {
        $fingerprint = $_POST['fingerprint'] ?? '';
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'];
        $ip = $_SERVER['REMOTE_ADDR'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($forwarded[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }

        $ref_token = $_GET['ref'] ?? '';
        $final_limit = 1;
        $referral_id = null;

        if (!empty($ref_token)) {
            $ref_stmt = $pdo->prepare("SELECT id, user_usage_limit FROM referral_links WHERE token = ? AND is_used = 0");
            $ref_stmt->execute([$ref_token]);
            $ref_data = $ref_stmt->fetch();
            if ($ref_data) {
                $final_limit = $ref_data['user_usage_limit'];
                $referral_id = $ref_data['id'];
            }
        }

        $signup_location = "Unknown";
        try {
            if (!empty($lat) && !empty($lng) && is_numeric($lat) && is_numeric($lng)) {
                $geoUrl = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&zoom=10&addressdetails=1";
                $ctx = stream_context_create(['http' => ['header' => "User-Agent: InfoCropAI/1.0\r\n", 'timeout' => 5]]);
                $geoResp = @file_get_contents($geoUrl, false, $ctx);
                if ($geoResp) {
                    $geoData = json_decode($geoResp, true);
                    $addr = $geoData['address'] ?? [];
                    $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['county'] ?? '';
                    $state = $addr['state'] ?? $addr['region'] ?? '';
                    if ($city || $state) {
                        $signup_location = trim("$city, $state", ', ');
                    }
                }
            }
            if ($signup_location === 'Unknown') {
                $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,city,regionName,country");
                $geo_data = json_decode($geo, true);
                if ($geo_data && $geo_data['status'] === 'success') {
                    $signup_location = $geo_data['city'] . ", " . $geo_data['regionName'];
                }
            }
        } catch (Exception $e) {}

        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            $error = "This phone number is already registered.";
        } else {
            $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, phone, password, signup_ip, latitude, longitude, signup_location, device_fingerprint, user_agent, usage_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $phone, $hashed_pw, $ip, $lat, $lng, $signup_location, $fingerprint, $ua, $final_limit])) {
                if ($referral_id) {
                    $pdo->prepare("UPDATE referral_links SET is_used = 1 WHERE id = ?")->execute([$referral_id]);
                }
                setcookie('ic_reg_lock', '1', time() + (86400 * 365), "/");
                $success = "Account created with a usage limit of $final_limit! You can now <a href='login.php'>login here →</a>";
            } else {
                $error = "Something went wrong. Please try again.";
            }
        }
    }
}

$stmt_settings = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'");
$site_name = $stmt_settings->fetchColumn() ?: 'InfoCrop AI';

// Preserve field values on error
$val_name  = isset($_POST['name'])  ? htmlspecialchars($_POST['name'])  : '';
$val_phone = isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
        }

        :root {
            --green-50:  #f0fdf4;
            --green-100: #dcfce7;
            --green-200: #bbf7d0;
            --green-400: #4ade80;
            --green-500: #22c55e;
            --green-600: #16a34a;
            --green-700: #15803d;
            --green-800: #166534;
            --green-900: #14532d;
            --green-950: #052e16;

            --surface: #ffffff;
            --surface-2: #f8fdf9;
            --border: rgba(22, 163, 74, 0.12);
            --border-strong: rgba(22, 163, 74, 0.25);

            --text-primary: #0f2a15;
            --text-secondary: #3a6147;
            --text-muted: #6b8f74;
            --text-placeholder: #9db8a4;

            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.06), 0 2px 6px rgba(0,0,0,0.04);
            --shadow-lg: 0 20px 48px rgba(0,0,0,0.08), 0 8px 16px rgba(0,0,0,0.04);
            --shadow-glow: 0 0 0 4px rgba(22, 163, 74, 0.12);

            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;

            --transition: 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html { height: 100%; }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100%;
            background-color: var(--green-50);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* ── BACKGROUND ── */
        .bg-canvas {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .bg-canvas::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 10% 0%, rgba(34,197,94,0.13) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 90% 100%, rgba(20,184,166,0.09) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 50% 50%, rgba(134,239,172,0.07) 0%, transparent 70%);
        }

        .bg-canvas::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(22,163,74,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(22,163,74,0.04) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.6;
            animation: drift var(--dur, 25s) var(--delay, 0s) infinite alternate ease-in-out;
        }
        .orb-1 { width: 520px; height: 520px; background: radial-gradient(circle, rgba(74,222,128,0.18), transparent 70%); top: -120px; left: -100px; --dur: 22s; --delay: 0s; }
        .orb-2 { width: 400px; height: 400px; background: radial-gradient(circle, rgba(20,184,166,0.12), transparent 70%); bottom: -80px; right: -80px; --dur: 28s; --delay: -8s; }
        .orb-3 { width: 280px; height: 280px; background: radial-gradient(circle, rgba(134,239,172,0.15), transparent 70%); top: 50%; right: 15%; --dur: 18s; --delay: -4s; }

        @keyframes drift {
            0%   { transform: translate(0, 0) scale(1); }
            100% { transform: translate(40px, 60px) scale(1.08); }
        }

        /* ── LAYOUT ── */
        .page-layout {
            position: relative;
            z-index: 1;
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        /* ── LEFT PANEL ── */
        .left-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: clamp(40px, 6vw, 80px) clamp(40px, 5vw, 72px);
            background: linear-gradient(155deg, #ecfdf5 0%, #d1fae5 50%, #a7f3d0 100%);
            position: relative;
            overflow: hidden;
        }

        .left-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2316a34a' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        .panel-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.7);
            border: 1px solid rgba(22,163,74,0.2);
            border-radius: 100px;
            padding: 6px 14px 6px 8px;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--green-700);
            letter-spacing: 0.03em;
            text-transform: uppercase;
            margin-bottom: 32px;
            backdrop-filter: blur(8px);
        }

        .badge-dot {
            width: 8px; height: 8px;
            background: var(--green-500);
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.85); }
        }

        .panel-headline {
            font-family: 'Syne', sans-serif;
            font-size: clamp(2rem, 3.2vw, 3rem);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.03em;
            color: var(--green-950);
            margin-bottom: 20px;
        }

        .panel-headline em {
            font-style: normal;
            color: var(--green-600);
            position: relative;
        }

        .panel-headline em::after {
            content: '';
            position: absolute;
            bottom: 2px; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
            border-radius: 2px;
            opacity: 0.6;
        }

        .panel-desc {
            font-size: 1rem;
            color: var(--green-800);
            line-height: 1.7;
            max-width: 380px;
            margin-bottom: 40px;
            opacity: 0.8;
        }

        /* Step-style benefits */
        .steps-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0;
            position: relative;
        }

        .steps-list::before {
            content: '';
            position: absolute;
            left: 17px;
            top: 28px;
            bottom: 28px;
            width: 2px;
            background: linear-gradient(to bottom, var(--green-300, #86efac), transparent);
            opacity: 0.4;
        }

        .steps-list li {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 10px 0;
            position: relative;
        }

        .step-num {
            width: 36px; height: 36px;
            background: white;
            border: 2px solid rgba(22,163,74,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--green-600);
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        }

        .step-content strong {
            display: block;
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--green-900);
            margin-bottom: 2px;
            line-height: 1.3;
        }

        .step-content span {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* Floating stat card */
        .stat-card {
            position: absolute;
            bottom: 44px;
            right: -16px;
            background: white;
            border-radius: 18px;
            padding: 14px 18px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            animation: float-card 6s ease-in-out infinite;
            min-width: 190px;
        }

        .stat-card-label {
            font-size: 0.73rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .stat-card-value {
            font-family: 'Syne', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--green-700);
            line-height: 1;
        }

        .stat-card-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        @keyframes float-card {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }

        /* ── RIGHT FORM PANEL ── */
        .right-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: clamp(32px, 4vw, 60px) clamp(24px, 4vw, 64px);
            background: var(--surface);
            overflow-y: auto;
        }

        .form-container {
            width: 100%;
            max-width: 420px;
        }

        /* Brand */
        .form-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 32px;
        }

        .brand-logo {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, var(--green-500), var(--green-700));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 8px 20px rgba(22,163,74,0.3);
            flex-shrink: 0;
        }

        .brand-text h1 {
            font-family: 'Syne', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--text-primary);
            line-height: 1.1;
        }

        .brand-text span {
            font-size: 0.82rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .form-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.5rem, 2.2vw, 1.9rem);
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.025em;
            line-height: 1.2;
            margin-bottom: 6px;
        }

        .form-subtitle {
            font-size: 0.93rem;
            color: var(--text-muted);
            margin-bottom: 28px;
            line-height: 1.5;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-bottom: 22px;
            font-size: 0.875rem;
            font-weight: 500;
            line-height: 1.5;
        }

        .alert-error {
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-left: 3px solid #dc2626;
            color: #b91c1c;
            animation: shake 0.4s cubic-bezier(.36,.07,.19,.97);
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid rgba(22,163,74,0.2);
            border-left: 3px solid var(--green-500);
            color: var(--green-700);
        }

        .alert-success a {
            color: var(--green-700);
            font-weight: 700;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        .alert-icon { font-size: 1rem; line-height: 1.5; flex-shrink: 0; }

        /* Form row grid */
        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .field-row .field-group { margin-bottom: 0; }

        .field-group {
            margin-bottom: 18px;
        }

        .field-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 7px;
        }

        .field-input-wrap { position: relative; }

        .field-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-placeholder);
            font-size: 0.95rem;
            pointer-events: none;
            transition: color var(--transition);
            z-index: 1;
        }

        .field-input {
            width: 100%;
            padding: 12px 16px 12px 42px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-primary);
            background: var(--surface-2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            outline: none;
            transition: all var(--transition);
            -webkit-appearance: none;
        }

        .field-input::placeholder {
            color: var(--text-placeholder);
            font-weight: 400;
        }

        .field-input:hover {
            border-color: var(--border-strong);
            background: #fff;
        }

        .field-input:focus {
            background: #fff;
            border-color: var(--green-500);
            box-shadow: var(--shadow-glow);
        }

        .field-input-wrap:focus-within .field-icon {
            color: var(--green-600);
        }

        /* Password strength indicator */
        .pwd-strength {
            display: flex;
            gap: 4px;
            margin-top: 8px;
            height: 3px;
        }

        .pwd-strength-bar {
            flex: 1;
            background: var(--border);
            border-radius: 2px;
            transition: background var(--transition);
        }

        .pwd-strength-bar.active-weak   { background: #ef4444; }
        .pwd-strength-bar.active-fair   { background: #f59e0b; }
        .pwd-strength-bar.active-good   { background: #84cc16; }
        .pwd-strength-bar.active-strong { background: var(--green-500); }

        /* Password toggle */
        .pwd-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-placeholder);
            font-size: 0.88rem;
            padding: 4px;
            border-radius: 6px;
            transition: color var(--transition);
            display: flex;
            align-items: center;
        }

        .pwd-toggle:hover { color: var(--green-600); }

        /* Checkbox row */
        .terms-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            margin-top: 4px;
        }

        .terms-checkbox {
            width: 17px; height: 17px;
            margin-top: 2px;
            accent-color: var(--green-600);
            cursor: pointer;
            flex-shrink: 0;
        }

        .terms-label {
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .terms-label a {
            color: var(--green-600);
            font-weight: 600;
            text-decoration: none;
        }

        .terms-label a:hover { text-decoration: underline; }

        /* Submit button */
        .btn-submit {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 24px;
            background: linear-gradient(135deg, var(--green-600) 0%, var(--green-700) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-family: 'Syne', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 4px 16px rgba(22,163,74,0.35), 0 1px 3px rgba(22,163,74,0.2);
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12), transparent);
            opacity: 0;
            transition: opacity var(--transition);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(22,163,74,0.4), 0 2px 6px rgba(22,163,74,0.2);
        }

        .btn-submit:hover::before { opacity: 1; }
        .btn-submit:active { transform: translateY(0); }

        .btn-arrow { transition: transform var(--transition); }
        .btn-submit:hover .btn-arrow { transform: translateX(4px); }

        /* Spinner */
        .btn-submit .spinner {
            position: absolute;
            width: 20px; height: 20px;
            border: 2.5px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            opacity: 0;
        }
        .btn-submit.loading .spinner { opacity: 1; }
        .btn-submit.loading .btn-label { opacity: 0; }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 22px 0;
            color: var(--text-placeholder);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* Footer */
        .form-footer {
            text-align: center;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .form-footer a {
            color: var(--green-600);
            font-weight: 700;
            text-decoration: none;
            position: relative;
            transition: color var(--transition);
        }

        .form-footer a:hover { color: var(--green-700); }

        .form-footer a::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 0; right: 0;
            height: 1.5px;
            background: currentColor;
            transform: scaleX(0);
            transform-origin: left;
            transition: transform var(--transition);
        }

        .form-footer a:hover::after { transform: scaleX(1); }

        /* Trust row */
        .trust-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
        }

        .trust-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            color: var(--text-placeholder);
            font-weight: 500;
        }

        /* Entrance animations */
        .animate-in {
            opacity: 0;
            transform: translateY(18px);
            animation: slide-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes slide-up {
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-in:nth-child(1) { animation-delay: 0.05s; }
        .animate-in:nth-child(2) { animation-delay: 0.1s; }
        .animate-in:nth-child(3) { animation-delay: 0.15s; }
        .animate-in:nth-child(4) { animation-delay: 0.2s; }
        .animate-in:nth-child(5) { animation-delay: 0.25s; }
        .animate-in:nth-child(6) { animation-delay: 0.3s; }
        .animate-in:nth-child(7) { animation-delay: 0.35s; }
        .animate-in:nth-child(8) { animation-delay: 0.4s; }

        /* ══════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════ */
        @media (max-width: 1024px) {
            .left-panel { padding: 48px 40px; }
            .panel-headline { font-size: 2rem; }
            .stat-card { display: none; }
        }

        @media (max-width: 768px) {
            .page-layout {
                grid-template-columns: 1fr;
                min-height: 100vh;
            }

            .left-panel { display: none; }

            .right-panel {
                min-height: 100vh;
                padding: 40px 24px;
                background: var(--green-50);
                justify-content: center;
                position: relative;
            }

            .right-panel::before {
                content: '';
                position: absolute;
                inset: 0;
                background: radial-gradient(ellipse 80% 40% at 50% 0%, rgba(34,197,94,0.1) 0%, transparent 60%);
                pointer-events: none;
                z-index: 0;
            }

            .form-container {
                position: relative;
                z-index: 1;
                max-width: 480px;
                margin: 0 auto;
                background: white;
                border-radius: var(--radius-xl);
                padding: 36px 28px;
                box-shadow: var(--shadow-lg);
                border: 1px solid var(--border);
            }
        }

        @media (max-width: 520px) {
            .field-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .field-row .field-group { margin-bottom: 18px; }
        }

        @media (max-width: 480px) {
            .right-panel { padding: 20px 16px; align-items: stretch; }

            .form-container {
                max-width: 100%;
                padding: 28px 20px;
                border-radius: var(--radius-lg);
            }

            .form-brand { margin-bottom: 22px; }

            .brand-logo {
                width: 44px; height: 44px;
                font-size: 1.3rem;
                border-radius: 13px;
            }

            .brand-text h1 { font-size: 1.3rem; }

            .form-title { font-size: 1.4rem; }

            .form-subtitle {
                font-size: 0.87rem;
                margin-bottom: 22px;
            }

            .field-input { padding: 11px 14px 11px 40px; font-size: 0.93rem; }

            .btn-submit { padding: 13px 20px; font-size: 0.975rem; }

            .trust-row { gap: 12px; flex-wrap: wrap; }
        }

        @media (max-width: 360px) {
            .right-panel { padding: 16px 12px; }
            .form-container { padding: 22px 14px; }
            .divider { margin: 16px 0; }
        }

        @media (min-height: 900px) and (min-width: 769px) {
            .right-panel { padding-top: 60px; padding-bottom: 60px; }
        }
    </style>
</head>
<body>

<div class="bg-canvas">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
</div>

<div class="page-layout">

    <!-- ── LEFT PANEL ── -->
    <aside class="left-panel" aria-hidden="true">
        <div class="panel-badge">
            <span class="badge-dot"></span>
            Free to Join
        </div>

        <h2 class="panel-headline">
            Grow smarter,<br>harvest <em>more</em>
        </h2>

        <p class="panel-desc">
            Join thousands of farmers using AI-powered insights to boost yields, reduce waste, and farm with confidence.
        </p>

        <ul class="steps-list">
            <li>
                <span class="step-num">1</span>
                <div class="step-content">
                    <strong>Create your free account</strong>
                    <span>Takes less than 60 seconds to get started</span>
                </div>
            </li>
            <li>
                <span class="step-num">2</span>
                <div class="step-content">
                    <strong>Tell us about your farm</strong>
                    <span>Crops, location, soil type — we tailor everything</span>
                </div>
            </li>
            <li>
                <span class="step-num">3</span>
                <div class="step-content">
                    <strong>Get AI-powered advice</strong>
                    <span>Real-time recommendations to maximize your harvest</span>
                </div>
            </li>
            <li>
                <span class="step-num">4</span>
                <div class="step-content">
                    <strong>Track & improve every season</strong>
                    <span>Your personal farming intelligence grows with you</span>
                </div>
            </li>
        </ul>

        <div class="stat-card">
            <div class="stat-card-label">Farmers on platform</div>
            <div class="stat-card-value">24,800+</div>
            <div class="stat-card-sub">🌍 Across 12 states · Growing daily</div>
        </div>
    </aside>

    <!-- ── RIGHT FORM PANEL ── -->
    <main class="right-panel">
        <div class="form-container">

            <div class="form-brand animate-in">
                <div class="brand-logo" role="img" aria-label="InfoCrop logo">🌿</div>
                <div class="brand-text">
                    <h1><?php echo htmlspecialchars($site_name); ?></h1>
                    <span>Agricultural Intelligence</span>
                </div>
            </div>

            <h2 class="form-title animate-in">Create your account</h2>
            <p class="form-subtitle animate-in">Free forever · No credit card needed</p>

            <?php if ($error): ?>
            <div class="alert alert-error animate-in" role="alert">
                <span class="alert-icon">⚠️</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success animate-in" role="status">
                <span class="alert-icon">✅</span>
                <span><?php echo $success; ?></span>
            </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" id="signupForm" novalidate>
                <input type="hidden" name="fingerprint" id="fingerprint">
                <input type="hidden" name="lat" id="lat">
                <input type="hidden" name="lng" id="lng">

                <div class="field-group animate-in">
                    <label class="field-label" for="name">Full Name</label>
                    <div class="field-input-wrap">
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="field-input"
                            placeholder="Your full name"
                            autocomplete="name"
                            required
                            value="<?php echo $val_name; ?>"
                        >
                        <span class="field-icon" aria-hidden="true">👤</span>
                    </div>
                </div>

                <div class="field-group animate-in">
                    <label class="field-label" for="phone">Phone Number</label>
                    <div class="field-input-wrap">
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            class="field-input"
                            placeholder="Your phone number"
                            autocomplete="tel"
                            inputmode="numeric"
                            required
                            value="<?php echo $val_phone; ?>"
                        >
                        <span class="field-icon" aria-hidden="true">📱</span>
                    </div>
                </div>

                <div class="field-row animate-in">
                    <div class="field-group">
                        <label class="field-label" for="password">Password</label>
                        <div class="field-input-wrap">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="field-input"
                                placeholder="Create password"
                                autocomplete="new-password"
                                required
                            >
                            <span class="field-icon" aria-hidden="true">🔒</span>
                            <button type="button" class="pwd-toggle" id="pwdToggle1" aria-label="Toggle password">👁️</button>
                        </div>
                        <div class="pwd-strength" id="pwdStrength" aria-hidden="true">
                            <div class="pwd-strength-bar" id="bar1"></div>
                            <div class="pwd-strength-bar" id="bar2"></div>
                            <div class="pwd-strength-bar" id="bar3"></div>
                            <div class="pwd-strength-bar" id="bar4"></div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="confirm_password">Confirm</label>
                        <div class="field-input-wrap">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="field-input"
                                placeholder="Repeat password"
                                autocomplete="new-password"
                                required
                            >
                            <span class="field-icon" aria-hidden="true">✅</span>
                            <button type="button" class="pwd-toggle" id="pwdToggle2" aria-label="Toggle confirm password">👁️</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit animate-in" id="submitBtn">
                    <div class="spinner" aria-hidden="true"></div>
                    <span class="btn-label">
                        Create Free Account
                        <span class="btn-arrow" aria-hidden="true">→</span>
                    </span>
                </button>

            </form>
            <?php endif; ?>

            <div class="divider animate-in">or</div>

            <p class="form-footer animate-in">
                Already have an account? <a href="login.php">Sign in here</a>
            </p>

            <div class="trust-row animate-in" aria-label="Security badges">
                <div class="trust-item">🔒 Secure signup</div>
                <div class="trust-item">🛡️ Data protected</div>
                <div class="trust-item">✅ Free forever</div>
            </div>

        </div>
    </main>

</div>

<?php include 'partials/footer.php'; ?>

<script>
    // ── Fingerprint ──
    function generateFingerprint() {
        const s = window.screen, nav = window.navigator;
        const parts = [nav.userAgent, nav.language, s.width+'x'+s.height, s.colorDepth, new Date().getTimezoneOffset(), !!window.sessionStorage, !!window.localStorage];
        return btoa(parts.join('|')).substring(0, 100);
    }
    const fpField = document.getElementById('fingerprint');
    if (fpField) fpField.value = generateFingerprint();

    // ── Geolocation ──
    let geoLoaded = false;
    if ("geolocation" in navigator) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                document.getElementById('lat').value = pos.coords.latitude;
                document.getElementById('lng').value = pos.coords.longitude;
                geoLoaded = true;
            },
            err => { geoLoaded = true; console.log('Geo:', err.message); },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
    } else {
        geoLoaded = true;
    }

    // ── Password toggles ──
    function bindToggle(btnId, inputId) {
        const btn = document.getElementById(btnId);
        const inp = document.getElementById(inputId);
        if (!btn || !inp) return;
        btn.addEventListener('click', () => {
            const show = inp.type === 'password';
            inp.type = show ? 'text' : 'password';
            btn.textContent = show ? '🙈' : '👁️';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    }
    bindToggle('pwdToggle1', 'password');
    bindToggle('pwdToggle2', 'confirm_password');

    // ── Password strength ──
    const pwdInput = document.getElementById('password');
    const bars = [document.getElementById('bar1'), document.getElementById('bar2'), document.getElementById('bar3'), document.getElementById('bar4')];
    const classList = ['active-weak','active-fair','active-good','active-strong'];

    function getStrength(pwd) {
        let score = 0;
        if (pwd.length >= 8) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^A-Za-z0-9]/.test(pwd)) score++;
        return score;
    }

    if (pwdInput) {
        pwdInput.addEventListener('input', () => {
            const strength = getStrength(pwdInput.value);
            bars.forEach((bar, i) => {
                bar.className = 'pwd-strength-bar';
                if (i < strength) bar.classList.add(classList[strength - 1]);
            });
        });
    }

    // ── Password match indicator ──
    const confirmInput = document.getElementById('confirm_password');
    if (confirmInput && pwdInput) {
        confirmInput.addEventListener('input', () => {
            if (confirmInput.value && pwdInput.value) {
                const match = confirmInput.value === pwdInput.value;
                confirmInput.style.borderColor = match ? 'var(--green-500)' : '#ef4444';
            } else {
                confirmInput.style.borderColor = '';
            }
        });
    }

    // ── Submit with loading + GPS wait ──
    const form = document.getElementById('signupForm');
    const submitBtn = document.getElementById('submitBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function(e) {
            const phone = document.getElementById('phone').value.trim();
            const name  = document.getElementById('name').value.trim();
            const pwd   = document.getElementById('password').value;
            const conf  = document.getElementById('confirm_password').value;
            if (!name || !phone || !pwd || !conf) return;

            if (!geoLoaded) {
                e.preventDefault();
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
                const check = setInterval(() => {
                    if (geoLoaded) {
                        clearInterval(check);
                        form.submit();
                    }
                }, 200);
                // Hard fallback after 3s
                setTimeout(() => { clearInterval(check); form.submit(); }, 3000);
                return;
            }

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            setTimeout(() => {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }, 8000);
        });
    }

    // ── Auto-focus first empty field ──
    window.addEventListener('DOMContentLoaded', () => {
        const nameInput = document.getElementById('name');
        if (nameInput && !nameInput.value) nameInput.focus();
    });
</script>

</body>
</html>
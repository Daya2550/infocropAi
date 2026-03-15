<?php
// login.php
session_start();
require_once 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($phone) || empty($password)) {
        $error = "Please enter both phone and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'suspended') {
                $error = "Your account has been suspended. Please contact support.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                header("Location: index.php");
                exit;
            }
        } else {
            $error = "Invalid phone number or password.";
        }
    }
}

// Fetch Site Name
$stmt_settings = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='site_name'");
$site_name = $stmt_settings->fetchColumn() ?: 'InfoCrop AI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In | <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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

        /* ── BACKGROUND CANVAS ── */
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

        /* Subtle grid pattern */
        .bg-canvas::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(22,163,74,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(22,163,74,0.04) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        /* Floating orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.6;
            animation: drift var(--dur, 25s) var(--delay, 0s) infinite alternate ease-in-out;
        }
        .orb-1 {
            width: 520px; height: 520px;
            background: radial-gradient(circle, rgba(74,222,128,0.18), transparent 70%);
            top: -120px; left: -100px;
            --dur: 22s; --delay: 0s;
        }
        .orb-2 {
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(20,184,166,0.12), transparent 70%);
            bottom: -80px; right: -80px;
            --dur: 28s; --delay: -8s;
        }
        .orb-3 {
            width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(134,239,172,0.15), transparent 70%);
            top: 50%; right: 15%;
            --dur: 18s; --delay: -4s;
        }

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

        /* ── LEFT PANEL (decorative) ── */
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
            font-size: clamp(2.2rem, 3.5vw, 3.2rem);
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
            bottom: 2px;
            left: 0; right: 0;
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
            margin-bottom: 48px;
            opacity: 0.8;
        }

        .features-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .features-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--green-900);
        }

        .feature-icon {
            width: 36px; height: 36px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }

        /* Floating crop card */
        .crop-card {
            position: absolute;
            bottom: 40px;
            right: -20px;
            background: white;
            border-radius: 20px;
            padding: 16px 20px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            animation: float-card 6s ease-in-out infinite;
            max-width: 240px;
        }

        @keyframes float-card {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }

        .crop-card-icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--green-100), var(--green-200));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .crop-card-text small {
            display: block;
            font-size: 0.73rem;
            color: var(--text-muted);
            font-weight: 400;
            margin-top: 2px;
        }

        /* ── RIGHT PANEL (form) ── */
        .right-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: clamp(32px, 4vw, 60px) clamp(24px, 4vw, 64px);
            background: var(--surface);
        }

        .form-container {
            width: 100%;
            max-width: 400px;
        }

        /* Logo + heading */
        .form-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 36px;
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
            font-size: clamp(1.6rem, 2.5vw, 2rem);
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.025em;
            line-height: 1.2;
            margin-bottom: 6px;
        }

        .form-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-bottom: 32px;
            line-height: 1.5;
        }

        /* Alert */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-left: 3px solid #dc2626;
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 0.875rem;
            color: #b91c1c;
            font-weight: 500;
            line-height: 1.5;
            animation: shake 0.4s cubic-bezier(.36,.07,.19,.97);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        .alert-icon { font-size: 1rem; line-height: 1.5; flex-shrink: 0; }

        /* Form fields */
        .field-group {
            margin-bottom: 20px;
        }

        .field-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .field-label label {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .field-input-wrap {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-placeholder);
            font-size: 1rem;
            pointer-events: none;
            transition: color var(--transition);
            z-index: 1;
        }

        .field-input {
            width: 100%;
            padding: 13px 16px 13px 44px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.975rem;
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

        .field-input:focus + .field-icon,
        .field-input-wrap:focus-within .field-icon {
            color: var(--green-600);
        }

        /* Password toggle */
        .pwd-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-placeholder);
            font-size: 0.9rem;
            padding: 4px;
            border-radius: 6px;
            transition: color var(--transition);
            display: flex;
            align-items: center;
        }

        .pwd-toggle:hover { color: var(--green-600); }

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
            margin-top: 8px;
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

        .btn-arrow {
            display: inline-flex;
            align-items: center;
            transition: transform var(--transition);
        }

        .btn-submit:hover .btn-arrow { transform: translateX(4px); }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 24px 0;
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

        /* Footer link */
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
            bottom: -1px;
            left: 0; right: 0;
            height: 1.5px;
            background: currentColor;
            transform: scaleX(0);
            transform-origin: left;
            transition: transform var(--transition);
        }

        .form-footer a:hover::after { transform: scaleX(1); }

        /* Trust badges */
        .trust-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-top: 28px;
            padding-top: 20px;
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

        /* Loading state */
        .btn-submit.loading .btn-label { opacity: 0; }
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

        @keyframes spin {
            to { transform: rotate(360deg); }
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

        /* ══════════════════════════════════════
           RESPONSIVE BREAKPOINTS
        ══════════════════════════════════════ */

        /* Tablets landscape (1024px and below) */
        @media (max-width: 1024px) {
            .left-panel {
                padding: 48px 40px;
            }
            .panel-headline {
                font-size: 2rem;
            }
            .crop-card {
                display: none;
            }
        }

        /* Tablets portrait (768px and below) — stack layout */
        @media (max-width: 768px) {
            .page-layout {
                grid-template-columns: 1fr;
                min-height: 100vh;
            }

            .left-panel {
                display: none; /* Hide on mobile for clean single-panel UX */
            }

            .right-panel {
                min-height: 100vh;
                padding: 40px 24px;
                background: var(--green-50);
                position: relative;
            }

            .right-panel::before {
                content: '';
                position: absolute;
                inset: 0;
                background:
                    radial-gradient(ellipse 80% 40% at 50% 0%, rgba(34,197,94,0.1) 0%, transparent 60%);
                pointer-events: none;
                z-index: 0;
            }

            .form-container {
                position: relative;
                z-index: 1;
                max-width: 440px;
                margin: 0 auto;
                /* Card feel on mobile */
                background: white;
                border-radius: var(--radius-xl);
                padding: 36px 28px;
                box-shadow: var(--shadow-lg);
                border: 1px solid var(--border);
            }

            .form-brand {
                margin-bottom: 28px;
            }

            .form-title {
                font-size: 1.6rem;
            }
        }

        /* Small phones (480px and below) */
        @media (max-width: 480px) {
            .right-panel {
                padding: 20px 16px;
                align-items: stretch;
            }

            .form-container {
                max-width: 100%;
                padding: 28px 20px;
                border-radius: var(--radius-lg);
            }

            .form-brand {
                margin-bottom: 24px;
            }

            .brand-logo {
                width: 44px; height: 44px;
                font-size: 1.3rem;
                border-radius: 13px;
            }

            .brand-text h1 {
                font-size: 1.3rem;
            }

            .form-title {
                font-size: 1.4rem;
            }

            .form-subtitle {
                font-size: 0.88rem;
                margin-bottom: 24px;
            }

            .field-input {
                padding: 12px 16px 12px 42px;
                font-size: 0.95rem;
            }

            .btn-submit {
                padding: 13px 20px;
                font-size: 0.975rem;
            }

            .trust-row {
                gap: 12px;
                flex-wrap: wrap;
            }
        }

        /* Very small devices (360px) */
        @media (max-width: 360px) {
            .right-panel {
                padding: 16px 12px;
            }

            .form-container {
                padding: 24px 16px;
            }

            .divider { margin: 18px 0; }

            .trust-row {
                margin-top: 20px;
                padding-top: 16px;
            }
        }

        /* Tall desktop screens — limit vertical stretch */
        @media (min-height: 900px) and (min-width: 769px) {
            .right-panel {
                padding-top: 80px;
                padding-bottom: 80px;
            }
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

    <!-- ── LEFT DECORATIVE PANEL ── -->
    <aside class="left-panel" aria-hidden="true">
        <div class="panel-badge">
            <span class="badge-dot"></span>
            AI-Powered Platform
        </div>

        <h2 class="panel-headline">
            Smarter farming<br>starts with <em>better data</em>
        </h2>

        <p class="panel-desc">
            Get personalized crop recommendations, real-time weather insights, and yield predictions — all in one place.
        </p>

        <ul class="features-list">
            <li>
                <span class="feature-icon">🌾</span>
                Crop health monitoring & alerts
            </li>
            <li>
                <span class="feature-icon">🌦️</span>
                Hyper-local weather forecasts
            </li>
            <li>
                <span class="feature-icon">📊</span>
                Yield prediction with AI insights
            </li>
            <li>
                <span class="feature-icon">🧑‍🌾</span>
                Expert advisory network
            </li>
        </ul>

        <div class="crop-card">
            <div class="crop-card-icon">🌿</div>
            <div class="crop-card-text">
                Wheat yield up 23%
                <small>Based on your soil report · Just now</small>
            </div>
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

            <h2 class="form-title animate-in">Welcome back</h2>
            <p class="form-subtitle animate-in">Sign in to your account to continue</p>

            <?php if ($error): ?>
            <div class="alert animate-in" role="alert">
                <span class="alert-icon">⚠️</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" novalidate>

                <div class="field-group animate-in">
                    <div class="field-label">
                        <label for="phone">Phone Number</label>
                    </div>
                    <div class="field-input-wrap">
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            class="field-input"
                            placeholder="Enter your phone number"
                            autocomplete="tel"
                            inputmode="numeric"
                            required
                            value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                        >
                        <span class="field-icon" aria-hidden="true">📱</span>
                    </div>
                </div>

                <div class="field-group animate-in">
                    <div class="field-label">
                        <label for="password">Password</label>
                    </div>
                    <div class="field-input-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="field-input"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <span class="field-icon" aria-hidden="true">🔒</span>
                        <button
                            type="button"
                            class="pwd-toggle"
                            id="pwdToggle"
                            aria-label="Toggle password visibility"
                            title="Show / hide password"
                        >👁️</button>
                    </div>
                </div>

                <button type="submit" class="btn-submit animate-in" id="submitBtn">
                    <div class="spinner" aria-hidden="true"></div>
                    <span class="btn-label">
                        Sign In
                        <span class="btn-arrow" aria-hidden="true">→</span>
                    </span>
                </button>

            </form>

            <div class="divider animate-in">or</div>

            <p class="form-footer animate-in">
                Don't have an account? <a href="signup.php">Create one free</a>
            </p>

            <div class="trust-row animate-in" aria-label="Security badges">
                <div class="trust-item">🔒 Secure login</div>
                <div class="trust-item">🛡️ Data protected</div>
                <div class="trust-item">✅ Verified platform</div>
            </div>

        </div>
    </main>

</div>

<?php include 'partials/footer.php'; ?>

<script>
    // Password toggle
    const pwdToggle = document.getElementById('pwdToggle');
    const pwdInput  = document.getElementById('password');

    if (pwdToggle && pwdInput) {
        pwdToggle.addEventListener('click', () => {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type     = isPassword ? 'text' : 'password';
            pwdToggle.textContent = isPassword ? '🙈' : '👁️';
            pwdToggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    }

    // Submit loading state
    const form      = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', (e) => {
            const phone    = document.getElementById('phone').value.trim();
            const password = document.getElementById('password').value;

            if (!phone || !password) return; // let browser handle required

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            // Re-enable after 8s fallback (page might reload on error)
            setTimeout(() => {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
            }, 8000);
        });
    }

    // Auto-focus first empty field
    window.addEventListener('DOMContentLoaded', () => {
        const phoneInput = document.getElementById('phone');
        if (phoneInput && !phoneInput.value) {
            phoneInput.focus();
        } else {
            document.getElementById('password')?.focus();
        }
    });
</script>

</body>
</html>
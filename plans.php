<?php
// plans.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Fetch System Settings
$stmt = $pdo->query("SELECT * FROM settings");
$sys_settings = [];
foreach ($stmt->fetchAll() as $s) {
    $sys_settings[$s['setting_key']] = $s['setting_value'];
}
$site_name = $sys_settings['site_name'] ?? 'InfoCrop AI';

// Dynamic Plan Settings
$starter_price = (int)($sys_settings['plan_starter_price'] ?? 50);
$starter_limit = (int)($sys_settings['plan_starter_limit'] ?? 10);
$pro_price     = (int)($sys_settings['plan_pro_price']     ?? 100);
$pro_limit     = (int)($sys_settings['plan_pro_limit']     ?? 25);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan_type      = $_POST['plan_type'] ?? '';
    $transaction_id = trim($_POST['transaction_id'] ?? '');

    if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === 0) {
        $allowed  = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['screenshot']['name'];
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $error = "Invalid file type. Only JPG, JPEG, and PNG allowed.";
        } else {
            $new_filename = "pay_" . time() . "_" . $user_id . "." . $ext;
            $upload_dir   = 'uploads/payments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $dest = $upload_dir . $new_filename;
            if (move_uploaded_file($_FILES['screenshot']['tmp_name'], $dest)) {
                $new_limit = ($plan_type === 'standard') ? $starter_limit : $pro_limit;
                $amount    = ($plan_type === 'standard') ? $starter_price : $pro_price;

                $stmt = $pdo->prepare("INSERT INTO payments (user_id, amount, screenshot, transaction_id, new_limit) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $amount, $dest, $transaction_id, $new_limit]);

                $success = "Payment proof submitted! Admin will approve it within 24 hours.";
            } else {
                $error = "Failed to upload screenshot. Please try again.";
            }
        }
    } else {
        $error = "Payment screenshot is required.";
    }
}

// Fetch last payment
$stmt = $pdo->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user_id]);
$last_payment = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plans & Pricing | <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/global.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

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

            --surface:   #ffffff;
            --surface-2: #f8fdf9;
            --border:    rgba(22, 163, 74, 0.12);
            --border-strong: rgba(22, 163, 74, 0.28);

            --text-primary:     #0f2a15;
            --text-secondary:   #3a6147;
            --text-muted:       #6b8f74;
            --text-placeholder: #9db8a4;

            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.06), 0 2px 6px rgba(0,0,0,0.04);
            --shadow-lg: 0 20px 48px rgba(0,0,0,0.08), 0 8px 16px rgba(0,0,0,0.04);
            --shadow-glow: 0 0 0 4px rgba(22, 163, 74, 0.12);
            --shadow-glow-strong: 0 0 0 4px rgba(22, 163, 74, 0.2);

            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;

            --transition: 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--green-50);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* ── BACKGROUND ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background:
                radial-gradient(ellipse 70% 50% at 5% 0%,  rgba(34,197,94,0.10) 0%, transparent 60%),
                radial-gradient(ellipse 55% 45% at 95% 100%, rgba(20,184,166,0.07) 0%, transparent 55%);
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(22,163,74,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(22,163,74,0.035) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        /* ── PAGE WRAPPER ── */
        .page-wrap {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: clamp(32px, 5vw, 56px) clamp(16px, 4vw, 32px) 80px;
        }

        /* ── PAGE HEADER ── */
        .page-header {
            text-align: center;
            margin-bottom: clamp(36px, 5vw, 56px);
            animation: slide-up 0.5s cubic-bezier(0.16,1,0.3,1) both;
        }

        .page-header-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid var(--border-strong);
            border-radius: 100px;
            padding: 5px 14px 5px 8px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--green-700);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .badge-dot {
            width: 8px; height: 8px;
            background: var(--green-500);
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%,100% { opacity:1; transform:scale(1); }
            50% { opacity:.5; transform:scale(.8); }
        }

        .page-header h1 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(2rem, 4.5vw, 3rem);
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--green-950);
            line-height: 1.1;
            margin-bottom: 14px;
        }

        .page-header h1 em {
            font-style: normal;
            color: var(--green-600);
            position: relative;
        }

        .page-header h1 em::after {
            content: '';
            position: absolute;
            bottom: 2px; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--green-400), var(--green-600));
            border-radius: 2px;
            opacity: 0.55;
        }

        .page-header p {
            font-size: 1.05rem;
            color: var(--text-muted);
            font-weight: 400;
            max-width: 480px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* ── ALERTS ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 28px;
            font-size: 0.9rem;
            font-weight: 500;
            line-height: 1.5;
            animation: slide-up 0.4s cubic-bezier(0.16,1,0.3,1) both;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid rgba(22,163,74,0.2);
            border-left: 3px solid var(--green-500);
            color: var(--green-800);
        }

        .alert-error {
            background: #fff5f5;
            border: 1px solid #fecaca;
            border-left: 3px solid #dc2626;
            color: #b91c1c;
        }

        .alert-pending {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 3px solid #f59e0b;
            color: #92400e;
        }

        .alert-icon { font-size: 1rem; line-height: 1.5; flex-shrink: 0; }

        /* ── PLANS GRID ── */
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 52px;
        }

        .plan-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 40px 32px 36px;
            position: relative;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-md);
            transition: transform var(--transition), box-shadow var(--transition);
            animation: slide-up 0.5s cubic-bezier(0.16,1,0.3,1) both;
        }

        .plan-card:nth-child(1) { animation-delay: 0.1s; }
        .plan-card:nth-child(2) { animation-delay: 0.18s; }

        .plan-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
        }

        .plan-card.popular {
            border: 2px solid var(--green-500);
            box-shadow: 0 0 0 1px rgba(34,197,94,0.15), var(--shadow-lg);
        }

        .popular-tag {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, var(--green-500), var(--green-700));
            color: white;
            padding: 5px 18px;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            white-space: nowrap;
            box-shadow: 0 4px 14px rgba(22,163,74,0.35);
        }

        .plan-icon {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, var(--green-100), var(--green-200));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .plan-name {
            font-family: 'Syne', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .plan-tagline {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.4;
        }

        .plan-price-row {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            margin-bottom: 28px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .plan-price-amount {
            font-family: 'Syne', sans-serif;
            font-size: 3rem;
            font-weight: 800;
            color: var(--green-700);
            line-height: 1;
            letter-spacing: -0.03em;
        }

        .plan-price-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
            padding-bottom: 6px;
            line-height: 1.3;
        }

        .plan-price-meta strong {
            display: block;
            font-size: 0.92rem;
            color: var(--text-secondary);
            font-weight: 700;
        }

        .feature-list {
            list-style: none;
            flex: 1;
            margin-bottom: 28px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 0;
            font-size: 0.92rem;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 1px solid rgba(22,163,74,0.06);
        }

        .feature-list li:last-child { border-bottom: none; }

        .feature-check {
            width: 20px; height: 20px;
            background: linear-gradient(135deg, var(--green-100), var(--green-200));
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            flex-shrink: 0;
            color: var(--green-700);
            font-weight: 900;
        }

        .plan-cta {
            display: block;
            width: 100%;
            padding: 13px 20px;
            border-radius: var(--radius-md);
            text-align: center;
            font-family: 'Syne', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
            border: none;
        }

        .plan-cta-outline {
            background: var(--surface-2);
            border: 1.5px solid var(--border-strong);
            color: var(--green-700);
        }

        .plan-cta-outline:hover {
            background: var(--green-100);
            border-color: var(--green-500);
        }

        .plan-cta-solid {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            box-shadow: 0 4px 16px rgba(22,163,74,0.35);
        }

        .plan-cta-solid:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(22,163,74,0.4);
        }

        /* ── PAYMENT SECTION ── */
        .payment-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: slide-up 0.5s 0.25s cubic-bezier(0.16,1,0.3,1) both;
        }

        .payment-section-header {
            background: linear-gradient(135deg, var(--green-950) 0%, var(--green-800) 100%);
            padding: 32px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .payment-section-header h2 {
            font-family: 'Syne', sans-serif;
            font-size: clamp(1.4rem, 3vw, 1.75rem);
            font-weight: 800;
            color: white;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .payment-section-header p {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.65);
            margin-top: 5px;
            font-weight: 400;
        }

        .secure-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 100px;
            padding: 6px 14px;
            font-size: 0.78rem;
            font-weight: 600;
            color: rgba(255,255,255,0.9);
            white-space: nowrap;
        }

        .payment-body {
            display: grid;
            grid-template-columns: 1fr 1.15fr;
            gap: 0;
        }

        /* Left: Steps */
        .steps-col {
            padding: clamp(28px, 4vw, 48px);
            border-right: 1px solid var(--border);
        }

        .steps-col h3 {
            font-family: 'Syne', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.01em;
            margin-bottom: 28px;
        }

        .step-item {
            display: flex;
            gap: 16px;
            margin-bottom: 28px;
            position: relative;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 17px;
            top: 36px;
            bottom: -16px;
            width: 2px;
            background: linear-gradient(to bottom, var(--green-200), transparent);
        }

        .step-bubble {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.82rem;
            font-weight: 800;
            color: white;
            box-shadow: 0 4px 12px rgba(22,163,74,0.3);
        }

        .step-content h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .step-content p {
            font-size: 0.84rem;
            color: var(--text-muted);
            line-height: 1.55;
            font-weight: 400;
        }

        /* QR Box inside steps col */
        .qr-box {
            background: linear-gradient(135deg, var(--green-50), var(--green-100));
            border: 1.5px dashed var(--border-strong);
            border-radius: var(--radius-lg);
            padding: 24px 20px;
            text-align: center;
            margin-top: 8px;
        }

        .qr-img-wrap {
            width: 160px; height: 160px;
            background: white;
            margin: 0 auto 14px;
            padding: 10px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .qr-img-wrap img {
            max-width: 100%;
            max-height: 100%;
            border-radius: 8px;
        }

        .qr-placeholder {
            font-size: 3rem;
            line-height: 1;
        }

        .qr-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-bottom: 12px;
        }

        .upi-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: white;
            border: 1.5px solid var(--border-strong);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--green-700);
            word-break: break-all;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: all var(--transition);
            max-width: 100%;
        }

        .upi-chip:hover {
            background: var(--green-50);
            border-color: var(--green-500);
        }

        /* Right: Form */
        .form-col {
            padding: clamp(28px, 4vw, 48px);
        }

        .form-col h3 {
            font-family: 'Syne', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.01em;
            margin-bottom: 28px;
        }

        .field-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field-group {
            margin-bottom: 18px;
        }

        .field-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 7px;
        }

        .field-input-wrap { position: relative; }

        .field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-placeholder);
            font-size: 0.9rem;
            pointer-events: none;
            transition: color var(--transition);
            z-index: 1;
        }

        .field-input,
        .field-select {
            width: 100%;
            padding: 12px 14px 12px 40px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.93rem;
            font-weight: 500;
            color: var(--text-primary);
            background: var(--surface-2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-md);
            outline: none;
            transition: all var(--transition);
            -webkit-appearance: none;
        }

        .field-input::placeholder { color: var(--text-placeholder); font-weight: 400; }

        .field-input:hover,
        .field-select:hover { border-color: var(--border-strong); background: #fff; }

        .field-input:focus,
        .field-select:focus {
            background: #fff;
            border-color: var(--green-500);
            box-shadow: var(--shadow-glow);
        }

        .field-input-wrap:focus-within .field-icon { color: var(--green-600); }

        /* File input */
        .file-drop {
            width: 100%;
            border: 2px dashed var(--border-strong);
            border-radius: var(--radius-md);
            padding: 22px 20px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition);
            background: var(--surface-2);
            position: relative;
        }

        .file-drop:hover,
        .file-drop.dragover {
            border-color: var(--green-500);
            background: var(--green-50);
        }

        .file-drop input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .file-drop-icon { font-size: 1.8rem; line-height: 1; margin-bottom: 8px; }

        .file-drop-text {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .file-drop-text span {
            color: var(--green-600);
            font-weight: 700;
        }

        .file-drop-hint {
            font-size: 0.75rem;
            color: var(--text-placeholder);
            margin-top: 4px;
        }

        .file-name-preview {
            display: none;
            align-items: center;
            gap: 8px;
            background: var(--green-50);
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            font-size: 0.83rem;
            font-weight: 600;
            color: var(--green-700);
            margin-top: 10px;
        }

        /* Submit button */
        .btn-submit {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 24px;
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-family: 'Syne', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 4px 16px rgba(22,163,74,0.35);
            position: relative;
            overflow: hidden;
            margin-top: 4px;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), transparent);
            opacity: 0;
            transition: opacity var(--transition);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(22,163,74,0.4);
        }

        .btn-submit:hover::before { opacity: 1; }
        .btn-submit:active { transform: translateY(0); }

        .btn-arrow { transition: transform var(--transition); }
        .btn-submit:hover .btn-arrow { transform: translateX(4px); }

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

        /* ── ANIMATIONS ── */
        @keyframes slide-up {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .payment-body {
                grid-template-columns: 1fr;
            }
            .steps-col {
                border-right: none;
                border-bottom: 1px solid var(--border);
                padding-bottom: clamp(24px, 4vw, 40px);
            }
            .qr-box { max-width: 360px; margin: 0 auto; }
        }

        @media (max-width: 700px) {
            .plans-grid { grid-template-columns: 1fr; gap: 20px; }
            .payment-section-header { padding: 24px 24px; }
            .steps-col, .form-col { padding: 24px 20px; }
        }

        @media (max-width: 540px) {
            .field-row-2 { grid-template-columns: 1fr; gap: 0; }
        }

        @media (max-width: 480px) {
            .page-wrap { padding: 20px 14px 60px; }
            .plan-card { padding: 28px 20px; border-radius: var(--radius-lg); }
            .plan-price-amount { font-size: 2.5rem; }
            .payment-section { border-radius: var(--radius-lg); }
            .payment-section-header { padding: 20px; }
            .steps-col, .form-col { padding: 20px 16px; }
            .secure-badge { display: none; }
        }

        @media (max-width: 360px) {
            .page-wrap { padding: 16px 10px 48px; }
            .plan-card { padding: 22px 14px; }
        }

        /* Tooltip for UPI copy */
        .copied-toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(12px);
            background: var(--green-900);
            color: white;
            padding: 10px 20px;
            border-radius: 100px;
            font-size: 0.85rem;
            font-weight: 600;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s ease;
            z-index: 9999;
            white-space: nowrap;
        }

        .copied-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    </style>
</head>
<body>

<?php include 'partials/header.php'; ?>

<main class="page-main">
<div class="page-wrap">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-badge">
            <span class="badge-dot"></span>
            Upgrade Your Farm
        </div>
        <h1>Choose Your <em>Success Plan</em></h1>
        <p>Unlock full AI-powered farming insights. Pick the plan that fits your needs.</p>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <span class="alert-icon">⚠️</span>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($last_payment && $last_payment['status'] === 'pending'): ?>
        <div class="alert alert-pending">
            <span class="alert-icon">⌛</span>
            <span>Your payment of <strong>₹<?php echo (int)$last_payment['amount']; ?></strong> is pending approval. Credits will be added within 24 hours.</span>
        </div>
    <?php endif; ?>

    <!-- Plans -->
    <div class="plans-grid">

        <!-- Starter -->
        <div class="plan-card">
            <div class="plan-icon">🌾</div>
            <div class="plan-name">Starter Plan</div>
            <div class="plan-tagline">Perfect for small farms & getting started</div>
            <div class="plan-price-row">
                <div class="plan-price-amount">₹<?php echo $starter_price; ?></div>
                <div class="plan-price-meta">
                    one-time
                    <strong><?php echo $starter_limit; ?> AI Credits</strong>
                </div>
            </div>
            <ul class="feature-list">
                <li><span class="feature-check">✓</span> <?php echo $starter_limit; ?> Full AI Farm Plans</li>
                <li><span class="feature-check">✓</span> Email & Chat Support</li>
                <li><span class="feature-check">✓</span> PDF Report Downloads</li>
                <li><span class="feature-check">✓</span> Basic Market Trends</li>
                <li><span class="feature-check">✓</span> Crop Condition Monitoring</li>
            </ul>
            <a href="#payment-form" class="plan-cta plan-cta-outline" onclick="selectPlan('standard')">Get Starter</a>
        </div>

        <!-- Pro -->
        <div class="plan-card popular">
            <div class="popular-tag">MOST POPULAR</div>
            <div class="plan-icon">🚜</div>
            <div class="plan-name">Pro Farmer</div>
            <div class="plan-tagline">For serious farmers who want maximum results</div>
            <div class="plan-price-row">
                <div class="plan-price-amount">₹<?php echo $pro_price; ?></div>
                <div class="plan-price-meta">
                    one-time
                    <strong><?php echo $pro_limit; ?> AI Credits</strong>
                </div>
            </div>
            <ul class="feature-list">
                <li><span class="feature-check">✓</span> <?php echo $pro_limit; ?> Full AI Farm Plans</li>
                <li><span class="feature-check">✓</span> Priority WhatsApp Support</li>
                <li><span class="feature-check">✓</span> Advanced Pest Analysis</li>
                <li><span class="feature-check">✓</span> Real-time Mandi Price Alerts</li>
                <li><span class="feature-check">✓</span> Soil Quality Tracking</li>
                <li><span class="feature-check">✓</span> Weather Risk Analysis</li>
            </ul>
            <a href="#payment-form" class="plan-cta plan-cta-solid" onclick="selectPlan('pro')">Get Pro Farmer</a>
        </div>

    </div>

    <!-- Payment Section -->
    <div class="payment-section" id="payment-form">

        <div class="payment-section-header">
            <div>
                <h2>Complete Your Payment</h2>
                <p>Scan, pay, and submit proof — credits added within 24 hours</p>
            </div>
            <div class="secure-badge">🔒 UPI Secured Payment</div>
        </div>

        <div class="payment-body">

            <!-- Steps + QR -->
            <div class="steps-col">
                <h3>How it works</h3>

                <div class="step-item">
                    <div class="step-bubble">1</div>
                    <div class="step-content">
                        <h4>Scan the QR Code</h4>
                        <p>Open GPay, PhonePe, Paytm or any UPI app and scan the code below to pay.</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-bubble">2</div>
                    <div class="step-content">
                        <h4>Note your Transaction ID</h4>
                        <p>After payment, copy the 12-digit UTR / Transaction ID from your UPI app.</p>
                    </div>
                </div>

                <div class="step-item">
                    <div class="step-bubble">3</div>
                    <div class="step-content">
                        <h4>Submit proof on the right</h4>
                        <p>Fill the form with your plan, transaction ID, and a screenshot. We'll verify and credit you.</p>
                    </div>
                </div>

                <!-- QR Card -->
                <div class="qr-box">
                    <div class="qr-img-wrap">
                        <?php if (!empty($sys_settings['payment_qr_path'])): ?>
                            <img src="<?php echo htmlspecialchars($sys_settings['payment_qr_path']); ?>" alt="UPI QR Code">
                        <?php else: ?>
                            <div class="qr-placeholder">📱</div>
                        <?php endif; ?>
                    </div>
                    <p class="qr-hint">Scan with any UPI app</p>
                    <div
                        class="upi-chip"
                        id="upiChip"
                        onclick="copyUPI()"
                        title="Click to copy UPI ID"
                        role="button"
                        tabindex="0"
                        aria-label="Copy UPI ID"
                    >
                        <span>🆔</span>
                        <?php echo htmlspecialchars($sys_settings['payment_upi_id'] ?? 'jagadledayanand2550@okicici'); ?>
                        <span style="font-size:0.7rem; opacity:0.6;">copy</span>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <div class="form-col">
                <h3>Submit Payment Proof</h3>

                <form method="POST" enctype="multipart/form-data" id="paymentForm">

                    <div class="field-row-2">
                        <div class="field-group">
                            <label class="field-label" for="plan_type">Select Plan</label>
                            <div class="field-input-wrap">
                                <select name="plan_type" id="plan_type" class="field-select" required>
                                    <option value="standard">🌾 Starter — ₹<?php echo $starter_price; ?></option>
                                    <option value="pro">🚜 Pro Farmer — ₹<?php echo $pro_price; ?></option>
                                </select>
                                <span class="field-icon" aria-hidden="true">📋</span>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="transaction_id">Transaction ID / UTR</label>
                            <div class="field-input-wrap">
                                <input
                                    type="text"
                                    id="transaction_id"
                                    name="transaction_id"
                                    class="field-input"
                                    placeholder="12-digit UTR number"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    required
                                >
                                <span class="field-icon" aria-hidden="true">🔢</span>
                            </div>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Payment Screenshot</label>
                        <div class="file-drop" id="fileDrop">
                            <input
                                type="file"
                                name="screenshot"
                                id="screenshotInput"
                                accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                required
                            >
                            <div class="file-drop-icon">📸</div>
                            <div class="file-drop-text">
                                <span>Click to upload</span> or drag & drop
                            </div>
                            <div class="file-drop-hint">JPG, JPEG or PNG · Max 5MB</div>
                        </div>
                        <div class="file-name-preview" id="filePreview">
                            <span>📎</span>
                            <span id="fileName"></span>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <div class="spinner" aria-hidden="true"></div>
                        <span class="btn-label">
                            Submit for Approval
                            <span class="btn-arrow" aria-hidden="true">→</span>
                        </span>
                    </button>

                </form>

                <p style="font-size:0.78rem; color:var(--text-placeholder); margin-top:14px; line-height:1.5; text-align:center;">
                    🛡️ Your payment info is secure. Credits are added within 24 hours after manual verification.
                </p>
            </div>

        </div>
    </div>

</div>

<!-- Copy toast -->
<div class="copied-toast" id="copiedToast">✅ UPI ID copied!</div>

</main>
<?php include 'partials/footer.php'; ?>

<script>
    // ── Select plan via CTA buttons ──
    function selectPlan(value) {
        setTimeout(() => {
            const sel = document.getElementById('plan_type');
            if (sel) sel.value = value;
        }, 50);
    }

    // ── UPI copy ──
    function copyUPI() {
        const upiText = document.getElementById('upiChip').textContent.replace('🆔','').replace('copy','').trim();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(upiText).then(showCopied).catch(() => fallbackCopy(upiText));
        } else {
            fallbackCopy(upiText);
        }
    }

    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showCopied();
    }

    function showCopied() {
        const toast = document.getElementById('copiedToast');
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 2500);
    }

    document.getElementById('upiChip')?.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); copyUPI(); }
    });

    // ── File upload preview ──
    const fileInput   = document.getElementById('screenshotInput');
    const fileDrop    = document.getElementById('fileDrop');
    const filePreview = document.getElementById('filePreview');
    const fileNameEl  = document.getElementById('fileName');

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            const f = fileInput.files[0];
            if (f) {
                fileNameEl.textContent = f.name;
                filePreview.style.display = 'flex';
                fileDrop.style.borderColor = 'var(--green-500)';
                fileDrop.style.background  = 'var(--green-50)';
            }
        });
    }

    // Drag & drop visual
    ['dragenter','dragover'].forEach(ev => {
        fileDrop?.addEventListener(ev, e => { e.preventDefault(); fileDrop.classList.add('dragover'); });
    });
    ['dragleave','drop'].forEach(ev => {
        fileDrop?.addEventListener(ev, e => { e.preventDefault(); fileDrop.classList.remove('dragover'); });
    });

    // ── Submit loading state ──
    const form      = document.getElementById('paymentForm');
    const submitBtn = document.getElementById('submitBtn');

    form?.addEventListener('submit', () => {
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
        setTimeout(() => {
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
        }, 10000);
    });

    // ── Smooth scroll to form from plan CTAs ──
    document.querySelectorAll('a[href="#payment-form"]').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            document.getElementById('payment-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
</script>

</body>
</html>
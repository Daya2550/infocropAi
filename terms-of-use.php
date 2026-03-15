<?php
session_start();
require_once 'db.php';
require_once 'config.php';

// Fetch System Settings
$stmt = $pdo->query("SELECT * FROM settings");
$sys_settings = [];
foreach ($stmt->fetchAll() as $s) {
    $sys_settings[$s['setting_key']] = $s['setting_value'];
}
$site_name = $sys_settings['site_name'] ?? 'InfoCrop AI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Terms of Use – <?php echo htmlspecialchars($site_name); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/global.css">
  <style>
    .legal-container {
      max-width: 800px;
      margin: 60px auto;
      padding: 40px;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    }
    .legal-container h1 {
      font-size: 2.2rem;
      color: #1b5e20;
      margin-bottom: 20px;
      border-bottom: 2px solid #e8f5e9;
      padding-bottom: 10px;
    }
    .legal-container h2 {
      font-size: 1.4rem;
      color: #2e7d32;
      margin-top: 30px;
      margin-bottom: 15px;
    }
    .legal-container p, .legal-container li {
      font-size: 1rem;
      color: #444;
      line-height: 1.7;
      margin-bottom: 15px;
    }
    .legal-container ul {
      margin-left: 20px;
      margin-bottom: 15px;
    }
  </style>
  <?php include 'partials/header.php'; ?>
</head>
<body>

<main class="page-main" style="padding-top: 20px;">
  <div class="legal-container">
    <h1>Terms of Use</h1>
    <p><em>Last Updated: <?php echo date('F d, Y'); ?></em></p>
    
    <p>Welcome to <strong><?php echo htmlspecialchars($site_name); ?></strong>. By accessing or using our platform, you agree to be bound by these Terms of Use. Please read them carefully.</p>

    <h2>1. Acceptance of Terms</h2>
    <p>By registering for, accessing, or using <?php echo htmlspecialchars($site_name); ?>, you acknowledge that you have read, understood, and agree to comply with these terms. If you do not agree, you must not use our services.</p>

    <h2>2. Description of Service</h2>
    <p><?php echo htmlspecialchars($site_name); ?> provides AI-powered agricultural advisory services for farmers. The system generates farm plans, market outlooks, pest management strategies, and other agricultural recommendations based on user input and artificial intelligence models.</p>

    <h2>3. Disclaimer of AI Accuracy</h2>
    <p>The guidance provided by <?php echo htmlspecialchars($site_name); ?> is powered by Artificial Intelligence (Gemini AI). While we strive for accuracy based on historical data and agronomic principles, the outputs are probabilistic and estimations.</p>
    <ul>
      <li><strong>No Guarantees:</strong> We do not guarantee crop yields, market prices, or successful farming outcomes.</li>
      <li><strong>Weather/Market Estimates:</strong> Weather forecasts and market price projections are for informational purposes only and are subject to real-world fluctuations.</li>
      <li><strong>Professional Verification:</strong> Always consult with a local Krishi Vigyan Kendra (KVK) or certified agronomist before applying chemical fertilizers or pesticides.</li>
    </ul>

    <h2>4. User Responsibilities</h2>
    <p>You agree to provide accurate information about your farm, land area, and inputs to ensure the AI can formulate the best possible plan. You are responsible for maintaining the confidentiality of your account credentials.</p>

    <h2>5. Limitation of Liability</h2>
    <p>To the maximum extent permitted by law, <?php echo htmlspecialchars($site_name); ?> and its creators shall not be liable for any direct, indirect, incidental, or consequential damages resulting from the use or inability to use the service, including crop failure or financial losses.</p>

    <h2>6. Changes to Terms</h2>
    <p>We reserve the right to modify these Terms of Use at any time. Continued use of the platform after any such changes constitutes your consent to such changes.</p>
  </div>
</main>

<?php include 'partials/footer.php'; ?>
</body>
</html>

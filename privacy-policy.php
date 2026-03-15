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
  <title>Privacy Policy – <?php echo htmlspecialchars($site_name); ?></title>
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
    <h1>Privacy Policy</h1>
    <p><em>Last Updated: <?php echo date('F d, Y'); ?></em></p>
    
    <p>At <strong><?php echo htmlspecialchars($site_name); ?></strong>, maintaining the privacy and security of our users' personal and agricultural data is our priority. This Privacy Policy details how we collect, use, and protect your information.</p>

    <h2>1. Information We Collect</h2>
    <ul>
      <li><strong>Personal Details:</strong> Name, phone number, location (City/State), and device information when you sign up.</li>
      <li><strong>Farm Data:</strong> Land area, crop preferences, budget, soil information, equipment details, and photos uploaded for Smart Check.</li>
      <li><strong>Usage Metrics:</strong> AI query logs, credit consumption, and diagnostic logs to improve our service.</li>
    </ul>

    <h2>2. How We Use Your Information</h2>
    <p>We use your farm parameters to construct AI prompts sent to the Google Gemini models. We use your personal data to securely manage your account and subscription limits.</p>

    <h2>3. Data Sharing with Third Parties</h2>
    <p>To generate localized advice, we send non-personally identifiable farm data (like crop type, soil type, and region) to Google's Gemini API infrastructure. Google processes this information to return the farm plan. We do not sell your personal data or phone number to external advertisers, direct marketers, or data brokers.</p>

    <h2>4. Data Storage and Security</h2>
    <p>We store your data in secure cloud databases. While we implement industry-standard encryption protocols (including password hashing), no method of transmission over the Internet is 100% secure.</p>

    <h2>5. Your Rights</h2>
    <p>You have the right to access the reports and data generated within your account. If you wish to delete your data or account, you can contact our support team to initiate account removal procedures.</p>

    <h2>6. Updates to This Policy</h2>
    <p>We reserve the right to amend this Privacy Policy periodically. Users will be notified of material changes through their dashboard or via website announcements.</p>
  </div>
</main>

<?php include 'partials/footer.php'; ?>
</body>
</html>

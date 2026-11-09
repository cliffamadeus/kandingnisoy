<?php
session_start();

require_once __DIR__ . '/../includes/env-loader.php';
loadEnv(__DIR__ . '/../.env');

date_default_timezone_set('Asia/Manila');

// Email configuration (for verification)
define('MAIL_FROM', 'noreply@yourdomain.com');
define('BASE_URL', 'http://localhost/kandingnisoy'); // Adjust to your base URL

define('DB_HOST', 'localhost');
define('DB_NAME', 'soy_php_pdo');
define('DB_USER', 'root');
define('DB_PASS', '');

// ============================================================
// TEXTBEE SMS GATEWAY CONFIGURATION
// ============================================================
define('TEXTBEE_API_KEY', getenv('TEXTBEE_API_KEY') ?: '');
define('TEXTBEE_DEVICE_ID', getenv('TEXTBEE_DEVICE_ID') ?: '');
define('OPENWEATHER_API_KEY', getenv('OPENWEATHER_API_KEY') ?: '');
define('TEXTBEE_API_URL', 'https://api.textbee.dev/api/v1');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

?>
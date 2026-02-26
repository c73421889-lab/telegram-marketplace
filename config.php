<?php
/**
 * Telegram Marketplace - Configuration File
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'telegram_marketplace');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'Telegram Marketplace');
define('APP_URL', 'https://your-domain.com');
define('MINI_APP_URL', 'https://your-domain.com/app');
define('ADMIN_URL', 'https://your-domain.com/admin');

// Security Settings
define('ENCRYPTION_KEY', 'your-256-bit-encryption-key-here');
define('TELEGRAM_BOT_TOKEN', 'your-telegram-bot-token');
define('TELEGRAM_MINI_APP_URL', 'https://t.me/YourBotUsername/app');

// PaymentPoint Configuration
define('PAYMENTPOINT_API_KEY', 'your-paymentpoint-api-key');
define('PAYMENTPOINT_SECRET_KEY', 'your-paymentpoint-secret-key');
define('PAYMENTPOINT_PUBLIC_KEY', 'your-paymentpoint-public-key');

// Admin Settings
define('ADMIN_FOLDER', 'admin');
define('ADMIN_PASSWORD', 'extra-admin-password');
define('ADMIN_IP_WHITELIST', ['127.0.0.1']); // Set to empty for no IP restriction

// Payment Settings
define('DEFAULT_COMMISSION', 10); // 10%
define('WITHDRAWAL_FEE', 50); // NGN
define('MIN_WITHDRAWAL', 1000); // NGN
define('MAX_WITHDRAWAL', 500000); // NGN

// Feature Toggles
define('ESCROW_ENABLED', true);
define('KYC_ENABLED', false);
define('AFFILIATE_ENABLED', true);
define('MAINTENANCE_MODE', false);

// Security Headers
define('FORCE_HTTPS', true);
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCK_TIME', 900); // 15 minutes

// Logging
define('LOG_PATH', __DIR__ . '/logs/');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARNING, ERROR

// Cron Jobs
define('CRON_SECRET', 'your-cron-secret-key');
define('CRON_EXPIRE_SELLERS', true); // Auto-expire premium sellers
define('CRON_PROCESS_WITHDRAWALS', true);

// Email Configuration (Optional)
define('MAIL_FROM', 'noreply@telegram-marketplace.com');
define('MAIL_HOST', 'smtp.mailtrap.io');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', '');
define('MAIL_PASSWORD', '');

// Rate Limiting
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 60); // seconds

// PDO Connection
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Database Connection Error: ' . $e->getMessage());
    die('Database connection failed. Please check configuration.');
}

// Helper Functions
function get_setting($key) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : null;
}

function set_setting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
    return $stmt->execute([$key, $value, $value]);
}

function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
           $_SERVER['SERVER_PORT'] == 443;
}

function start_secure_session() {
    if (FORCE_HTTPS && !is_https()) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', FORCE_HTTPS ? 1 : 0);
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function log_action($level, $message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $log_file = LOG_PATH . date('Y-m-d') . '.log';
    
    if (!is_dir(LOG_PATH)) {
        mkdir(LOG_PATH, 0755, true);
    }
    
    $log_message = "[$timestamp] [$level] $message";
    if (!empty($context)) {
        $log_message .= ' ' . json_encode($context);
    }
    $log_message .= PHP_EOL;
    
    error_log($log_message, 3, $log_file);
}

?>
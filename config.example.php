<?php
// config.example.php — TEMPLATE. Copy to config.php and fill in real values.
// config.php is gitignored; never commit real credentials.

$isApiRequest = (
    $_SERVER['REQUEST_METHOD'] === 'POST' && 
    (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false || 
     isset($_POST['action'])) &&
    !isset($_GET['user_id']) && // Page requests have user_id
    basename($_SERVER['SCRIPT_NAME']) !== 'payment.php' && // Don't set for payment page
    basename($_SERVER['SCRIPT_NAME']) !== 'success.php' &&  // Don't set for success page
    basename($_SERVER['SCRIPT_NAME']) !== 'index.php' &&    // Don't set for index page
    basename($_SERVER['SCRIPT_NAME']) !== 'manage.php' &&   // account page (not API)
    basename($_SERVER['SCRIPT_NAME']) !== 'admin.php'       // admin panel (not API)
);

// Set proper headers for API responses only
if ($isApiRequest && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Environment setting - CHANGE THIS FOR PRODUCTION
define('ENVIRONMENT', 'development'); // 'development' or 'production'

// Database configuration for A.Email
define('DB_HOST', 'localhost');
define('DB_USER', 'a.email');     // Change this to your MySQL username
define('DB_PASS', 'CHANGE_ME_db_password');     // Change this to your MySQL password
define('DB_NAME', 'a.email');

// Stripe configuration - USE LIVE KEYS FOR PRODUCTION
if (ENVIRONMENT === 'production') {
    // LIVE STRIPE KEYS - Get these from Stripe Dashboard
    define('STRIPE_SECRET_KEY', 'sk_test_CHANGE_ME_stripe_secret_key'); // Replace with your live secret key
    define('STRIPE_PUBLISHABLE_KEY', 'pk_test_CHANGE_ME_stripe_publishable_key'); // Replace with your live publishable key
    define('STRIPE_WEBHOOK_SECRET', 'whsec_CHANGE_ME_webhook_secret'); // Replace with your live webhook secret
} else {
    define('STRIPE_SECRET_KEY', 'sk_test_CHANGE_ME_stripe_secret_key'); // Replace with your live secret key
    define('STRIPE_PUBLISHABLE_KEY', 'pk_test_CHANGE_ME_stripe_publishable_key'); // Replace with your live publishable key
    define('STRIPE_WEBHOOK_SECRET', 'whsec_CHANGE_ME_webhook_secret'); // Replace with your live webhook secret
}

// Site configuration - UPDATED FOR A.EMAIL
define('SITE_URL', 'https://a.email'); // Your production domain
define('CURRENCY', 'HKD');
define('SITE_NAME', 'A.Email');
define('SITE_TAGLINE', 'Email. Simplified.');
define('DOMAIN_SUFFIX', '@a.email');

// A.Email specific settings
define('MAILBOX_API_KEY', 'CHANGE_ME_mailbox_api_key'); // Your mailbox API key
define('MAILBOX_API_URL', 'https://your-mailcow-host/api/v1/add/mailbox'); // Mailbox API endpoint

// Verify TLS certificates on outbound API calls (mailcow). Toggle here: set to false ONLY
// if your mailbox API host uses a self-signed cert (insecure — not recommended).
define('VERIFY_TLS', true);

// Pricing configuration
define('FREE_ACCOUNT_MIN_LENGTH', 4); // Minimum length for free accounts (>3 chars)
define('SHORT_NAME_PRICE', 100.00); // Price for 2-3 character names
define('SINGLE_CHAR_PRICE', 1000.00); // Price for 1 character names
define('DAILY_FREE_LIMIT_PER_IP', 5); // Maximum free accounts per IP per day


// Forbidden words array - Used in pricing validation
$FORBIDDEN_WORDS = [
    'admin', 'administrator', 'root', 'support', 'help', 'info', 'mail', 'email', 
    'postmaster', 'webmaster', 'noreply', 'no-reply', 'api', 'www', 'ftp', 'smtp', 
    'pop', 'imap', 'abuse', 'security', 'billing', 'sales', 'contact', 'service',
    'team', 'staff', 'official', 'system', 'server', 'network', 'tech', 'support',
    'moderator', 'mod', 'owner', 'ceo', 'cto', 'cfo', 'president', 'director',
    'manager', 'supervisor', 'lead', 'head', 'chief', 'master', 'super', 'sudo',
    'test', 'demo', 'sample', 'example', 'null', 'undefined', 'void', 'delete',
    'remove', 'ban', 'block', 'suspend', 'disable', 'deactivate', 'terminate',
    'fuck', 'shit', 'damn', 'hell', 'ass', 'bitch', 'bastard', 'crap', 'piss',
    'terrorist', 'bomb', 'kill', 'murder', 'suicide', 'rape',
    'porn', 'sex', 'nude', 'naked', 'xxx', 'adult', 'escort', 'prostitute',
    'drug', 'cocaine', 'heroin', 'meth', 'weed', 'marijuana', 'cannabis',
    'hack', 'hacker', 'exploit', 'virus', 'malware', 'phishing', 'scam',
    'spam', 'fraud', 'fake', 'illegal', 'stolen', 'pirate', 'crack',
    'password', 'login', 'signin', 'signup', 'register', 'account',
    'payment', 'paypal', 'visa', 'mastercard', 'amex', 'discover',
    'microsoft', 'google', 'apple', 'facebook', 'twitter', 'instagram',
    'youtube', 'amazon', 'ebay', 'paypal', 'netflix', 'spotify',
    'admin', 'administrator', 'root', 'system', 'operator', 'hostmaster',
    'webmaster', 'postmaster', 'info', 'support', 'contact', 'help', 'abuse',
    'security', 'ssladmin', 'ssladministrator', 'sslwebmaster', 'sysadmin',
    'ispadmin', 'hostadmin', 'staff', 'test', 'test1', 'test2', 'test3',
    'user', 'username', 'guest', 'demo', 'example', 'mail', 'noreply',
    'no-reply', 'nobody', 'somebody', 'anybody', 'sales', 'billing',
    'accounting', 'accounts', 'marketing', 'service', 'services', 'admin1',
    'admin2', 'admin3', 'admin4', 'admin5', 'admin6', 'admin7', 'admin8',
    'admin9', 'admin10', 'superuser', 'manager', 'supervisor',
    'gov', 'government', 'www', 'vip',
    // Add your own blocked words / reserved usernames here.
];

// Security settings for production
if (ENVIRONMENT === 'production') {
    // Disable error display in production
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    
    // Enable error logging
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/error.log');
} else {
    // Enable errors for development
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Database connection function
function getDBConnection() {
    static $mysqli = null;
    
    if ($mysqli === null) {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($mysqli->connect_error) {
            if (ENVIRONMENT === 'production') {
                // Don't expose database details in production
                logError("Database connection failed: " . $mysqli->connect_error);
                die(json_encode(['error' => 'Service temporarily unavailable']));
            } else {
                die("Connection failed: " . $mysqli->connect_error);
            }
        }
        
        $mysqli->set_charset("utf8mb4");
    }
    
    return $mysqli;
}

// Redact password-like fields (recursively) before anything is written to a log.
function redactSensitive($value) {
    static $sensitive = ['password', 'password2', 'confirmpassword', 'confirm_password',
        'pass', 'pwd', 'new_password', 'old_password', 'current_password'];
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = (is_string($k) && in_array(strtolower($k), $sensitive, true))
                ? '[redacted]' : redactSensitive($v);
        }
        return $out;
    }
    return $value;
}

// Enhanced error logging function
function logError($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . " [" . getClientIP() . "] - " . $message;

    if ($data) {
        // Never write credentials to logs — redact password-like fields at any depth.
        $logMessage .= " - Data: " . json_encode(redactSensitive($data), JSON_UNESCAPED_UNICODE);
    }
    
    // Add request context in production
    if (ENVIRONMENT === 'production') {
        $logMessage .= " - User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
        $logMessage .= " - Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'Direct');
    }
    
    error_log($logMessage . "\n", 3, __DIR__ . '/app.log');
    
    // Also log critical errors to separate file
    if (strpos(strtolower($message), 'error') !== false || 
        strpos(strtolower($message), 'failed') !== false) {
        error_log($logMessage . "\n", 3, __DIR__ . '/critical.log');
    }
}

// Get client IP address
function getClientIP() {
    $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            return trim($ips[0]);
        }
    }
    
    return 'Unknown';
}

// Success response function
function sendSuccessResponse($message, $data = null) {
    // Ensure JSON headers for API responses
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Error response function
function sendErrorResponse($message, $code = 400, $data = null) {
    // Ensure JSON headers for API responses
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
    }
    
    http_response_code($code);
    
    // Don't expose sensitive information in production
    if (ENVIRONMENT === 'production' && $code >= 500) {
        $message = 'Internal server error occurred';
        logError('Internal server error: ' . $message, $data);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Rate limiting function (enhanced for A.Email)
function checkRateLimit($identifier = null, $maxRequests = 60, $timeWindow = 3600) {
    if (ENVIRONMENT !== 'production') {
        return true; // Skip rate limiting in development
    }
    
    $identifier = $identifier ?: getClientIP();
    $cacheFile = __DIR__ . '/cache/rate_limit_' . md5($identifier) . '.txt';
    
    // Create cache directory if it doesn't exist
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $now = time();
    $requests = [];
    
    // Load existing requests
    if (file_exists($cacheFile)) {
        $data = file_get_contents($cacheFile);
        $requests = json_decode($data, true) ?: [];
    }
    
    // Remove old requests outside time window
    $requests = array_filter($requests, function($timestamp) use ($now, $timeWindow) {
        return ($now - $timestamp) < $timeWindow;
    });
    
    // Check if limit exceeded
    if (count($requests) >= $maxRequests) {
        logError('Rate limit exceeded', [
            'identifier' => $identifier,
            'requests' => count($requests),
            'limit' => $maxRequests
        ]);
        return false;
    }
    
    // Add current request
    $requests[] = $now;
    
    // Save updated requests
    file_put_contents($cacheFile, json_encode($requests));
    
    return true;
}

// Input sanitization function
function sanitizeInput($input, $type = 'string') {
    switch ($type) {
        case 'email':
            return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
        case 'username':
            return preg_replace('/[^a-zA-Z0-9.-]/', '', trim($input)); // Updated regex for A.Email
        case 'numeric':
            return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        case 'string':
        default:
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Validate CSRF token (if using CSRF protection)
function validateCSRFToken($token) {
    if (ENVIRONMENT !== 'production') {
        return true; // Skip CSRF in development
    }
    
    session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Generate CSRF token
function generateCSRFToken() {
    session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// A.Email specific functions

// Get full email address
function getFullEmail($username) {
    return $username . DOMAIN_SUFFIX;
}

// Validate A.Email username with forbidden words check
function validateAEmailUsername($username) {
    global $FORBIDDEN_WORDS;
    
    $username = trim($username);
    
    if (empty($username)) {
        return ['valid' => false, 'error' => 'Username cannot be empty'];
    }
    
    if (strlen($username) < 1 || strlen($username) > 50) {
        return ['valid' => false, 'error' => 'Username must be between 1 and 50 characters'];
    }
    
    // Regex check: only allow A-Za-z0-9.-
    if (!preg_match('/^[a-zA-Z0-9.-]+$/', $username)) {
        return ['valid' => false, 'error' => 'Username can only contain letters, numbers, dots, and hyphens'];
    }
    
    if (preg_match('/^[.-]|[.-]$/', $username)) {
        return ['valid' => false, 'error' => 'Username cannot start or end with dots or hyphens'];
    }
    
    // Check for forbidden words (case insensitive substring check)
    $usernameLower = strtolower($username);
    foreach ($FORBIDDEN_WORDS as $forbidden) {
        if (strpos($usernameLower, $forbidden) !== false) {
            return ['valid' => false, 'error' => 'Username contains forbidden word: ' . $forbidden];
        }
    }
    
    return ['valid' => true, 'username' => strtolower($username)];
}

// Canonical, length-based pricing — the SINGLE source of truth, used by pricing.php
// (availability/quote), register.php (what is charged) and payment.php (Stripe amount).
// Monthly is derived as one tenth of the lifetime price.
function calculateAEmailPricing($username) {
    $username = strtolower(trim($username));
    $length = strlen($username);

    if ($length >= FREE_ACCOUNT_MIN_LENGTH) {
        return [
            'type' => 'free',
            'username' => $username,
            'monthly_price' => 0.00,
            'lifetime_price' => 0.00,
            'is_free' => true,
            'length' => $length
        ];
    } elseif ($length >= 2) { // 2–3 characters
        return [
            'type' => 'premium_short',
            'username' => $username,
            'monthly_price' => round(SHORT_NAME_PRICE / 10, 2),
            'lifetime_price' => (float) SHORT_NAME_PRICE,
            'is_free' => false,
            'length' => $length
        ];
    } elseif ($length == 1) {
        return [
            'type' => 'premium_single',
            'username' => $username,
            'monthly_price' => round(SINGLE_CHAR_PRICE / 10, 2),
            'lifetime_price' => (float) SINGLE_CHAR_PRICE,
            'is_free' => false,
            'length' => $length
        ];
    }

    return [
        'type' => 'invalid',
        'username' => $username,
        'monthly_price' => 0.00,
        'lifetime_price' => 0.00,
        'is_free' => false,
        'error' => 'Invalid username length'
    ];
}

// Resolve the charge amount (in dollars) for a chosen plan from canonical pricing.
function getPlanAmount($pricingData, $planType) {
    if (!empty($pricingData['is_free'])) {
        return 0.00;
    }
    if ($planType === 'monthly') {
        return (float) ($pricingData['monthly_price'] ?? 0);
    }
    if ($planType === 'lifetime') {
        return (float) ($pricingData['lifetime_price'] ?? 0);
    }
    return 0.00;
}

// Stripe price IDs are no longer used: payment.php builds an inline price_data line item
// from the server-computed amount, so no STRIPE_PRICE_MAPPING is required.

// Check daily free account limit for IP
function checkDailyFreeAccountLimit($clientIP) {
    try {
        $mysqli = getDBConnection();
        $today = date('Y-m-d');
        
        logError('DEBUG: Checking daily free account limit', [
            'client_ip' => $clientIP,
            'date' => $today,
            'limit' => DAILY_FREE_LIMIT_PER_IP
        ]);
        
        // Direct query (remove stored procedure logic for simplicity)
        $sql = "
            SELECT COUNT(*) as count 
            FROM users 
            WHERE client_ip = ? 
            AND DATE(created_at) = ? 
            AND plan_type = 'free'
            AND payment_status = 'completed'
        ";
        
        $checkStmt = $mysqli->prepare($sql);
        
        // Check if prepare failed BEFORE calling bind_param
        if ($checkStmt === false) {
            throw new Exception('Failed to prepare statement: ' . $mysqli->error);
        }
        
        $checkStmt->bind_param("ss", $clientIP, $today);
        
        if (!$checkStmt->execute()) {
            $error = 'Failed to execute statement: ' . $checkStmt->error;
            $checkStmt->close();
            throw new Exception($error);
        }
        
        $result = $checkStmt->get_result();
        if ($result === false) {
            $error = 'Failed to get result: ' . $checkStmt->error;
            $checkStmt->close();
            throw new Exception($error);
        }
        
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        $count = (int)($row['count'] ?? 0);
        $allowed = $count < DAILY_FREE_LIMIT_PER_IP;
        
        logError('DEBUG: Daily limit check result', [
            'count' => $count,
            'allowed' => $allowed,
            'limit' => DAILY_FREE_LIMIT_PER_IP,
            'remaining' => DAILY_FREE_LIMIT_PER_IP - $count
        ]);
        
        return [
            'allowed' => $allowed,
            'count' => $count,
            'remaining' => DAILY_FREE_LIMIT_PER_IP - $count,
            'message' => $allowed ? 'Within limit' : 'Daily limit reached. You can only create ' . DAILY_FREE_LIMIT_PER_IP . ' free accounts per day.'
        ];
        
    } catch (Exception $e) {
        logError('Error checking daily free account limit', [
            'client_ip' => $clientIP,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Return conservative result on error - allow but log the issue
        return [
            'allowed' => true,
            'count' => 0,
            'remaining' => DAILY_FREE_LIMIT_PER_IP,
            'message' => 'Unable to verify daily limit, proceeding with caution.'
        ];
    }
}

// Increment daily free account count
function incrementDailyFreeAccountCount($clientIP) {
    try {
        $mysqli = getDBConnection();
        $today = date('Y-m-d');
        
        // Use stored procedure if available
        if (function_exists('mysqli_stmt_init')) {
            $stmt = $mysqli->prepare("CALL IncrementDailyIPCount(?, ?)");
            if ($stmt) {
                $stmt->bind_param("ss", $clientIP, $today);
                $stmt->execute();
                $stmt->close();
                return true;
            }
        }
        
        // Fallback to direct query
        $stmt = $mysqli->prepare("
            INSERT INTO daily_ip_limits (client_ip, date, free_account_count)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE 
                free_account_count = free_account_count + 1,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("ss", $clientIP, $today);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
        
    } catch (Exception $e) {
        logError('Error incrementing daily free account count', [
            'client_ip' => $clientIP,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// Fixed createMailboxViaAPI function that handles array responses
// Replace the existing function in your config.php

function createMailboxViaAPI($username, $password, $fullName) {
    $startTime = microtime(true);
    
    // Initial logging
    logError('MAILBOX_API: Starting mailbox creation', [
        'username' => $username,
        'password_length' => strlen($password),
        'full_name' => $fullName,
        'api_url' => MAILBOX_API_URL,
        'api_key_present' => !empty(MAILBOX_API_KEY),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, MAILBOX_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, VERIFY_TLS);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, VERIFY_TLS ? 2 : 0);
    
    $data = array(
        "active" => "1",
        "domain" => "a.email",
        "local_part" => $username,
        "name" => $fullName,
        "password" => $password,
        "password2" => $password,
        "quota" => "10",
        "rl_frame" => "d",
        "rl_value" => "100"
    );
    
    $json_data = json_encode($data);
    $headers = array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_data),
        'X-API-Key: ' . MAILBOX_API_KEY
    );
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    $endTime = microtime(true);
    $executionTime = ($endTime - $startTime) * 1000;
    
    // Log basic response info
    logError('MAILBOX_API: Response received', [
        'username' => $username,
        'execution_time_ms' => round($executionTime, 2),
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response_length' => strlen($response),
        'raw_response' => $response
    ]);
    
    // Check for CURL errors
    if ($response === false) {
        logError('MAILBOX_API: CURL execution failed', [
            'username' => $username,
            'curl_error' => $curlError
        ]);
        throw new Exception("Mailbox API Error: $curlError");
    }
    
    // Check HTTP status code
    if ($httpCode !== 200) {
        logError('MAILBOX_API: HTTP error', [
            'username' => $username,
            'http_code' => $httpCode,
            'response' => $response
        ]);
        throw new Exception("Mailbox API returned HTTP $httpCode");
    }
    
    // Parse JSON response
    $responseData = json_decode($response, true);
    $jsonError = json_last_error();
    
    if ($jsonError !== JSON_ERROR_NONE) {
        logError('MAILBOX_API: JSON parsing failed', [
            'username' => $username,
            'json_error' => json_last_error_msg(),
            'raw_response' => $response
        ]);
        throw new Exception("Mailbox API response parsing failed: " . json_last_error_msg());
    }
    
    // Handle array responses (multiple operations)
    if (is_array($responseData) && isset($responseData[0])) {
        logError('MAILBOX_API: Processing array response', [
            'username' => $username,
            'response_count' => count($responseData),
            'response_data' => $responseData
        ]);
        
        $successCount = 0;
        $errorCount = 0;
        $mailboxCreated = false;
        $messages = [];
        
        foreach ($responseData as $index => $operation) {
            if (isset($operation['type'])) {
                if ($operation['type'] === 'success') {
                    $successCount++;
                    
                    // Check if this is the mailbox creation operation
                    if (isset($operation['log']) && is_array($operation['log']) && 
                        count($operation['log']) >= 3 && $operation['log'][1] === 'add' && 
                        $operation['log'][2] === 'mailbox') {
                        $mailboxCreated = true;
                    }
                    
                    // Extract message
                    if (isset($operation['msg']) && is_array($operation['msg']) && !empty($operation['msg'])) {
                        $messages[] = $operation['msg'][0] . (isset($operation['msg'][1]) ? ': ' . $operation['msg'][1] : '');
                    }
                } else {
                    $errorCount++;
                    logError('MAILBOX_API: Operation failed', [
                        'username' => $username,
                        'operation_index' => $index,
                        'operation' => $operation
                    ]);
                }
            }
        }
        
        logError('MAILBOX_API: Array response summary', [
            'username' => $username,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'mailbox_created' => $mailboxCreated,
            'messages' => $messages
        ]);
        
        // Consider it successful if we have successes and specifically mailbox was created
        if ($successCount > 0 && $mailboxCreated && $errorCount === 0) {
            $successMessage = implode('; ', $messages) ?: 'Mailbox created successfully';
            
            logError('MAILBOX_API: Array response - SUCCESS', [
                'username' => $username,
                'message' => $successMessage,
                'operations_completed' => $successCount
            ]);
            
            return [
                'success' => true,
                'message' => $successMessage,
                'data' => $responseData
            ];
        } else {
            $errorMessage = $errorCount > 0 ? 'Some operations failed' : 'Mailbox creation not confirmed';
            
            logError('MAILBOX_API: Array response - FAILURE', [
                'username' => $username,
                'error_message' => $errorMessage,
                'mailbox_created' => $mailboxCreated,
                'success_count' => $successCount,
                'error_count' => $errorCount
            ]);
            
            throw new Exception("Mailbox creation failed: $errorMessage");
        }
    }
    
    // Handle single object response (original logic)
    if (is_array($responseData)) {
        $isSuccess = false;
        $successMessage = '';
        $errorMessage = '';
        
        // Check different possible success indicators
        if (isset($responseData['type']) && $responseData['type'] === 'success') {
            $isSuccess = true;
            $successMessage = $responseData['msg'] ?? 'Mailbox created successfully';
        } elseif (isset($responseData['success']) && $responseData['success'] === true) {
            $isSuccess = true;
            $successMessage = $responseData['message'] ?? $responseData['msg'] ?? 'Mailbox created successfully';
        } elseif (isset($responseData['status']) && $responseData['status'] === 'success') {
            $isSuccess = true;
            $successMessage = $responseData['message'] ?? $responseData['msg'] ?? 'Mailbox created successfully';
        } else {
            // Try to determine error message
            if (isset($responseData['msg'])) {
                $errorMessage = $responseData['msg'];
            } elseif (isset($responseData['message'])) {
                $errorMessage = $responseData['message'];
            } elseif (isset($responseData['error'])) {
                $errorMessage = $responseData['error'];
            } else {
                $errorMessage = "Unknown response format";
            }
        }
        
        logError('MAILBOX_API: Single response processing', [
            'username' => $username,
            'is_success' => $isSuccess,
            'success_message' => $successMessage,
            'error_message' => $errorMessage,
            'response_data' => $responseData
        ]);
        
        if ($isSuccess) {
            return [
                'success' => true,
                'message' => $successMessage,
                'data' => $responseData
            ];
        } else {
            throw new Exception("Mailbox creation failed: $errorMessage");
        }
    }
    
    // If we get here, something unexpected happened
    logError('MAILBOX_API: Unexpected response format', [
        'username' => $username,
        'response_type' => gettype($responseData),
        'response_data' => $responseData
    ]);
    
    throw new Exception("Mailbox API returned unexpected response format");
}

function sendEmailNotification($to, $subject, $message, $type = 'general') {
    try {
        // Use PHPMailer with SMTP
        require_once 'vendor/autoload.php'; // Make sure this path is correct for your setup
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = 'smtp.example.com'; // Replace with your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'support@a.email'; // Replace with your email
        $mail->Password = 'CHANGE_ME_smtp_password'; // Replace with your password
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Email settings
        $mail->setFrom('support@a.email', SITE_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo('support@a.email', SITE_NAME);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        
        $result = $mail->send();
        
        // Rest of your logging code remains the same
        if ($result) {
            logError('Email sent successfully', [
                'to' => $to,
                'subject' => $subject,
                'type' => $type
            ]);
        } else {
            logError('Email sending failed', [
                'to' => $to,
                'subject' => $subject,
                'type' => $type
            ]);
        }
        
        // Log to email_logs table
        try {
            $mysqli = getDBConnection();
            $stmt = $mysqli->prepare("
                INSERT INTO email_logs (email_to, email_type, subject, delivery_status, sent_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $deliveryStatus = $result ? 'sent' : 'failed';
            $stmt->bind_param("ssss", $to, $type, $subject, $deliveryStatus);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            logError('Failed to log email to database', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $result;
        
    } catch (Exception $e) {
        logError('Email notification error', [
            'to' => $to,
            'subject' => $subject,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// Format pricing for display
function formatPricing($amount) {
    if ($amount == 0) {
        return 'Free';
    }
    return '$' . number_format($amount, 2);
}

// Format plan type for display
function formatPlanType($planType) {
    switch($planType) {
        case 'free': return 'Free Account';
        case 'lifetime': return 'Lifetime Premium';
        default: return 'Standard Account';
    }
}

// Get system setting
function getSystemSetting($key, $default = null) {
    try {
        $mysqli = getDBConnection();
        $stmt = $mysqli->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return $row['setting_value'];
        }
        
        $stmt->close();
        return $default;
        
    } catch (Exception $e) {
        logError('Error getting system setting', [
            'key' => $key,
            'error' => $e->getMessage()
        ]);
        return $default;
    }
}

// Set system setting
function setSystemSetting($key, $value, $description = '') {
    try {
        $mysqli = getDBConnection();
        $stmt = $mysqli->prepare("
            INSERT INTO system_settings (setting_key, setting_value, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                description = VALUES(description),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("sss", $key, $value, $description);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
        
    } catch (Exception $e) {
        logError('Error setting system setting', [
            'key' => $key,
            'value' => $value,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// Clean up old logs and cache files
function cleanupOldFiles() {
    try {
        // Clean up old log files (keep last 30 days)
        $logFiles = glob(__DIR__ . '/*.log');
        $thirtyDaysAgo = time() - (30 * 24 * 60 * 60);
        
        foreach ($logFiles as $file) {
            if (filemtime($file) < $thirtyDaysAgo) {
                unlink($file);
            }
        }
        
        // Clean up old cache files (keep last 24 hours)
        $cacheFiles = glob(__DIR__ . '/cache/rate_limit_*.txt');
        $twentyFourHoursAgo = time() - (24 * 60 * 60);
        
        foreach ($cacheFiles as $file) {
            if (filemtime($file) < $twentyFourHoursAgo) {
                unlink($file);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        logError('Error cleaning up old files', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// Initialize database connection on first load
try {
    $mysqli = getDBConnection();
    
    // Run cleanup occasionally (1% chance)
    if (rand(1, 100) === 1) {
        cleanupOldFiles();
    }

} catch (Exception $e) {
    logError('Database initialization error', [
        'error' => $e->getMessage()
    ]);
}

// Export forbidden words for use in other files
function getForbiddenWords() {
    global $FORBIDDEN_WORDS;
    return $FORBIDDEN_WORDS;
}
?>
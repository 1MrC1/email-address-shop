<?php
// pricing.php - Updated pricing calculation system for A.Email
require_once 'config.php';



// Pricing comes from the canonical calculateAEmailPricing() in config.php
// (single source of truth). Forbidden words are enforced by validateUsername() below.

function isUsernameAvailable($username, $mysqli) {
    $username = strtolower(trim($username));
    
    // Check for existing username with completed status only
    $checkStmt = $mysqli->prepare("
        SELECT id, payment_status, created_at 
        FROM users 
        WHERE username = ? AND payment_status = 'completed' 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $checkStmt->bind_param("s", $username);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $checkStmt->close();
        
        return [
            'available' => false, 
            'reason' => 'completed',
            'completed_at' => $user['created_at']
        ];
    }
    
    $checkStmt->close();
    return ['available' => true, 'reason' => 'available'];
}

function validateUsername($username) {
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
    
    // Check for forbidden words (case insensitive)
    $usernameLower = strtolower($username);
    foreach ($FORBIDDEN_WORDS as $forbidden) {
        if (strpos($usernameLower, $forbidden) !== false) {
            return ['valid' => false, 'error' => 'Username contains forbidden word: ' . $forbidden];
        }
    }
    
    return ['valid' => true, 'username' => strtolower($username)];
}



// Custom response functions that handle business logic errors correctly
function sendBusinessErrorResponse($message, $data = null) {
    // Business logic errors (like username taken) should return 200 OK with success: false
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    http_response_code(200); // Always 200 for business logic errors
    
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Main execution
try {
    $mysqli = getDBConnection();
    
    // Handle different request methods
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $input = $_POST;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $input = $_GET;
    } else {
        sendErrorResponse('Method not allowed', 405);
    }
    
    $action = $input['action'] ?? '';
    
    if (empty($action)) {
        sendErrorResponse('No action specified');
    }
    
    switch ($action) {
        case 'check_pricing':
            $username = $input['username'] ?? '';
            $clientIP = getClientIP();
            
            // Validate username
            $validation = validateUsername($username);
            if (!$validation['valid']) {
                sendBusinessErrorResponse($validation['error']);
            }
            
            $username = $validation['username'];
            
            // Check availability
            $availability = isUsernameAvailable($username, $mysqli);
            if (!$availability['available']) {
                $errorMessage = "The username '{$username}' is already taken and is currently active. Please try a different username.";
                
                logError('Username unavailable', [
                    'username' => $username,
                    'reason' => $availability['reason'],
                    'client_ip' => $clientIP
                ]);
                
                sendBusinessErrorResponse($errorMessage);
            }
            
            // Get pricing
            $pricingData = calculateAEmailPricing($username);
            
            if (isset($pricingData['error'])) {
                sendBusinessErrorResponse($pricingData['error']);
            }
            
            // For free accounts, check daily limit
            if ($pricingData['is_free']) {
                $limitCheck = checkDailyFreeAccountLimit($clientIP, $mysqli);
                if (!$limitCheck['allowed']) {
                    sendBusinessErrorResponse($limitCheck['message'], [
                        'limit_reached' => true,
                        'count' => $limitCheck['count']
                    ]);
                }
                
                $pricingData['daily_limit'] = $limitCheck;
            }
            
            logError('Pricing check successful', [
                'username' => $username,
                'type' => $pricingData['type'],
                'is_free' => $pricingData['is_free'],
                'client_ip' => $clientIP
            ]);
            
            sendSuccessResponse('Username is available', $pricingData);
            break;
            
        case 'suggest_alternatives':
            $username = $input['username'] ?? '';
            $validation = validateUsername($username);
            
            if (!$validation['valid']) {
                sendBusinessErrorResponse($validation['error']);
            }
            
            $baseUsername = $validation['username'];
            $suggestions = [];
            
            // Generate alternatives that don't contain forbidden words
            for ($i = 1; $i <= 5; $i++) {
                $alternatives = [
                    $baseUsername . $i,
                    $baseUsername . rand(10, 99),
                    $baseUsername . '.' . $i,
                    $baseUsername . '-' . $i,
                    substr($baseUsername, 0, -1) . $i . substr($baseUsername, -1)
                ];
                
                foreach ($alternatives as $alt) {
                    if (count($suggestions) >= 5) break 2;
                    
                    // Validate alternative doesn't contain forbidden words
                    $altValidation = validateUsername($alt);
                    if (!$altValidation['valid']) continue;
                    
                    $altAvailability = isUsernameAvailable($alt, $mysqli);
                    if ($altAvailability['available']) {
                        $altPricing = calculateAEmailPricing($alt);
                        if (!isset($altPricing['error'])) {
                            $suggestions[] = [
                                'username' => $alt,
                                'pricing' => $altPricing
                            ];
                        }
                    }
                }
            }
            
            sendSuccessResponse('Alternative suggestions generated', [
                'original' => $baseUsername,
                'suggestions' => $suggestions
            ]);
            break;
            
        default:
            sendErrorResponse('Invalid action: ' . $action);
    }
    
} catch (Exception $e) {
    logError('Pricing error: ' . $e->getMessage(), [
        'input' => $input ?? null,
        'trace' => $e->getTraceAsString()
    ]);
    sendErrorResponse('An error occurred while processing your request. Please try again.');
}
?>
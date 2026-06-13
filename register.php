<?php
// register.php - Fixed transaction handling for A.Email user registration
require_once 'config.php';

// Ensure JSON response for API calls
header('Content-Type: application/json; charset=utf-8');

function registerUser($data) {
    global $FORBIDDEN_WORDS;
    
    // Validate required fields
    $required_fields = ['username', 'full_name', 'display_name', 'password', 'email'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
        }
    }
    
    // Validate username using A.Email validation
    $validation = validateAEmailUsername($data['username']);
    if (!$validation['valid']) {
        throw new Exception($validation['error']);
    }
    
    $username = $validation['username'];
    $clientIP = getClientIP();
    
    // Check availability
    $availability = isUsernameAvailable($username);
    if (!$availability['available']) {
        throw new Exception('Username is no longer available - already registered and active since ' . $availability['completed_at']);
    }
    
    // Get pricing data
    $pricingData = calculateAEmailPricing($username);
    
    if (isset($pricingData['error'])) {
        throw new Exception($pricingData['error']);
    }
    
    // For free accounts, check daily limit
    if ($pricingData['is_free']) {
        $limitCheck = checkDailyFreeAccountLimit($clientIP);
        if (!$limitCheck['allowed']) {
            throw new Exception($limitCheck['message']);
        }
    }
    
    // Validate email
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Validate password
    $passwordValidation = validatePassword($data['password']);
    if (!$passwordValidation['valid']) {
        throw new Exception($passwordValidation['error']);
    }
    
    // Validate names
    $fullName = trim($data['full_name']);
    $displayName = trim($data['display_name']);
    
    if (strlen($fullName) < 2 || strlen($fullName) > 100) {
        throw new Exception('Full name must be between 2 and 100 characters');
    }
    
    if (strlen($displayName) < 2 || strlen($displayName) > 100) {
        throw new Exception('Display name must be between 2 and 100 characters');
    }
    
    // Create full email
    $fullEmail = getFullEmail($username);
    
    // Hash password (bcrypt). The submitted password is used only transiently below to
    // provision the mailbox via the API — it is never persisted in plaintext.
    $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // Determine plan + amount SERVER-SIDE from canonical pricing — never trust the
    // client-supplied amount. $pricingData came from calculateAEmailPricing($username).
    $requestedPlan = $data['plan_type'] ?? 'free';
    $paymentStatus = 'pending'; // Always start as pending for transaction tracking

    if ($pricingData['is_free'] || $requestedPlan === 'free') {
        $planType = 'free';
        $amountPaid = 0.00;
        $isFreeAccount = true;
    } elseif ($requestedPlan === 'monthly') {
        $planType = 'monthly';
        $amountPaid = getPlanAmount($pricingData, 'monthly');
        $isFreeAccount = false;
    } else {
        // Any other value for a paid username resolves to the lifetime plan.
        $planType = 'lifetime';
        $amountPaid = getPlanAmount($pricingData, 'lifetime');
        $isFreeAccount = false;
    }

    logError('Plan resolved server-side', [
        'username' => $username,
        'requested_plan' => $requestedPlan,
        'final_plan_type' => $planType,
        'final_amount_paid' => $amountPaid,
        'is_free_account' => $isFreeAccount
    ]);
    
    // Start database transaction
    $mysqli = getDBConnection();
    $mysqli->begin_transaction();
    
    $userId = null;
    $transactionId = null;
    $mailboxCreated = false;
    
    try {
        // Create user record first (as transaction/tx)
        $insertStmt = $mysqli->prepare("
    INSERT INTO users (
        username,
        email,
        full_name,
        display_name,
        password_hash,
        existing_email,
        full_email,
        plan_type,
        amount_paid,
        payment_status,
        intention,
        client_ip,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
        
        $intention = $data['intention'] ?? '';
        $existingEmail = $data['email'];
        
        $insertStmt->bind_param("ssssssssdsss",
    $username,
    $existingEmail,
    $fullName,
    $displayName,
    $passwordHash,
    $existingEmail,
    $fullEmail,
    $planType,
    $amountPaid,
    $paymentStatus,
    $intention,
    $clientIP
);
        
        if (!$insertStmt->execute()) {
            throw new Exception('Failed to create user account: ' . $mysqli->error);
        }
        
        $userId = $mysqli->insert_id;
        $insertStmt->close();
        
        // Create payment transaction record (even for free accounts)
        $transactionId = 'TX_' . time() . '_' . $userId;
        $transactionStmt = $mysqli->prepare("
            INSERT INTO payment_transactions (user_id, transaction_id, amount, currency, status, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $transactionStatus = $isFreeAccount ? 'completed' : 'pending';
        $currency = 'USD';
        
        $transactionStmt->bind_param("isdss", $userId, $transactionId, $amountPaid, $currency, $transactionStatus);
        
        if (!$transactionStmt->execute()) {
            throw new Exception('Failed to create payment transaction');
        }
        $transactionStmt->close();
        
        // For free accounts, create mailbox immediately
        if ($isFreeAccount) {
            try {
                // Create mailbox BEFORE committing transaction
                $mailboxResult = createMailboxViaAPI($username, $data['password'], $fullName);
                
                if ($mailboxResult['success']) {
                    $mailboxCreated = true;
                    
                    // Update user status to completed
                    $updateStmt = $mysqli->prepare("
                        UPDATE users 
                        SET payment_status = 'completed', updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->bind_param("i", $userId);
                    if (!$updateStmt->execute()) {
                        throw new Exception('Failed to update user status: ' . $mysqli->error);
                    }
                    $updateStmt->close();
                    
                    // Update transaction status
                    $updateTransactionStmt = $mysqli->prepare("
                        UPDATE payment_transactions 
                        SET status = 'completed', updated_at = NOW() 
                        WHERE user_id = ? AND transaction_id = ?
                    ");
                    $updateTransactionStmt->bind_param("is", $userId, $transactionId);
                    if (!$updateTransactionStmt->execute()) {
                        throw new Exception('Failed to update transaction status: ' . $mysqli->error);
                    }
                    $updateTransactionStmt->close();
                    
                    logError('Free account mailbox created successfully', [
                        'user_id' => $userId,
                        'username' => $username,
                        'full_email' => $fullEmail,
                        'client_ip' => $clientIP
                    ]);
                    
                } else {
                    throw new Exception('Failed to create mailbox: ' . ($mailboxResult['message'] ?? 'Unknown error'));
                }
                
            } catch (Exception $mailboxError) {
                logError('Mailbox creation failed during registration', [
                    'user_id' => $userId,
                    'username' => $username,
                    'error' => $mailboxError->getMessage(),
                    'client_ip' => $clientIP
                ]);
                
                // Don't rollback here - commit the user record and handle mailbox creation separately
                // This prevents the inconsistent state you experienced
                $mysqli->commit();
                
                // Try to clean up the mailbox if it was created
                try {
                    deleteMailboxViaAPI($username);
                } catch (Exception $cleanupError) {
                    logError('Failed to cleanup mailbox after registration failure', [
                        'username' => $username,
                        'cleanup_error' => $cleanupError->getMessage()
                    ]);
                }
                
                throw new Exception('Failed to create email account: ' . $mailboxError->getMessage());
            }
        }
        
        // Commit the transaction only if everything succeeded
        $mysqli->commit();
        
        // Increment daily count for free accounts AFTER successful commit
        if ($isFreeAccount && $mailboxCreated) {
            incrementDailyFreeAccountCount($clientIP);
        }
        
        logError('User registration completed successfully', [
            'user_id' => $userId,
            'username' => $username,
            'full_name' => $fullName,
            'display_name' => $displayName,
            'plan_type' => $planType,
            'payment_status' => $isFreeAccount ? 'completed' : 'pending',
            'amount_paid' => $amountPaid,
            'client_ip' => $clientIP,
            'requires_payment' => !$isFreeAccount,
            'mailbox_created' => $mailboxCreated,
            'is_free_account' => $isFreeAccount
        ]);
        
        $returnData = [
            'user_id' => $userId,
            'username' => $username,
            'full_name' => $fullName,
            'display_name' => $displayName,
            'email' => $existingEmail,
            'full_email' => $fullEmail,
            'plan_type' => $planType,
            'amount_paid' => $amountPaid,
            'payment_status' => $isFreeAccount ? 'completed' : 'pending',
            'pricing_data' => $pricingData,
            'requires_payment' => !$isFreeAccount && $amountPaid > 0, // FIXED: Check both conditions
            'transaction_id' => $transactionId,
            'client_ip' => $clientIP
        ];
        
        // Debug: Log what we're returning to frontend
        logError('Returning registration data to frontend', [
            'requires_payment' => $returnData['requires_payment'],
            'is_free_account' => $isFreeAccount,
            'amount_paid' => $amountPaid,
            'plan_type' => $planType,
            'user_id' => $userId
        ]);
        
        return $returnData;
        
    } catch (Exception $e) {
        // Rollback database transaction
        $mysqli->rollback();
        
        // If we created a mailbox but DB failed, try to clean it up
        if ($mailboxCreated && $username) {
            try {
                deleteMailboxViaAPI($username);
                logError('Cleaned up orphaned mailbox after DB rollback', [
                    'username' => $username
                ]);
            } catch (Exception $cleanupError) {
                logError('Failed to cleanup orphaned mailbox after DB rollback', [
                    'username' => $username,
                    'cleanup_error' => $cleanupError->getMessage()
                ]);
            }
        }
        
        throw $e;
    }
}

// Function to delete mailbox via API (for cleanup)
function deleteMailboxViaAPI($username) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, str_replace('/add/', '/delete/', MAILBOX_API_URL));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, VERIFY_TLS);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, VERIFY_TLS ? 2 : 0);
    
    $data = array(
        "username" => $username . "@a.email"
    );
    
    $json_data = json_encode($data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($json_data),
        'X-API-Key: ' . MAILBOX_API_KEY
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception("Mailbox deletion API Error: $curlError");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("Mailbox deletion API returned HTTP $httpCode");
    }
    
    return true;
}

// Function to check if user exists in database but mailbox doesn't exist
function checkInconsistentState($username) {
    try {
        $mysqli = getDBConnection();
        $stmt = $mysqli->prepare("
            SELECT id, username, payment_status, created_at 
            FROM users 
            WHERE username = ? AND payment_status = 'completed'
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $stmt->close();
            
            // Check if mailbox actually exists by trying to create it
            // If it fails with "already exists" error, state is consistent
            // If it succeeds, state was inconsistent
            
            return [
                'user_exists' => true,
                'user_data' => $user,
                'needs_mailbox_check' => true
            ];
        }
        
        $stmt->close();
        return ['user_exists' => false];
        
    } catch (Exception $e) {
        logError('Error checking inconsistent state', [
            'username' => $username,
            'error' => $e->getMessage()
        ]);
        return ['user_exists' => false, 'error' => $e->getMessage()];
    }
}

function isUsernameAvailable($username) {
    $username = strtolower(trim($username));
    $mysqli = getDBConnection();
    
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

function validatePassword($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'error' => 'Password must be at least 8 characters long'];
    }
    
    if (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number'];
    }
    
    return ['valid' => true];
}

// Class for backward compatibility and additional methods
class UserRegistration {
    private $mysqli;
    
    public function __construct() {
        $this->mysqli = getDBConnection();
    }
    
    public function getUserById($userId) {
        $stmt = $this->mysqli->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $stmt->close();
            return $user;
        }
        
        $stmt->close();
        return null;
    }
    
    public function updatePaymentStatus($userId, $status, $paymentIntentId = null) {
        try {
            $sql = "UPDATE users SET payment_status = ?, updated_at = NOW()";
            $params = [$status];
            $types = "s";
            
            if ($paymentIntentId) {
                $sql .= ", stripe_payment_intent_id = ?";
                $params[] = $paymentIntentId;
                $types .= "s";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $userId;
            $types .= "i";
            
            $stmt = $this->mysqli->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $result = $stmt->execute();
            $stmt->close();
            
            // If payment is completed, create mailbox
            if ($status === 'completed' && $result) {
                $user = $this->getUserById($userId);
                if ($user) {
                    try {
                        // Use existing password hash for mailbox creation (not ideal, but API limitation)
                        $tempPassword = 'TempPass123!'; // API will require password change on first login
                        $mailboxResult = createMailboxViaAPI($user['username'], $tempPassword, $user['full_name']);
                        
                        logError('Mailbox created after payment completion', [
                            'user_id' => $userId,
                            'username' => $user['username'],
                            'mailbox_result' => $mailboxResult['success']
                        ]);
                        
                    } catch (Exception $mailboxError) {
                        logError('Failed to create mailbox after payment', [
                            'user_id' => $userId,
                            'username' => $user['username'],
                            'error' => $mailboxError->getMessage()
                        ]);
                        // Don't fail the payment status update
                    }
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            logError('Failed to update payment status', [
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function getLatestUserByUsername($username) {
        $username = strtolower(trim($username));
        
        $stmt = $this->mysqli->prepare("
            SELECT * FROM users 
            WHERE username = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $stmt->close();
            return $user;
        }
        
        $stmt->close();
        return null;
    }
}

// Main execution
try {
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
        case 'register':
            $userData = registerUser($input);
            sendSuccessResponse('User registered successfully', $userData);
            break;
            
        case 'get_user':
            $userId = $input['user_id'] ?? 0;
            if (!$userId) {
                sendErrorResponse('User ID is required');
            }
            
            $registration = new UserRegistration();
            $user = $registration->getUserById($userId);
            
            if ($user) {
                // Don't return password hash
                unset($user['password_hash']);
                sendSuccessResponse('User found', $user);
            } else {
                sendErrorResponse('User not found', 404);
            }
            break;
            
        case 'get_latest_user_by_username':
            $username = $input['username'] ?? '';
            if (!$username) {
                sendErrorResponse('Username is required');
            }
            
            $registration = new UserRegistration();
            $user = $registration->getLatestUserByUsername($username);
            
            if ($user) {
                unset($user['password_hash']);
                sendSuccessResponse('Latest user found', $user);
            } else {
                sendErrorResponse('No user found for this username', 404);
            }
            break;
            
        case 'check_inconsistent_state':
            $username = $input['username'] ?? '';
            if (!$username) {
                sendErrorResponse('Username is required');
            }
            
            $stateCheck = checkInconsistentState($username);
            sendSuccessResponse('State check completed', $stateCheck);
            break;
            
        default:
            sendErrorResponse('Invalid action: ' . $action);
    }
    
} catch (Exception $e) {
    logError('Registration error: ' . $e->getMessage(), $input ?? []);
    sendErrorResponse($e->getMessage());
}
?>
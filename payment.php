<?php
// payment.php - A.Email payment processing with Stripe
require_once 'config.php';
require_once 'vendor/autoload.php';

// Initialize Stripe
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Get parameters
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$plan = isset($_GET['plan']) ? $_GET['plan'] : 'lifetime';

if (!$userId) {
    header('Location: index.php?error=invalid_user');
    exit;
}

$cancelled = isset($_GET['cancelled']) ? $_GET['cancelled'] : null;

if ($userId && $cancelled=="true") {
    header('Location: fail.php?user_id='.$userId);
    exit;
}

// Initialize variables
$mysqli = null;
$user = null;
$errorMessage = null;
$showError = false;

// Get user details
try {
    $mysqli = getDBConnection();
    if (!$mysqli) {
        throw new Exception('Database connection failed');
    }
    
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        if ($mysqli) $mysqli->close();
        header('Location: index.php?error=user_not_found');
        exit;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // If payment is already completed, redirect to success
    if ($user['payment_status'] === 'completed') {
        if ($mysqli) $mysqli->close();
        header('Location: success.php?user_id=' . $userId);
        exit;
    }
    
    // If it's a free account, redirect to success
    if ($user['plan_type'] === 'free' || $user['amount_paid'] == 0) {
        if ($mysqli) $mysqli->close();
        header('Location: success.php?user_id=' . $userId);
        exit;
    }
    
} catch (Exception $e) {
    if (function_exists('logError')) {
        logError('Payment page error: ' . $e->getMessage());
    }
    if ($mysqli) $mysqli->close();
    header('Location: index.php?error=system_error');
    exit;
}


// Process payment
try {
    $clientIP = function_exists('getClientIP') ? getClientIP() : $_SERVER['REMOTE_ADDR'];
    
    // Create Stripe checkout session with an inline price (no pre-created Stripe Price
    // needed) built from the server-computed amount.
    $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);

    $checkoutSession = $stripe->checkout->sessions->create([
        'line_items' => [
            [
                'price_data' => [
                    'currency' => strtolower(CURRENCY),
                    'product_data' => [
                        'name' => $user['full_email'],
                        'description' => formatPlanType($user['plan_type']) . ' mailbox',
                    ],
                    'unit_amount' => (int) round(((float) $user['amount_paid']) * 100),
                ],
                'quantity' => 1,
            ],
        ],
        'mode' => 'payment',
        'success_url' => SITE_URL . '/success.php?user_id=' . $userId . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => SITE_URL . '/payment.php?user_id=' . $userId . '&cancelled=true',
        'customer_email' => $user['existing_email'],
        'metadata' => [
            'user_id' => $userId,
            'username' => $user['username'],
            'plan' => $user['plan_type'],
            'amount' => $user['amount_paid'],
            'full_email' => $user['full_email']
        ]
    ]);
    
    // Update user record with Stripe session ID
    $updateStmt = $mysqli->prepare("
        UPDATE users 
        SET stripe_session_id = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    
    if (!$updateStmt) {
        throw new Exception('Database prepare failed: ' . $mysqli->error);
    }
    
    $updateStmt->bind_param("si", $checkoutSession->id, $userId);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Update payment transaction with Stripe session ID
    $updateTxStmt = $mysqli->prepare("
        UPDATE payment_transactions 
        SET stripe_session_id = ?, updated_at = NOW() 
        WHERE user_id = ? AND status = 'pending'
    ");
    
    if ($updateTxStmt) {
        $updateTxStmt->bind_param("si", $checkoutSession->id, $userId);
        $updateTxStmt->execute();
        $updateTxStmt->close();
    }
    
    // Log payment attempt
    if (function_exists('logError')) {
        logError('Stripe checkout session created', [
            'user_id' => $userId,
            'username' => $user['username'],
            'session_id' => $checkoutSession->id,
            'amount' => $user['amount_paid'],
            'client_ip' => $clientIP
        ]);
    }
    
    // Close database connection before redirect
    if ($mysqli) $mysqli->close();
    
    // Redirect to Stripe Checkout
    header("HTTP/1.1 303 See Other");
    header("Location: " . $checkoutSession->url);
    exit;
    
} catch (Exception $e) {
    if (function_exists('logError')) {
        logError('Stripe payment error', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'client_ip' => $clientIP
        ]);
    }
    
    // Show error page instead of redirect
    $errorMessage = 'Payment system error. Please try again or contact support.';
    $showError = true;
}

// Close database connection
if ($mysqli) $mysqli->close();

// If we reach here, there was an error - show error page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Error - A.Email</title>
    <link rel="stylesheet" type="text/css" href="css/payment.css">
</head>
<body>
    <div class="error-container">
        <div class="logo">A.Email</div>
        
        <div class="error-icon">⚠️</div>
        
        <h1 class="error-title">Payment Error</h1>
        
        <p class="error-message">
            <?php if (isset($_GET['cancelled'])): ?>
                Payment was cancelled. You can try again or contact support for assistance.
            <?php else: ?>
                We encountered an error while processing your payment. Please try again or contact support.
            <?php endif; ?>
        </p>
        
        <?php if (isset($errorMessage)): ?>
        <div class="error-details">
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="index.php" class="btn btn-secondary">Back to Home</a>
            <?php if (!isset($_GET['cancelled'])): ?>
            <a href="payment.php?user_id=<?php echo $userId; ?>&plan=<?php echo $plan; ?>" class="btn btn-primary">Try Again</a>
            <?php endif; ?>
            <a href="mailto:support@a.email?subject=Payment%20Error%20-%20User%20ID%20<?php echo $userId; ?>" class="btn btn-primary">Contact Support</a>
        </div>
    </div>
</body>
</html>
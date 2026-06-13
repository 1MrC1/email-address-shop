<?php
// fail.php - Payment failure page with user data integration
require_once 'config.php'; // Make sure this file exists and has your DB connection

// Initialize variables
$user = null;
$paymentTransaction = null;
$errorReason = 'unknown';
$errorCode = 'PAYMENT_FAILED';
$username = '';
$fullEmail = '';
$attemptedPlan = 'unknown';
$attemptedAmount = 0;

// Get user_id from URL
$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

// Get additional parameters for error details
$reasonParam = $_GET['reason'] ?? '';
$codeParam = $_GET['code'] ?? '';

// Function to get user and payment data
function getUserFailureData($userId) {
    global $user, $paymentTransaction, $errorReason, $errorCode, $username, $fullEmail, $attemptedPlan, $attemptedAmount;
    
    if ($userId <= 0) {
        return false;
    }
    
    try {
        $mysqli = getDBConnection();
        
        // Get user data
        $userStmt = $mysqli->prepare("
            SELECT 
                id, username, full_name, display_name, existing_email, 
                full_email, plan_type, amount_paid, payment_status,
                stripe_customer_id, stripe_payment_intent_id, stripe_session_id,
                created_at, updated_at, client_ip
            FROM users 
            WHERE id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        
        if ($userResult && $userResult->num_rows > 0) {
            $user = $userResult->fetch_assoc();
            $username = $user['username'];
            $fullEmail = $user['full_email'];
            $attemptedPlan = $user['plan_type'];
            $attemptedAmount = $user['amount_paid'];
        }
        $userStmt->close();
        
        // Get latest payment transaction for this user
        if ($user) {
            $transactionStmt = $mysqli->prepare("
                SELECT 
                    id, transaction_id, amount, currency, payment_method,
                    status, failure_reason, stripe_payment_intent_id, stripe_session_id,
                    created_at, updated_at
                FROM payment_transactions 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            
            $transactionStmt->bind_param("i", $userId);
            $transactionStmt->execute();
            $transactionResult = $transactionStmt->get_result();
            
            if ($transactionResult && $transactionResult->num_rows > 0) {
                $paymentTransaction = $transactionResult->fetch_assoc();
                
                // Determine error reason from transaction data
                if ($paymentTransaction['failure_reason']) {
                    $errorReason = $paymentTransaction['failure_reason'];
                }
                
                // Map payment status to error codes
                switch ($paymentTransaction['status']) {
                    case 'failed':
                        $errorCode = 'PAYMENT_FAILED';
                        break;
                    case 'canceled':
                        $errorCode = 'PAYMENT_CANCELLED';
                        $errorReason = 'cancelled';
                        break;
                    case 'expired':
                        $errorCode = 'PAYMENT_EXPIRED';
                        break;
                    default:
                        $errorCode = 'PAYMENT_ERROR';
                }
            }
            $transactionStmt->close();
        }
        
        // Log the failure page access
        logError('Payment failure page accessed', [
            'user_id' => $userId,
            'username' => $username,
            'payment_status' => $user['payment_status'] ?? 'unknown',
            'error_reason' => $errorReason,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        return true;
        
    } catch (Exception $e) {
        logError('Error loading failure data', [
            'user_id' => $userId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// Load user data
getUserFailureData($userId);

// Override with URL parameters if provided
if (!empty($reasonParam)) {
    $errorReason = $reasonParam;
}
if (!empty($codeParam)) {
    $errorCode = $codeParam;
}

// Function to format error message based on reason
function getErrorMessage($reason) {
    switch (strtolower($reason)) {
        case 'card_declined':
            return 'Your payment card was declined by your bank. Please check your card details and try again, or use a different payment method.';
        case 'insufficient_funds':
            return 'Your payment failed due to insufficient funds. Please ensure you have enough balance and try again.';
        case 'expired_card':
            return 'Your payment card has expired. Please update your card information and try again.';
        case 'invalid_card':
            return 'The card information provided is invalid. Please check your card number, expiration date, and security code.';
        case 'processing_error':
            return 'There was an error processing your payment. This is usually temporary. Please try again in a few minutes.';
        case 'network_error':
            return 'A network error occurred during payment processing. Please check your internet connection and try again.';
        case 'cancelled':
            return 'Payment was cancelled by the user. You can try again whenever you\'re ready.';
        case 'authentication_required':
            return 'Your bank requires additional authentication. Please try again and complete any required verification steps.';
        case 'generic_decline':
            return 'Your payment was declined. Please contact your bank for more information or try a different payment method.';
        default:
            return 'Your payment could not be processed. This may be due to insufficient funds, an expired card, or other payment method issues.';
    }
}

// Function to get formatted error code
function getFormattedErrorCode($code, $reason) {
    if (!empty($code)) {
        return 'Error Code: ' . strtoupper($code);
    }
    
    switch (strtolower($reason)) {
        case 'card_declined':
            return 'Error Code: CARD_DECLINED';
        case 'insufficient_funds':
            return 'Error Code: INSUFFICIENT_FUNDS';
        case 'expired_card':
            return 'Error Code: EXPIRED_CARD';
        case 'invalid_card':
            return 'Error Code: INVALID_CARD';
        case 'processing_error':
            return 'Error Code: PROCESSING_ERROR';
        case 'network_error':
            return 'Error Code: NETWORK_ERROR';
        case 'cancelled':
            return 'Error Code: PAYMENT_CANCELLED';
        default:
            return 'Error Code: PAYMENT_FAILED';
    }
}

// Determine retry URL
$retryUrl = '/';
if ($userId > 0) {
    $retryUrl = "/payment.php?user_id={$userId}&retry=true";
}

// Prepare data for JavaScript
$jsData = [
    'user_id' => $userId,
    'username' => $username,
    'full_email' => $fullEmail,
    'error_reason' => $errorReason,
    'error_code' => $errorCode,
    'attempted_plan' => $attemptedPlan,
    'attempted_amount' => $attemptedAmount,
    'retry_url' => $retryUrl,
    'has_user_data' => !empty($user)
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed - A.Email</title>
    <link rel="stylesheet" type="text/css" href="css/fail.css">

</head>
<body>
    <div class="bg-animation">
        <div class="bg-grid"></div>
        <div class="bg-orb"></div>
        <div class="bg-orb"></div>
    </div>

    <header>
        <div class="header-content">
            <a href="/" class="logo">A.Email</a>
            <nav>
                <ul class="nav-links">
                    <li><a href="/">Home</a></li>
                    <li><a href="/manage">Manage Account</a></li>
                    <li><a href="mailto:support@a.email">Support</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <div class="failure-container">
            <div class="failure-icon">✕</div>
            
            <h1 class="failure-title">Payment Failed</h1>
            <p class="failure-subtitle">We couldn't process your payment. Don't worry, we can help you resolve this.</p>
            
            <div class="error-details">
                <div class="error-title">Payment Error Details</div>
                <div class="error-message" id="errorMessage">
                    <?php echo htmlspecialchars(getErrorMessage($errorReason)); ?>
                </div>
                <div class="error-code" id="errorCode">
                    <?php echo htmlspecialchars(getFormattedErrorCode($errorCode, $errorReason)); ?>
                </div>
            </div>
            
            <div class="attempted-email">
                <div class="attempted-email-title">Attempted Registration</div>
                <div class="email-address" id="attemptedEmail">
                    <?php echo htmlspecialchars($fullEmail ?: 'username@a.email'); ?>
                </div>
                <div class="email-status">⏳ Reserved for 24 hours</div>
                <?php if ($attemptedPlan && $attemptedAmount > 0): ?>
                <div class="plan-details">
                    Plan: <?php echo ucfirst($attemptedPlan); ?> - $<?php echo number_format($attemptedAmount, 2); ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="troubleshooting">
                <h3 class="troubleshooting-title">Quick Solutions</h3>
                <ul class="troubleshooting-steps">
                    <li>Check your payment method details and ensure they're correct</li>
                    <li>Verify you have sufficient funds or credit limit available</li>
                    <li>Try a different payment method (card, bank account, etc.)</li>
                    <li>Contact your bank if the issue persists</li>
                    <li>Reach out to our support team for assistance</li>
                </ul>
            </div>
            
            <div class="common-issues">
                <h3 class="common-issues-title">Common Payment Issues</h3>
                
                <div class="issue-item">
                    <div class="issue-title">Card Declined</div>
                    <div class="issue-description">
                        Your bank or card issuer declined the transaction. This can happen due to insufficient funds, security measures, or expired cards.
                    </div>
                </div>
                
                <div class="issue-item">
                    <div class="issue-title">Invalid Card Information</div>
                    <div class="issue-description">
                        Double-check your card number, expiration date, and security code. Even small typos can cause payment failures.
                    </div>
                </div>
                
                <div class="issue-item">
                    <div class="issue-title">International Transaction Blocked</div>
                    <div class="issue-description">
                        Some banks block international transactions by default. Contact your bank to authorize the payment.
                    </div>
                </div>
                
                <div class="issue-item">
                    <div class="issue-title">Network Timeout</div>
                    <div class="issue-description">
                        Connection issues during payment processing. Try again with a stable internet connection.
                    </div>
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="<?php echo htmlspecialchars($retryUrl); ?>" class="btn btn-primary" id="retryPayment">
                    <?php echo $userId > 0 ? 'Try Payment Again' : 'Start Over'; ?>
                </a>
                <a href="mailto:support@a.email" class="btn btn-warning">Contact Support</a>
                <a href="/" class="btn btn-secondary">Back to Home</a>
            </div>
        </div>
    </main>

    <footer>
        <div class="footer-content">
            <div class="footer-links">
                <a href="/">Home</a>
                <a href="/manage">Account</a>
                <a href="mailto:support@a.email">Support</a>
                <a href="/privacy">Privacy</a>
                <a href="/terms">Terms</a>
            </div>
            <p>&copy; 2024 A.Email. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Server-side data passed to JavaScript
        const serverData = <?php echo json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        
        // Load failure data when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Failure page loaded with data:', serverData);
            
            // Track page view for analytics
            if (typeof gtag !== 'undefined') {
                gtag('event', 'payment_failure_viewed', {
                    'user_id': serverData.user_id,
                    'error_reason': serverData.error_reason,
                    'attempted_plan': serverData.attempted_plan
                });
            }
        });
        
        // Add click tracking for analytics
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function() {
                const buttonText = this.textContent.trim();
                console.log('Button clicked:', buttonText);
                
                // Track button clicks for analytics
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'payment_failure_button_click', {
                        'button_text': buttonText,
                        'user_id': serverData.user_id,
                        'error_reason': serverData.error_reason
                    });
                }
            });
        });
        
        // Auto-retry functionality (optional)
        if (serverData.error_reason === 'network_error' && serverData.user_id > 0) {
            // Show retry suggestion after 5 seconds for network errors
            setTimeout(function() {
                const retryButton = document.getElementById('retryPayment');
                if (retryButton) {
                    retryButton.style.animation = 'pulse 1s infinite';
                    retryButton.style.border = '2px solid var(--warning)';
                }
            }, 5000);
        }
    </script>
</body>
</html>
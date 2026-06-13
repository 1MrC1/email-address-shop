<?php
// success.php - Handle payment success and account activation
require_once 'config.php';
require_once 'vendor/autoload.php';

// Initialize Stripe
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Get parameters
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$sessionId = isset($_GET['session_id']) ? $_GET['session_id'] : '';

if (!$userId) {
    header('Location: index.php?error=invalid_user');
    exit;
}

// Get user details
try {
    $mysqli = getDBConnection();
    $stmt = $mysqli->prepare("
        SELECT id, username, email, full_name, display_name, password_hash,
               existing_email, full_email, plan_type, amount_paid, payment_status, 
               stripe_customer_id, stripe_payment_intent_id, stripe_session_id,
               created_at, updated_at, client_ip
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header('Location: index.php?error=user_not_found');
        exit;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    
} catch (Exception $e) {
    logError('Success page error: ' . $e->getMessage());
    header('Location: index.php?error=system_error');
    exit;
}

$paymentVerified = false;
$accountActivated = false;
$errorMessage = '';
$mailboxPassword = '';
$transactionId = '';

// Get transaction ID for this user
try {
    $transactionStmt = $mysqli->prepare("
        SELECT transaction_id 
        FROM payment_transactions 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $transactionStmt->bind_param("i", $userId);
    $transactionStmt->execute();
    $transactionResult = $transactionStmt->get_result();
    
    if ($transactionResult && $transactionResult->num_rows > 0) {
        $transactionData = $transactionResult->fetch_assoc();
        $transactionId = $transactionData['transaction_id'];
    }
    $transactionStmt->close();
} catch (Exception $e) {
    logError('Failed to get transaction ID', [
        'user_id' => $userId,
        'error' => $e->getMessage()
    ]);
}

// If there's a session ID, verify payment with Stripe
if (!empty($sessionId)) {
    try {
        $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
        $session = $stripe->checkout->sessions->retrieve($sessionId);
        
        if ($session->payment_status === 'paid') {
            $paymentVerified = true;
            
            // Update user payment status
            $updateStmt = $mysqli->prepare("
                UPDATE users 
                SET payment_status = 'completed', 
                    stripe_session_id = ?,
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->bind_param("si", $sessionId, $userId);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Update payment transaction
            $updateTxStmt = $mysqli->prepare("
                UPDATE payment_transactions 
                SET status = 'completed',
                    stripe_session_id = ?,
                    updated_at = NOW() 
                WHERE user_id = ? AND status = 'pending'
            ");
            $updateTxStmt->bind_param("si", $sessionId, $userId);
            $updateTxStmt->execute();
            $updateTxStmt->close();
            
            // Log payment success
            logError('Payment completed successfully', [
                'user_id' => $userId,
                'username' => $user['username'],
                'session_id' => $sessionId,
                'amount' => $user['amount_paid']
            ]);
            
            // Update user record for mailbox creation
            $user['payment_status'] = 'completed';
        }
        
    } catch (Exception $e) {
        logError('Stripe session verification error', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'error' => $e->getMessage()
        ]);
        $errorMessage = 'Payment verification failed. Please contact support.';
    }
}

// Check if account should be activated (payment completed or free account)
if ($user['payment_status'] === 'completed') {
    try {
        // No plaintext password is stored. Provision the mailbox with a fresh temporary
        // password and email it to the user (they change it on first login).
        $apiPassword = 'TempPass' . rand(100000, 999999) . '!';
        $mailboxPassword = $apiPassword;

        // Create mailbox via API
        $mailboxResult = createMailboxViaAPI($user['username'], $apiPassword, $user['full_name']);

        if ($mailboxResult['success']) {
            $accountActivated = true;

            // Send welcome email with login credentials
            $welcomeSubject = 'Welcome to A.Email - Your Account is Ready!';
            $welcomeMessage = generateWelcomeEmail($user, $mailboxPassword, $userId, $transactionId);

            // Send to user's existing email
            sendEmailNotification($user['existing_email'], $welcomeSubject, $welcomeMessage, 'welcome');

            logError('Account activated successfully', [
                'user_id' => $userId,
                'username' => $user['username'],
                'full_email' => $user['full_email'],
                'mailbox_created' => true
            ]);

        } else {
            logError('Mailbox creation failed after payment', [
                'user_id' => $userId,
                'username' => $user['username'],
                'error' => $mailboxResult['message'] ?? 'Unknown error',
                'api_response' => $mailboxResult
            ]);
            $errorMessage = 'Payment successful but account activation failed. Support has been notified.';
        }
        
    } catch (Exception $e) {
        logError('Account activation error', [
            'user_id' => $userId,
            'username' => $user['username'],
            'error' => $e->getMessage()
        ]);
        $errorMessage = 'Payment successful but account activation failed. Support has been notified.';
    }
}

// Determine success status
$isSuccess = ($user['payment_status'] === 'completed' && $accountActivated) || 
             ($user['plan_type'] === 'free' && $user['payment_status'] === 'completed');

// Function to generate welcome email content
function generateWelcomeEmail($user, $password, $userId = null, $transactionId = '') {
    $planDisplay = formatPlanType($user['plan_type']);
    $loginUrl = 'https://email.a.email/?user_id='.$user['id'].'&transaction_id='.($transactionId ?? '');
    
    return "
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8fafc; padding: 30px; border-radius: 0 0 10px 10px; }
                .credentials { background: #e0e7ff; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #6366f1; }
                .button { display: inline-block; background: #6366f1; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 20px 0; }
                .steps { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .warning { background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 15px; border-radius: 6px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 Welcome to A.Email!</h1>
                    <p>Your premium email account is now active</p>
                </div>
                
                <div class='content'>
                    <h2>Account Successfully Created</h2>
                    <p>Congratulations! Your A.Email account has been successfully created and activated.</p>
                    
                    <div class='credentials'>
                        <h3>📧 Your Login Credentials</h3>
                        <p><strong>Email Address:</strong> {$user['full_email']}</p>
                        <p><strong>Password:</strong> {$password}</p>
                        <p><strong>Plan:</strong> {$planDisplay}</p>
                    </div>
                    
                    <div class='warning'>
                        <strong>🔒 Important Security Notice:</strong> Please change your password immediately after your first login for security purposes.
                    </div>
                    
                    <div class='steps'>
                        <h3>🚀 Next Steps</h3>
                        <ol>
                            <li><strong>Access your mailbox:</strong> Click the button below or visit {$loginUrl}</li>
                            <li><strong>Login:</strong> Use your email and the password provided above</li>
                            <li><strong>Change password:</strong> Go to Settings → Security → Change Password</li>
                            <li><strong>Configure email client:</strong> Set up your favorite email app (optional)</li>
                            <li><strong>Start using:</strong> You're ready to send and receive emails!</li>
                        </ol>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='{$loginUrl}' class='button'>Access Your Email Now</a>
                    </div>
                    
                    <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb;'>
                        <h3>📱 Email Client Settings (IMAP/SMTP)</h3>
                        <p><strong>IMAP Server:</strong> imap.a.email (Port: 993, SSL)</p>
                        <p><strong>SMTP Server:</strong> smtp.a.email (Port: 587, TLS)</p>
                        <p><strong>Username:</strong> {$user['full_email']}</p>
                        <p><strong>Password:</strong> Your account password</p>
                    </div>
                    
                    <div style='margin-top: 30px; padding: 20px; background: #f3f4f6; border-radius: 8px;'>
                        <h3>🆘 Need Help?</h3>
                        <p>If you have any questions or need assistance:</p>
                        <ul>
                            <li>📧 Email us: <a href='mailto:support@a.email'>support@a.email</a></li>
                            <li>💬 Visit our help center (coming soon)</li>
                            <li>🔧 Check our setup guides (coming soon)</li>
                        </ul>
                    </div>
                    
                    <div style='text-align: center; margin-top: 30px; color: #6b7280;'>
                        <p>Thank you for choosing A.Email!</p>
                        <p><strong>The A.Email Team</strong></p>
                    </div>
                </div>
            </div>
        </body>
        </html>
    ";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isSuccess ? 'Success' : 'Account Status'; ?> - A.Email</title>
    <link rel="stylesheet" type="text/css" href="css/success.css">
</head>
<body>
    <div class="success-container">
        <div class="logo">A.Email</div>
        
        <?php if ($isSuccess): ?>
            <div class="success-icon">🎉</div>
            <h1 class="success-title">Account Created Successfully!</h1>
            <p class="success-message">
                Your A.Email account has been activated and is ready to use.
            </p>
            
            <div class="email-display">
                <div class="email"><?php echo htmlspecialchars($user['full_email']); ?></div>
                <div class="status">✅ Active and Ready</div>
            </div>
            <?php
            /*
            <div class="security-notice">
                <strong>🔒 Security Notice:</strong> Check your email (<?php echo htmlspecialchars($user['existing_email']); ?>) for login credentials. Please change your password after first login.
            </div>
            */
            ?>
        <?php else: ?>
            <div class="error-icon">⚠️</div>
            <h1 class="success-title">Account Status</h1>
            <p class="success-message">
                Your account has been created but requires additional processing.
            </p>
            
            <div class="email-display error">
                <div class="email"><?php echo htmlspecialchars($user['full_email']); ?></div>
                <div class="status">⏳ Pending Activation</div>
            </div>
            
            <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($errorMessage); ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="account-details">
            <h3>Account Details</h3>
            <div class="detail-row">
                <span class="detail-label">Email Address:</span>
                <span class="detail-value"><?php echo htmlspecialchars($user['full_email']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Plan:</span>
                <span class="detail-value"><?php echo formatPlanType($user['plan_type']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span class="detail-value"><?php echo $user['payment_status'] === 'completed' ? 'Active' : 'Pending'; ?></span>
            </div>
            <?php if ($user['amount_paid'] > 0): ?>
            <div class="detail-row">
                <span class="detail-label">Amount Paid:</span>
                <span class="detail-value"><?php echo formatPricing($user['amount_paid']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($isSuccess): ?>
        <div class="next-steps">
            <h3>Next Steps</h3>
            <ol>
                <li>Check your email (<?php echo htmlspecialchars($user['existing_email']); ?>) for login credentials</li>
                <li>Access your mailbox at <a href="https://web.a.email" target="_blank">https://web.a.email</a></li>
                <li>Manage your mailbox at <a href="https://email.a.email/?user_id=<?php echo $userId; ?>&transaction_id=<?php echo $transactionId; ?>" target="_blank">https://email.a.email</a></li>
                <li>Configure your email client or use our web interface</li>
            </ol>
        </div>
        
        <div class="actions">
            <a href="https://web.a.email" class="btn btn-success" target="_blank">Access Email</a>
            <a href="https://email.a.email/?user_id=<?php echo $userId; ?>&transaction_id=<?php echo $transactionId; ?>"  target="_blank" class="btn btn-secondary">Manage Account</a>
            <a href="index.php" class="btn btn-secondary">Create Another</a>
        </div>
        
        <?php else: ?>
        <div class="actions">
            <a href="mailto:support@a.email?subject=Account%20Activation%20Help%20-%20User%20ID%20<?php echo $userId; ?>&body=Hi,%0D%0A%0D%0AI%20need%20help%20with%20account%20activation.%0D%0A%0D%0AUser%20ID:%20<?php echo $userId; ?>%0D%0ATransaction%20ID:%20<?php echo $transactionId; ?>%0D%0AEmail:%20<?php echo htmlspecialchars($user['full_email']); ?>%0D%0A%0D%0APlease%20help%20me%20activate%20my%20account.%0D%0A%0D%0AThank%20you!" class="btn btn-primary">Contact Support</a>
            <a href="index.php" class="btn btn-secondary">Back to Home</a>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Track successful account creation
        <?php if ($isSuccess): ?>
        console.log('Account successfully created and activated', {
            user_id: <?php echo $userId; ?>,
            transaction_id: '<?php echo addslashes($transactionId); ?>',
            username: '<?php echo addslashes($user['username']); ?>',
            plan: '<?php echo addslashes($user['plan_type']); ?>'
        });
        
        // Analytics tracking if available
        if (typeof gtag !== 'undefined') {
            gtag('event', 'account_activation_success', {
                'user_id': <?php echo $userId; ?>,
                'transaction_id': '<?php echo addslashes($transactionId); ?>',
                'plan_type': '<?php echo addslashes($user['plan_type']); ?>',
                'amount_paid': <?php echo $user['amount_paid']; ?>
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>
<?php
// webhook.php - Stripe webhook handler for A.Email
require_once 'config.php';
require_once 'vendor/autoload.php';

// This is your Stripe CLI webhook secret for testing your endpoint locally.
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    logError('Webhook error: Invalid payload', ['error' => $e->getMessage()]);
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    logError('Webhook error: Invalid signature', ['error' => $e->getMessage()]);
    http_response_code(400);
    exit();
}

// Handle the event
switch ($event->type) {
    case 'checkout.session.completed':
        $session = $event->data->object;
        handleCheckoutSessionCompleted($session);
        break;
    case 'payment_intent.succeeded':
        $paymentIntent = $event->data->object;
        handlePaymentIntentSucceeded($paymentIntent);
        break;
    case 'payment_intent.payment_failed':
        $paymentIntent = $event->data->object;
        handlePaymentIntentFailed($paymentIntent);
        break;
    default:
        logError('Webhook: Received unknown event type ' . $event->type);
}

http_response_code(200);

function handleCheckoutSessionCompleted($session) {
    try {
        $sessionId = $session->id;
        $paymentStatus = $session->payment_status;
        $customerEmail = $session->customer_email;
        
        // Get metadata
        $userId = $session->metadata->user_id ?? null;
        $username = $session->metadata->username ?? null;
        $plan = $session->metadata->plan ?? null;
        $amount = $session->metadata->amount ?? null;
        $fullEmail = $session->metadata->full_email ?? null;
        
        logError('Webhook: Checkout session completed', [
            'session_id' => $sessionId,
            'payment_status' => $paymentStatus,
            'user_id' => $userId,
            'username' => $username,
            'amount' => $amount
        ]);
        
        if ($paymentStatus === 'paid' && $userId) {
            // Update user payment status
            $mysqli = getDBConnection();
            
            // Get current user details
            $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $stmt->close();
                
                // Update payment status
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
                
                // Create mailbox if not already created
                if ($user['payment_status'] !== 'completed') {
                    try {
                        // Generate a temporary password
                        $tempPassword = 'TempPass' . rand(100000, 999999) . '!';
                        
                        $mailboxResult = createMailboxViaAPI($user['username'], $tempPassword, $user['full_name']);
                        
                        if ($mailboxResult['success']) {
                            // Send welcome email
                            $welcomeSubject = 'Welcome to A.Email - Your Account is Ready!';
                            $welcomeMessage = "
                                <html>
                                <body style='font-family: Arial, sans-serif; color: #333;'>
                                    <h2 style='color: #6366f1;'>Welcome to A.Email!</h2>
                                    <p>Your premium email account has been successfully created and activated.</p>
                                    
                                    <div style='background: #f8fafc; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                        <h3>Your Account Details:</h3>
                                        <p><strong>Email:</strong> {$user['full_email']}</p>
                                        <p><strong>Temporary Password:</strong> {$tempPassword}</p>
                                        <p><strong>Plan:</strong> " . formatPlanType($user['plan_type']) . "</p>
                                    </div>
                                    
                                    <h3>Next Steps:</h3>
                                    <ol>
                                        <li>Access your email at <a href='https://mail.a.email'>https://mail.a.email</a></li>
                                        <li>Login with your email and temporary password</li>
                                        <li>Change your password immediately after first login</li>
                                    </ol>
                                    
                                    <p>If you have any questions, please contact our support team at <a href='mailto:support@a.email'>support@a.email</a>.</p>
                                    
                                    <p>Thank you for choosing A.Email!</p>
                                    <p>The A.Email Team</p>
                                </body>
                                </html>
                            ";
                            
                            sendEmailNotification($user['existing_email'], $welcomeSubject, $welcomeMessage, 'welcome');
                            
                            logError('Webhook: Mailbox created successfully', [
                                'user_id' => $userId,
                                'username' => $user['username'],
                                'session_id' => $sessionId
                            ]);
                        } else {
                            logError('Webhook: Mailbox creation failed', [
                                'user_id' => $userId,
                                'username' => $user['username'],
                                'error' => $mailboxResult['message']
                            ]);
                        }
                        
                    } catch (Exception $e) {
                        logError('Webhook: Mailbox creation error', [
                            'user_id' => $userId,
                            'username' => $user['username'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                logError('Webhook: Payment processed successfully', [
                    'user_id' => $userId,
                    'username' => $user['username'],
                    'session_id' => $sessionId,
                    'amount' => $amount
                ]);
                
            } else {
                $stmt->close();
                logError('Webhook: User not found for session', [
                    'session_id' => $sessionId,
                    'user_id' => $userId
                ]);
            }
        }
        
    } catch (Exception $e) {
        logError('Webhook: Error processing checkout session', [
            'session_id' => $session->id,
            'error' => $e->getMessage()
        ]);
    }
}

function handlePaymentIntentSucceeded($paymentIntent) {
    try {
        logError('Webhook: Payment intent succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency
        ]);
        
        // Additional processing if needed
        // This event is fired when a payment is successful
        // Most processing should happen in checkout.session.completed
        
    } catch (Exception $e) {
        logError('Webhook: Error processing payment intent success', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $e->getMessage()
        ]);
    }
}

function handlePaymentIntentFailed($paymentIntent) {
    try {
        logError('Webhook: Payment intent failed', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'last_payment_error' => $paymentIntent->last_payment_error
        ]);
        
        // You might want to update the user's payment status or send a notification
        // For now, just log the failure
        
    } catch (Exception $e) {
        logError('Webhook: Error processing payment intent failure', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $e->getMessage()
        ]);
    }
}
?>
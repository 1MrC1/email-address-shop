-- A.Email Database Schema
-- Structure only: tables, stored procedures and views. No migrations, no seed data.

CREATE DATABASE IF NOT EXISTS a_email CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE a_email;

-- Users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    domain VARCHAR(100) NOT NULL DEFAULT 'a.email' COMMENT 'Email domain for the user',
    email VARCHAR(100) NULL COMMENT 'For notifications',
    full_name VARCHAR(100) NULL,
    display_name VARCHAR(100) NULL,
    password_hash VARCHAR(255) NULL COMMENT 'bcrypt hash; plaintext passwords are never stored',
    existing_email VARCHAR(100) NULL COMMENT 'User current email for notifications',
    full_email VARCHAR(150) NOT NULL COMMENT 'Complete email address (username@domain)',
    plan_type ENUM('free','monthly','lifetime') DEFAULT 'free',
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    payment_status ENUM('pending','completed','failed','canceled','processing','expired') DEFAULT 'pending',
    intention TEXT NULL,
    client_ip VARCHAR(45) NULL COMMENT 'Client IP address for rate limiting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    stripe_customer_id VARCHAR(100) NULL,
    stripe_payment_intent_id VARCHAR(100) NULL,
    stripe_session_id VARCHAR(200) NULL COMMENT 'Stripe checkout session ID',
    subscription_id VARCHAR(100) NULL,
    subscription_status ENUM('active','canceled','past_due','incomplete') NULL,

    INDEX idx_username (username),
    INDEX idx_domain (domain),
    INDEX idx_username_domain (username, domain),
    INDEX idx_email (email),
    INDEX idx_existing_email (existing_email),
    INDEX idx_full_email (full_email),
    INDEX idx_plan_type (plan_type),
    INDEX idx_payment_status (payment_status),
    INDEX idx_client_ip (client_ip),
    INDEX idx_created_at (created_at),
    INDEX idx_stripe_customer_id (stripe_customer_id),
    INDEX idx_stripe_payment_intent_id (stripe_payment_intent_id),
    INDEX idx_stripe_session_id (stripe_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment transactions (every registration is tracked as a transaction)
CREATE TABLE payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_id VARCHAR(100) UNIQUE NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    payment_method VARCHAR(50) NULL,
    status ENUM('pending','completed','failed','refunded','processing','canceled') DEFAULT 'pending',
    stripe_payment_intent_id VARCHAR(100) NULL,
    stripe_session_id VARCHAR(200) NULL COMMENT 'Stripe checkout session ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    failure_reason TEXT NULL,
    receipt_url VARCHAR(500) NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_status (status),
    INDEX idx_amount (amount),
    INDEX idx_stripe_payment_intent_id (stripe_payment_intent_id),
    INDEX idx_stripe_session_id (stripe_session_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subscriptions (for future monthly subscriptions)
CREATE TABLE subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    stripe_subscription_id VARCHAR(100) NOT NULL UNIQUE,
    stripe_customer_id VARCHAR(100) NOT NULL,
    status ENUM('active','canceled','past_due','incomplete','trialing') DEFAULT 'active',
    current_period_start INT NOT NULL,
    current_period_end INT NOT NULL,
    cancel_at_period_end TINYINT(1) DEFAULT 0,
    canceled_at INT NULL,
    trial_end INT NULL,
    plan_id VARCHAR(100) NOT NULL,
    quantity INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_stripe_subscription_id (stripe_subscription_id),
    INDEX idx_stripe_customer_id (stripe_customer_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook logs
CREATE TABLE webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(100) NOT NULL,
    payment_transaction_id INT NULL,
    event_type VARCHAR(100) NOT NULL,
    processed TINYINT(1) DEFAULT 0,
    data JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,

    FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL,
    INDEX idx_stripe_event_id (stripe_event_id),
    INDEX idx_payment_transaction_id (payment_transaction_id),
    INDEX idx_event_type (event_type),
    INDEX idx_processed (processed),
    INDEX idx_created_at (created_at),
    UNIQUE KEY unique_stripe_event (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email logs
CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    email_to VARCHAR(255) NOT NULL,
    email_type ENUM('confirmation','payment_success','payment_failed','subscription_update','welcome','account_created') NOT NULL,
    subject VARCHAR(500) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivery_status ENUM('sent','failed','bounced') DEFAULT 'sent',
    error_message TEXT NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_email_type (email_type),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Premium prefixes (for future premium usernames)
CREATE TABLE premium_prefixes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prefix VARCHAR(50) NOT NULL UNIQUE,
    monthly_price DECIMAL(10,2) NOT NULL,
    lifetime_price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings (key/value)
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin users (scaffold; no admin panel is implemented)
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
    role ENUM('admin','moderator') DEFAULT 'moderator',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,

    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily IP limits (free-account creation throttle)
CREATE TABLE daily_ip_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_ip VARCHAR(45) NOT NULL,
    date DATE NOT NULL,
    free_account_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_ip_date (client_ip, date),
    INDEX idx_client_ip (client_ip),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stored procedure: read a client IP's daily free-account count
DELIMITER //
CREATE PROCEDURE CheckDailyIPLimit(
    IN p_client_ip VARCHAR(45),
    IN p_date DATE,
    OUT p_count INT,
    OUT p_allowed BOOLEAN
)
BEGIN
    DECLARE v_limit INT DEFAULT 5;

    SELECT COALESCE(free_account_count, 0) INTO p_count
    FROM daily_ip_limits
    WHERE client_ip = p_client_ip AND date = p_date;

    IF p_count IS NULL THEN
        SET p_count = 0;
    END IF;

    SET p_allowed = (p_count < v_limit);
END //
DELIMITER ;

-- Stored procedure: increment a client IP's daily free-account count
DELIMITER //
CREATE PROCEDURE IncrementDailyIPCount(
    IN p_client_ip VARCHAR(45),
    IN p_date DATE
)
BEGIN
    INSERT INTO daily_ip_limits (client_ip, date, free_account_count)
    VALUES (p_client_ip, p_date, 1)
    ON DUPLICATE KEY UPDATE
        free_account_count = free_account_count + 1,
        updated_at = CURRENT_TIMESTAMP;
END //
DELIMITER ;

-- View: registrations per day
CREATE VIEW user_stats AS
SELECT
    DATE(created_at) AS registration_date,
    COUNT(*) AS total_registrations,
    SUM(CASE WHEN plan_type = 'free' THEN 1 ELSE 0 END) AS free_accounts,
    SUM(CASE WHEN plan_type = 'lifetime' THEN 1 ELSE 0 END) AS premium_accounts,
    SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) AS completed_accounts,
    SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) AS pending_accounts,
    SUM(amount_paid) AS total_revenue
FROM users
GROUP BY DATE(created_at)
ORDER BY registration_date DESC;

-- View: daily IP usage vs actual registrations
CREATE VIEW daily_ip_usage AS
SELECT
    client_ip,
    date,
    free_account_count,
    (SELECT COUNT(*) FROM users WHERE client_ip = dil.client_ip AND DATE(created_at) = dil.date) AS actual_registrations,
    created_at,
    updated_at
FROM daily_ip_limits dil
ORDER BY date DESC, free_account_count DESC;

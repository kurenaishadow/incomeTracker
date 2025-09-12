-- Disable foreign key checks temporarily to avoid issues during table creation/alteration
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Use the database
-- IMPORTANT: Ensure the database 'kurenaigui_dashboard_db' is created manually before running this script
CREATE DATABASE IF NOT EXISTS kurenaigui_dashboard_db;
USE kurenaigui_dashboard_db;

-- 2. Create the users table if it doesn't exist
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL DEFAULT '',
    business_name VARCHAR(255) NULL,
    currency VARCHAR(10) DEFAULT '$',
    is_admin TINYINT(1) DEFAULT 0,
    account_status VARCHAR(50) DEFAULT 'active',
    expiration_date DATETIME NULL,
    activation_code VARCHAR(255) NULL,
    needs_password_change TINYINT(1) DEFAULT 1,
    monthly_income_target DECIMAL(10, 2) DEFAULT 0.00,
    monthly_expense_target DECIMAL(10, 2) DEFAULT 0.00,
    show_inventory_overview TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. Ensure correct types/defaults for existing columns in the users table
-- MySQL doesn’t support ALTER COLUMN ... SET DEFAULT directly, so use MODIFY instead
ALTER TABLE users MODIFY account_status VARCHAR(50) DEFAULT 'active';
ALTER TABLE users MODIFY expiration_date DATETIME NULL;
ALTER TABLE users MODIFY needs_password_change TINYINT(1) DEFAULT 1;

-- 4. Insert or update the initial admin user
SET @admin_password_hash = '2a12$GtgWecff.tHchZNStxq4auTDCIXWzUUlJpdBhDHQNG9CEoRZTraK'; -- sample hash
SET @admin_expiration_date = NOW() + INTERVAL 1 YEAR;

INSERT INTO users (id, username, email, password_hash, is_admin, account_status, expiration_date, needs_password_change)
VALUES (1, 'enjinx', 'admin@example.com', @admin_password_hash, 1, 'active', @admin_expiration_date, 0)
ON DUPLICATE KEY UPDATE
    username = VALUES(username),
    email = VALUES(email),
    password_hash = IF(password_hash = '' OR password_hash IS NULL, VALUES(password_hash), password_hash),
    is_admin = VALUES(is_admin),
    account_status = VALUES(account_status),
    expiration_date = IF(expiration_date IS NULL, VALUES(expiration_date), expiration_date),
    needs_password_change = VALUES(needs_password_change);

-- 5. Create the income table if it doesn't exist
CREATE TABLE IF NOT EXISTS income (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255),
    income_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 6. Create the expenses table if it doesn't exist
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255),
    expense_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 7. Create the products table if it doesn't exist
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    min_stock_level INT NOT NULL DEFAULT 10,
    price DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, product_name)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 8. Create the scheduled_expenses table
CREATE TABLE IF NOT EXISTS scheduled_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bill_name VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    due_date DATE NOT NULL,
    recurrence ENUM('monthly', 'weekly', 'yearly', 'once') NOT NULL,
    description TEXT,
    is_paid TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 9. Create the savings tables
CREATE TABLE IF NOT EXISTS savings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255),
    date_added DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS savings_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_name VARCHAR(255) NOT NULL,
    target_amount DECIMAL(10, 2) NOT NULL,
    current_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS savings_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    goal_id INT NULL,
    log_type VARCHAR(50) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description VARCHAR(255) NULL,
    log_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (goal_id),
    CONSTRAINT savings_logs_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT savings_logs_ibfk_2 FOREIGN KEY (goal_id) REFERENCES savings_goals (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Security hardening, 2026-11-13. Run against the remote database (chamonixdb, phpMyAdmin -> SQL tab).
-- Two independent parts; run them in this order.
--
-- ============================================================================================================
-- PART 1: login attempt limit (api/lib/LoginThrottle.php). Safe to re-run.
-- ============================================================================================================
-- One row per FAILED sign-in (main page, X-Account/X-Password header login, monitor page). Rows older than a day
-- are purged by the code itself. Until this table exists, logins keep working and the code only logs an error.
-- attempted_at is UTC, written and compared by PHP (gmdate) -- never by the database clock.

CREATE TABLE IF NOT EXISTS login_attempts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account       VARCHAR(100) NOT NULL COMMENT '輸入的帳號（小寫，不論帳號是否存在）',
    ip            VARCHAR(45)  NOT NULL COMMENT '來源 IP',
    source        VARCHAR(16)  NOT NULL COMMENT 'web / header / monitor',
    attempted_at  DATETIME     NOT NULL COMMENT 'UTC',
    KEY idx_login_attempts_account (account, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登入失敗紀錄（登入次數限制）';

-- The app's DB user already has SELECT/INSERT/UPDATE/DELETE on the whole database (see schema.sql),
-- which covers this table.

-- ============================================================================================================
-- PART 2: remove the plaintext `users.password` column. Run the steps ONE AT A TIME.
-- ============================================================================================================
-- The column dates from when the table was created by hand. No code in this project reads or writes it
-- (api/login.php, CredentialAuth, the monitor and tools/create_user.php all use password_hash only), but if a
-- database dump ever leaks, any value in it is a readable password -- often reused elsewhere.
--
-- BEFORE step 2b: make sure no OTHER program (a desktop tool, a script, another web app) reads users.password
-- straight from the database. If unsure, do step 2a/2b, wait a few days, then step 2c.

-- Step 2a: how many rows still hold a plaintext password? (read-only)
SELECT COUNT(*) AS rows_with_plaintext_password FROM users WHERE password IS NOT NULL AND password <> '';

-- Step 2b: wipe the values. Logging in is unaffected (it only uses password_hash).
-- If this fails with "Column 'password' cannot be null", the column was created NOT NULL: skip to step 2c.
UPDATE users SET password = NULL;

-- Step 2c: drop the column for good. (IF EXISTS: harmless if it is already gone.)
ALTER TABLE users DROP COLUMN IF EXISTS password;

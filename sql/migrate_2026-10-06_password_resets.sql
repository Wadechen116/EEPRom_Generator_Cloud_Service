-- Forgot-password flow: one row per emailed reset link.
-- Run once against the remote database (phpMyAdmin -> SQL tab). Safe to re-run.
--
-- What is stored, and why:
--   token_hash   sha256 (hex) of a random 32-byte token. The raw token exists only in
--                the emailed link; a leaked table dump cannot be turned into working
--                reset links. Looked up by hash, so no timing side channel on the token.
--   expires_at   link lifetime ('mail.reset_ttl_minutes' in config.php, default 30).
--   used_at      set when the link is used OR superseded by a newer request for the same
--                user -- only the most recent link ever works.
--   requested_ip only used to rate-limit (api/forgot_password.php).
--
-- All three timestamps are UTC, written by PHP (gmdate) and compared by PHP, never by
-- the database clock, so a DB server in another time zone cannot shift expiry.
--
-- No FOREIGN KEY on user_id on purpose: the `users` table on the remote server was
-- created by hand (see schema.sql), and a FK fails to create if its engine/column type
-- differs from InnoDB / INT UNSIGNED. A reset for a deleted user simply finds no
-- matching user (api/reset_password.php joins `users`) and is rejected.

CREATE TABLE IF NOT EXISTS password_resets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    requested_ip  VARCHAR(45)  NULL DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    used_at       DATETIME     NULL DEFAULT NULL,
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, created_at),
    KEY idx_password_resets_ip (requested_ip, created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The app's DB user (see the CREATE USER note at the end of schema.sql) already has
-- SELECT/INSERT/UPDATE/DELETE on the whole database, which covers this table.
--
-- Optional housekeeping -- rows are tiny, but nothing deletes them automatically:
--   DELETE FROM password_resets WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY);

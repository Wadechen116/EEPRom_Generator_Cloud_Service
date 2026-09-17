-- Reference only -- the `eeprom_config` table was already created directly via
-- phpMyAdmin on the remote server. Kept here so the schema is documented/reproducible
-- (e.g. to rebuild it elsewhere); not part of the www/ package, not run automatically.

-- NOTE: `index` is a SQL reserved word. It's kept here (backtick-quoted) because
-- that's the exact column name specified for this table; api/eeprom_config.php quotes
-- it the same way in every query.

CREATE TABLE IF NOT EXISTS eeprom_config (
    `index`         INT(11) NOT NULL AUTO_INCREMENT,
    file_format     VARCHAR(50)  NOT NULL,
    fps             VARCHAR(50)  NOT NULL,
    file_name       VARCHAR(150) NOT NULL,
    MHz             VARCHAR(50)  NOT NULL,
    isPGL           TINYINT(1)   NULL DEFAULT 1,
    output_format   VARCHAR(50)  NOT NULL,
    support_mode    VARCHAR(50)  NOT NULL,
    create_time     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    modify_time     TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    content         TEXT NOT NULL,
    ext_str1        VARCHAR(100) NULL DEFAULT NULL,
    ext_str2        VARCHAR(255) NULL DEFAULT NULL,
    ext_int1        INT(11)      NULL DEFAULT NULL,
    ext_txt1        TEXT NULL DEFAULT NULL,
    ext_txt2        TEXT NULL DEFAULT NULL,
    ext_txt3        TEXT NULL DEFAULT NULL,
    ext_txt4        TEXT NULL DEFAULT NULL,
    ext_txt5        TEXT NULL DEFAULT NULL,
    PRIMARY KEY (`index`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `content` uses a binary collation (case/byte exact) per spec; every other text
-- column uses utf8mb4_unicode_ci, inherited from the table default above.
ALTER TABLE eeprom_config MODIFY content TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Login accounts for the web UI (api/login.php). password_hash stores a bcrypt hash
-- (PHP password_hash()) -- NEVER a plaintext password. Use tools/create_user.php
-- (run locally, not on the web server) to generate the INSERT/UPDATE statement for
-- an account without ever putting a plaintext password in a file or in chat.
--
-- NOTE: this reflects the table as actually created on the remote server (login
-- column is named `name`, not `account`; api/login.php aliases it). It also still
-- has a plaintext `password` column from manual creation -- api/login.php never
-- reads it, and it should be dropped/cleared once you're comfortable doing so:
--   ALTER TABLE users DROP COLUMN password;
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL UNIQUE,   -- login account name
    password      VARCHAR(255) NULL,              -- legacy/unused, plaintext -- see note above
    password_hash VARCHAR(255) NOT NULL,           -- bcrypt hash, checked by api/login.php
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recommended: create a dedicated DB user for this app with access only to this
-- database (not root/admin), e.g.:
--
-- CREATE USER 'items_app'@'%' IDENTIFIED BY 'CHOOSE_A_STRONG_PASSWORD';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON your_database.* TO 'items_app'@'%';
-- FLUSH PRIVILEGES;
--
-- Then put items_app / that password into api/config/config.php (never here, never
-- in chat/tickets/source control).

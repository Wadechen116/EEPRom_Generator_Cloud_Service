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
    -- account: who wrote the row. Stamped server-side from the logged-in session
    -- on every create/update (api/eeprom_config.php) -- never client-settable.
    -- Nullable because rows created before this column existed have none.
    account         VARCHAR(100) NULL DEFAULT NULL,
    -- comment: free text, what this version is for (production/sample/test/...).
    comment         TEXT NULL DEFAULT NULL,
    -- MEDIUMTEXT, not TEXT: a BIN row stores its image as hex text (see the
    -- note below), and 3 characters per byte puts a 64 KB EEPROM image at
    -- ~192 KB -- well past TEXT's 64 KB limit, where MySQL would truncate it.
    content         MEDIUMTEXT NOT NULL,
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
ALTER TABLE eeprom_config MODIFY content MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- ---------------------------------------------------------------------------
-- Value domains
--
-- This table is synced into the desktop tool (E2pRom_Generator, "Sync Cloud"),
-- which stores the same columns with the same values. If the two sides allowed
-- different spellings, the same row would flip back and forth on every sync --
-- so the allowed values are fixed:
--
--   file_format    'INI' | 'BIN'          (upper case)
--   output_format  'AHD' | 'YUV422'
--   support_mode   'Master' | 'Slave'
--   isPGL          1 | 0
--
-- api/eeprom_config.php enforces this on every create and update, and is the
-- authority: the CHECK constraints below are a second line of defence and are
-- silently ignored by MySQL before 8.0.16 and MariaDB before 10.2.
--
-- `content` holds the file: an INI row keeps the file's own text, a BIN row
-- keeps hex bytes, "12 40 AD 01", 16 bytes per line. MySQL TEXT cannot carry
-- raw binary, and the desktop tool stores exactly the same text, so a sync is
-- a straight copy.
-- ---------------------------------------------------------------------------
ALTER TABLE eeprom_config
    ADD CONSTRAINT chk_file_format   CHECK (file_format   IN ('INI', 'BIN')),
    ADD CONSTRAINT chk_output_format CHECK (output_format IN ('AHD', 'YUV422')),
    ADD CONSTRAINT chk_support_mode  CHECK (support_mode  IN ('Master', 'Slave'));

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

-- Migration for the deployed `eeprom_config` table: account + comment.
--
-- STATUS: already applied to the live database (verified 2026-09-23 -- editing
-- a row's comment through the web UI stores it and stamps account). This file
-- is kept as the record of what was run, and so the table can be rebuilt
-- elsewhere without reverse-engineering it out of schema.sql.
--
-- Why:
--   account  who put this row here. Filled by api/eeprom_config.php from the
--            authenticated account on every create and update -- NOT from the
--            request body, so a client cannot claim to be someone else. A
--            download never writes it: the desktop tool's "Sync Cloud" reads
--            every row on every sync, so a "last downloaded by" stamp would be
--            rewritten constantly and would only ever say who synced last.
--   comment  free text describing what this version is for -- production,
--            a customer sample, a test build, why it differs from the one
--            above it. The one field the table has never had, and the reason
--            file names keep growing suffixes like _v2_final_ok.
--
-- ON COLUMN POSITION: this appends both columns; schema.sql draws them after
-- modify_time, which is where they would sit if the table were created from
-- scratch. The two do not have to agree. Every query in api/eeprom_config.php
-- names its columns, and the desktop tool (E2pRom_Generator) matches by column
-- NAME -- its own SQLite table appends them, because SQLite's ALTER TABLE
-- cannot do anything else. Add `AFTER modify_time` below if you would rather
-- the deployed table read like schema.sql; nothing depends on it either way.
--
-- BACK UP FIRST: phpMyAdmin -> Export -> eeprom_config. Adding a column
-- rewrites the table on older MySQL versions and there is no undo.

-- NOTE ON TRANSACTIONS: ALTER TABLE causes an implicit COMMIT in MySQL and
-- cannot be rolled back, so there is no transaction here to give a false sense
-- of safety. A second run fails loudly with "Duplicate column name" and
-- changes nothing.

ALTER TABLE eeprom_config
    ADD COLUMN account VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN comment TEXT NULL DEFAULT NULL;

-- Existing rows keep account = NULL. They were created before anyone was
-- recorded, and guessing an owner is worse than admitting there isn't one:
-- the next update to a row fills it in with whoever made that update.
--
-- If you would rather show something in the web UI for those rows, this sets
-- them all to one account -- only run it if that is actually true:
--
--   UPDATE eeprom_config SET account = 'wade' WHERE account IS NULL;

-- Check: every column present, and nothing lost.
-- SELECT COUNT(*) AS rows_total,
--        SUM(account IS NULL) AS no_account,
--        SUM(comment IS NULL OR comment = '') AS no_comment
--   FROM eeprom_config;

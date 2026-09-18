-- Migration for the deployed `eeprom_config` table: value domains + MEDIUMTEXT.
--
-- Run once, in phpMyAdmin, against the live database. schema.sql already
-- describes the table as it looks after this; this file is what turns an
-- existing table into that.
--
-- Why: the table is synced into the desktop tool (E2pRom_Generator, "Sync
-- Cloud"), which now stores the same columns with the same values. Two sides
-- that spell a value differently would make the same row flip back and forth
-- on every sync, so the allowed values are fixed:
--
--   file_format    'INI' | 'BIN'
--   output_format  'AHD' | 'YUV422'
--   support_mode   'Master' | 'Slave'
--
-- api/eeprom_config.php rejects anything else from now on. These UPDATEs bring
-- the rows that are already in the table into the same shape.
--
-- BACK UP FIRST: phpMyAdmin -> Export -> eeprom_config. The UPDATEs below
-- rewrite existing values and there is no undo.

-- NOTE ON TRANSACTIONS: in MySQL, ALTER TABLE causes an implicit COMMIT and
-- cannot be rolled back, so the two ALTERs below deliberately sit OUTSIDE the
-- transaction. Wrapping them in one would not protect them and would silently
-- end the transaction mid-way, leaving the UPDATEs running unprotected in
-- autocommit. Only the UPDATEs are transactional; the ALTERs are safe to repeat
-- (a second run either succeeds unchanged or reports the constraint exists).

-- 1. content must hold a BIN image as hex text (~3 chars per byte), which
--    outgrows TEXT's 64 KB at around a 21 KB image. MEDIUMTEXT is 16 MB.
ALTER TABLE eeprom_config
    MODIFY content MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

START TRANSACTION;

-- 2. file_format: 'INI' / 'BIN', upper case, taken from the file name where
--    the stored value does not already say.
UPDATE eeprom_config SET file_format = 'INI'
 WHERE UPPER(file_format) = 'INI' OR LOWER(file_name) LIKE '%.ini';
UPDATE eeprom_config SET file_format = 'BIN'
 WHERE UPPER(file_format) = 'BIN' OR LOWER(file_name) LIKE '%.bin';

-- 3. output_format: only AHD and YUV422 remain. Anything else (the sample rows
--    carry 'RAW10') becomes AHD -- check the list afterwards and correct by
--    hand if a row was really something else:
--      SELECT `index`, file_name, output_format FROM eeprom_config;
UPDATE eeprom_config SET output_format = 'YUV422'
 WHERE UPPER(output_format) IN ('YUV422', 'YUV', 'YUV-422');
UPDATE eeprom_config SET output_format = 'AHD'
 WHERE output_format <> 'YUV422';

-- 4. support_mode: only Master and Slave remain. 'Normal' was the sample data's
--    single-device case, which is Master here.
UPDATE eeprom_config SET support_mode = 'Slave'
 WHERE UPPER(support_mode) = 'SLAVE';
UPDATE eeprom_config SET support_mode = 'Master'
 WHERE support_mode <> 'Slave';

-- 5. isPGL is already TINYINT(1); make sure nothing is NULL.
UPDATE eeprom_config SET isPGL = 0 WHERE isPGL IS NULL;

COMMIT;

-- 6. Second line of defence. MySQL before 8.0.16 and MariaDB before 10.2 parse
--    CHECK and ignore it -- harmless there, useful everywhere else. If a row
--    still violates one of these, the ALTER fails and step 2-4 missed
--    something; fix the row and run this again.
ALTER TABLE eeprom_config
    ADD CONSTRAINT chk_file_format   CHECK (file_format   IN ('INI', 'BIN')),
    ADD CONSTRAINT chk_output_format CHECK (output_format IN ('AHD', 'YUV422')),
    ADD CONSTRAINT chk_support_mode  CHECK (support_mode  IN ('Master', 'Slave'));

-- 7. A BIN row whose `content` is not hex text cannot be exported by the
--    desktop tool, and the web UI now uploads .bin files as hex. Existing BIN
--    rows were stored before that rule, so list them and re-upload the files:
--      SELECT `index`, file_name, LEFT(content, 60) FROM eeprom_config
--       WHERE file_format = 'BIN';

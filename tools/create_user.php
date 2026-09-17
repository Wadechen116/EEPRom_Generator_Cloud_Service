<?php
/**
 * Run locally (NOT deployed -- this file lives outside www/) to generate the SQL
 * for a login account, without ever writing the plaintext password to a file.
 *
 * Usage:
 *   php tools/create_user.php <account-name> <password>
 *
 * Prints both an INSERT (brand-new account) and an UPDATE (fix/rotate the
 * password_hash on an account row that already exists) -- use whichever applies.
 * Paste the one you need into phpMyAdmin's SQL tab against the `users` table
 * (see sql/schema.sql). Then clear your shell history if it captured the
 * plaintext password argument.
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/create_user.php <account-name> <password>\n");
    exit(1);
}

[, $name, $password] = $argv;
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "name:          {$name}\n";
echo "password_hash: {$hash}\n\n";

echo "-- New account:\n";
printf(
    "INSERT INTO users (name, password_hash) VALUES (%s, %s);\n\n",
    var_export($name, true),
    var_export($hash, true)
);

echo "-- Fix/rotate the hash on an account that already exists:\n";
printf(
    "UPDATE users SET password_hash = %s WHERE name = %s;\n",
    var_export($hash, true),
    var_export($name, true)
);

<?php
/**
 * Run locally (NOT deployed -- this file lives outside www/) to generate the SQL
 * for a login account, without ever writing the plaintext password to a file.
 *
 * Usage:
 *   php tools/create_user.php <account-name> <password> [email]
 *
 * email is optional -- omit it to generate SQL that doesn't touch the column at
 * all (e.g. for a host where it hasn't been added yet, see sql/schema.sql).
 *
 * Prints both an INSERT (brand-new account) and an UPDATE (fix/rotate the
 * password_hash -- and email, if given -- on an account row that already
 * exists) -- use whichever applies. Paste the one you need into phpMyAdmin's
 * SQL tab against the `users` table. Then clear your shell history if it
 * captured the plaintext password argument.
 */

if ($argc !== 3 && $argc !== 4) {
    fwrite(STDERR, "Usage: php tools/create_user.php <account-name> <password> [email]\n");
    exit(1);
}

[, $name, $password] = $argv;
$email = $argv[3] ?? null;
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "name:          {$name}\n";
echo "password_hash: {$hash}\n";
if ($email !== null) {
    echo "email:         {$email}\n";
}
echo "\n";

echo "-- New account:\n";
if ($email !== null) {
    printf(
        "INSERT INTO users (name, password_hash, email) VALUES (%s, %s, %s);\n\n",
        var_export($name, true),
        var_export($hash, true),
        var_export($email, true)
    );
} else {
    printf(
        "INSERT INTO users (name, password_hash) VALUES (%s, %s);\n\n",
        var_export($name, true),
        var_export($hash, true)
    );
}

echo "-- Fix/rotate the hash" . ($email !== null ? " and email" : "") . " on an account that already exists:\n";
if ($email !== null) {
    printf(
        "UPDATE users SET password_hash = %s, email = %s WHERE name = %s;\n",
        var_export($hash, true),
        var_export($email, true),
        var_export($name, true)
    );
} else {
    printf(
        "UPDATE users SET password_hash = %s WHERE name = %s;\n",
        var_export($hash, true),
        var_export($name, true)
    );
}

<?php
/**
 * Limits password guessing on every login path: the main page (api/login.php), per-request header login
 * (CredentialAuth, used by api/eeprom_config.php) and the monitor page (monitor/index.php).
 *
 * Only FAILED attempts are stored (table login_attempts, sql/migrate_2026-11-13_security.sql). A login is refused
 * BEFORE the password is checked -- even a correct one -- while, inside the last window_minutes:
 *   - the account name has max_per_account failures (any IP), or
 *   - the client IP has max_per_ip failures (any account names).
 * The account rule stops guessing one password from many IPs; the IP rule stops one IP trying many accounts.
 * A successful login clears that account's failures. Old rows are purged now and then.
 *
 * The account name is stored as typed (lower-cased), whether or not it exists, so a lock looks the same for
 * real and made-up names and reveals nothing about which accounts exist.
 * Trade-off (accepted, as everywhere): someone who knows an account name can keep it locked by failing on
 * purpose; the lock lifts by itself window_minutes after the last failures.
 *
 * Timestamps are UTC, written and compared by PHP (gmdate), never by the database clock -- same rule as
 * password_resets. If the table does not exist yet (migration not run), logins still work and an error is logged:
 * a missing table must not lock everyone out.
 */

class LoginThrottle {
    private const DEFAULTS = ['window_minutes' => 15, 'max_per_account' => 5, 'max_per_ip' => 20];

    private static function cfg(array $config): array {
        $c = ($config['login_throttle'] ?? []) + self::DEFAULTS;
        return [
            'window' => max(1, (int) $c['window_minutes']) * 60,
            'acct'   => max(1, (int) $c['max_per_account']),
            'ip'     => max(1, (int) $c['max_per_ip']),
        ];
    }

    public static function clientIp(): string {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private static function norm(string $account): string {
        $a = strtolower(trim($account));
        return function_exists('mb_substr') ? mb_substr($a, 0, 100, 'UTF-8') : substr($a, 0, 100);
    }

    /** Seconds until this account / IP may try again; 0 = go ahead. */
    public static function retryAfter(PDO $pdo, array $config, string $account): int {
        $c = self::cfg($config);
        $since = gmdate('Y-m-d H:i:s', time() - $c['window']);
        try {
            $wait = 0;
            foreach ([['account', self::norm($account), $c['acct']], ['ip', self::clientIp(), $c['ip']]] as [$col, $val, $max]) {
                // The max-th newest failure decides: the lock ends when it leaves the window.
                $st = $pdo->prepare("SELECT attempted_at FROM login_attempts WHERE $col = :v AND attempted_at > :t
                                     ORDER BY attempted_at DESC LIMIT 1 OFFSET " . ($max - 1));
                $st->execute([':v' => $val, ':t' => $since]);
                $at = $st->fetchColumn();
                if ($at !== false) {
                    $wait = max($wait, strtotime($at . ' UTC') + $c['window'] - time());
                }
            }
            return max(0, $wait);
        } catch (PDOException $e) {
            error_log('[throttle] check skipped: ' . $e->getMessage());
            return 0;
        }
    }

    public static function fail(PDO $pdo, string $account, string $source): void {
        try {
            $pdo->prepare('INSERT INTO login_attempts (account, ip, source, attempted_at) VALUES (:a, :ip, :s, :t)')
                ->execute([':a' => self::norm($account), ':ip' => self::clientIp(), ':s' => substr($source, 0, 16),
                           ':t' => gmdate('Y-m-d H:i:s')]);
            if (mt_rand(1, 50) === 1) {   // occasional purge; nothing older than a day is ever needed
                $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :t')
                    ->execute([':t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
            }
        } catch (PDOException $e) {
            error_log('[throttle] could not record failure: ' . $e->getMessage());
        }
    }

    public static function success(PDO $pdo, string $account): void {
        try {
            $pdo->prepare('DELETE FROM login_attempts WHERE account = :a')->execute([':a' => self::norm($account)]);
        } catch (PDOException $e) {
            error_log('[throttle] could not clear failures: ' . $e->getMessage());
        }
    }

    /** "N 分鐘" style helper: whole minutes, at least 1. */
    public static function minutes(int $seconds): int {
        return max(1, (int) ceil($seconds / 60));
    }
}

<?php
/**
 * Sends mail through the existing corporate SMTP relay, using the vendored PHPMailer
 * (api/lib/phpmailer/, see VENDORED.txt there). The app never runs a mail server of its own.
 *
 * Everything the relay needs comes from the 'mail' block of api/config/config.php. This file
 * holds no host names, accounts or passwords, and whoever deploys the app never has to hand
 * credentials to a developer: the mail admin fills the block in on the server.
 *
 * sendPasswordReset() never throws and never prints. It returns false and writes the reason to
 * the server's PHP error log, because its caller (api/forgot_password.php) has already answered
 * the browser and must not leak, in any way, whether a mail went out.
 */

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Mailer {
    /**
     * True only when mail is switched on AND complete. While it is false, forgot-password
     * requests are accepted but nothing is stored or sent -- the state this app ships in until
     * the mail admin has opened the relay for this server.
     */
    public static function isEnabled(array $config): bool {
        $mail = $config['mail'] ?? null;
        return is_array($mail)
            && !empty($mail['enabled'])
            && !empty($mail['host'])
            && !empty($mail['from'])
            && !empty($config['app_url']);
    }

    /** Minutes a reset link stays valid. Clamped so a typo in config can't make it 0 or a year. */
    public static function resetTtlMinutes(array $config): int {
        $ttl = (int) ($config['mail']['reset_ttl_minutes'] ?? 30);
        return max(5, min(1440, $ttl));
    }

    public static function sendPasswordReset(array $config, string $toEmail, string $account, string $link, int $ttlMinutes): bool {
        if (!self::isEnabled($config)) {
            return false;
        }
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[Mailer] refusing to send: recipient is not a valid email address');
            return false;
        }

        // First 4xx/5xx line the relay itself sent (e.g. "554 5.7.1 <...>: Client host rejected: Access denied").
        // PHPMailer's exception text keeps the relay's wording but drops the numeric status, and by the time it
        // throws it has already sent QUIT, so the status can't be read back afterwards -- collect it as it arrives.
        // Only SERVER replies are kept: the CLIENT lines of the transcript include the (base64) AUTH credentials.
        $relayError = '';

        try {
            require_once __DIR__ . '/phpmailer/Exception.php';
            require_once __DIR__ . '/phpmailer/PHPMailer.php';
            require_once __DIR__ . '/phpmailer/SMTP.php';

            $cfg = $config['mail'];
            $mail = new PHPMailer(true);   // true = throw MailException on failure

            $mail->isSMTP();
            $mail->Host = (string) $cfg['host'];
            $mail->Port = (int) ($cfg['port'] ?? 25);
            $mail->Timeout = max(3, (int) ($cfg['timeout'] ?? 10));   // a blocked relay must not hang the script
            $mail->SMTPKeepAlive = false;
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function ($line) use (&$relayError) {
                if ($relayError === '' && preg_match('/^SERVER -> CLIENT:\s*([45]\d\d[ -].*)$/', trim((string) $line), $m)) {
                    $relayError = substr($m[1], 0, 300);
                }
            };

            // Encryption is explicit, never opportunistic: '' = plain, 'tls' = STARTTLS (usually 587),
            // 'ssl' = implicit TLS (usually 465). Plain port 25 needs nothing from PHP's openssl extension.
            $encryption = strtolower((string) ($cfg['encryption'] ?? ''));
            $mail->SMTPAutoTLS = false;
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            // Credentials are optional: a relay that allow-lists this server's IP needs none.
            if ((string) ($cfg['username'] ?? '') !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = (string) $cfg['username'];
                $mail->Password = (string) ($cfg['password'] ?? '');
            }

            $mail->CharSet = 'UTF-8';
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->XMailer = null;   // don't advertise the PHPMailer version in a header

            $mail->setFrom((string) $cfg['from'], (string) ($cfg['from_name'] ?? ''));
            $mail->addAddress($toEmail);
            $mail->isHTML(false);
            $mail->Subject = 'Reset your password - EEPROM Generator cloud service';
            $mail->Body = self::resetBody($account, $link, $ttlMinutes);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            // Server log only. The message can name the rejected recipient and the relay's verdict
            // (e.g. "554 5.7.1 ... Client host rejected: Access denied") -- exactly what the mail
            // admin needs to see; it never goes to the browser, and the reset link is never logged.
            // One line per failure: PHPMailer's text ends in a line break, which would split the entry in two.
            error_log('[Mailer] password-reset mail not sent: ' . preg_replace('/\s+/', ' ', trim($e->getMessage()))
                . ($relayError !== '' ? ' [relay replied: ' . preg_replace('/\s+/', ' ', $relayError) . ']' : ''));
            return false;
        }
    }

    private static function resetBody(string $account, string $link, int $ttlMinutes): string {
        return "Hello {$account},\r\n"
            . "\r\n"
            . "We received a request to reset the password of your account on the EEPROM Generator cloud service.\r\n"
            . "\r\n"
            . "Open this link to choose a new password. It works once and expires in {$ttlMinutes} minutes:\r\n"
            . "\r\n"
            . "{$link}\r\n"
            . "\r\n"
            . "If you did not ask for this, ignore this email: your password has not been changed.\r\n";
    }
}

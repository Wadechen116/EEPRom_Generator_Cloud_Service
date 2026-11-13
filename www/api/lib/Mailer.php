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

    /**
     * The address in the reset mail:  <app_url>/index.html#reset=<token>.
     *
     * The page is named instead of leaving it to the directory address. A bare ".../chamonix/" is answered with
     * whatever the web server's DirectoryIndex lists first, and on this server that is an index.php, not the
     * app's index.html, so the link opened the wrong page. If app_url already ends in a page (.html, .htm, .php)
     * it is used as it is. The token stays in the fragment (never sent to the server, so not in access logs).
     */
    public static function resetLink(array $config, string $token): string {
        $base = (string) ($config['app_url'] ?? '');
        $hash = strpos($base, '#');
        if ($hash !== false) {
            // app_url is the address of the SITE, not of a reset link. A "#" in it means someone pasted a whole
            // link (".../index.html#reset=<token>"), which would put a second "#reset=" in the mailed address and
            // make every link fail. Drop the fragment and say so.
            error_log('[Mailer] app_url in config.php contains "#" and everything from it is ignored: it must be the '
                . 'site\'s address only, e.g. https://host/chamonix');
            $base = substr($base, 0, $hash);
        }
        $base = rtrim($base, '/');
        if (!preg_match('~\.(html?|php)$~i', $base)) {
            $base .= '/index.html';
        }
        return $base . '#reset=' . $token;
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
            // Two versions of the same text. The link is 100+ characters, wider than the 76-78 columns most mail
            // programs show a plain-text line in, so they fold it in two and only the first piece becomes a
            // hyperlink. In the HTML part the address sits in an href behind a short label and cannot be folded.
            $mail->isHTML(true);
            $mail->Subject = 'Reset your password - EEPROM Generator cloud service';
            $mail->Body = self::resetBodyHtml($account, $link, $ttlMinutes);
            $mail->AltBody = self::resetBody($account, $link, $ttlMinutes);

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

    /** Plain-text version (shown by programs that do not show HTML, and by PHPMailer as the alternative part). */
    private static function resetBody(string $account, string $link, int $ttlMinutes): string {
        return "Hello {$account},\r\n"
            . "\r\n"
            . "We received a request to reset the password of your account on the EEPROM Generator cloud service.\r\n"
            . "\r\n"
            . "Open this link to choose a new password. It works once and expires in {$ttlMinutes} minutes:\r\n"
            . "\r\n"
            . "{$link}\r\n"
            . "\r\n"
            . "If your mail program shows that link in two pieces, paste both pieces into the browser's address bar\r\n"
            . "one after the other, with nothing between them, as a single line.\r\n"
            . "\r\n"
            . "If you did not ask for this, ignore this email: your password has not been changed.\r\n";
    }

    /** HTML version: the same words, with the link behind a short label. Everything inserted is escaped. */
    private static function resetBodyHtml(string $account, string $link, int $ttlMinutes): string {
        $e = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $href = $e($link);
        return '<p>Hello ' . $e($account) . ',</p>' . "\r\n"
            . '<p>We received a request to reset the password of your account on the EEPROM Generator cloud service.</p>' . "\r\n"
            . '<p><a href="' . $href . '">Choose a new password</a></p>' . "\r\n"
            . '<p>The link works once and expires in ' . (int) $ttlMinutes . ' minutes. If it does not open, copy this whole address '
            . 'into the browser\'s address bar as one line:<br>' . $href . '</p>' . "\r\n"
            . '<p>If you did not ask for this, ignore this email: your password has not been changed.</p>' . "\r\n";
    }
}

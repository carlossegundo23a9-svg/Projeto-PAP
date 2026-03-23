<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/env.php';
app_env_load();

$appMailerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($appMailerAutoload)) {
    require_once $appMailerAutoload;
}

if (!function_exists('app_mail_env')) {
    function app_mail_env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false && isset($_ENV[$key])) {
            $value = (string) $_ENV[$key];
        }
        if ($value === false && isset($_SERVER[$key])) {
            $value = (string) $_SERVER[$key];
        }

        return trim((string) ($value === false ? $default : $value));
    }
}

if (!function_exists('app_mail_config')) {
    /**
     * @return array{
     *   host:string,
     *   port:int,
     *   encryption:string,
     *   username:string,
     *   password:string,
     *   from_email:string,
     *   from_name:string
     * }
     */
    function app_mail_config(): array
    {
        $port = app_mail_env('APP_MAIL_PORT', '587');
        $parsedPort = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['default' => 587, 'min_range' => 1, 'max_range' => 65535],
        ]);

        return [
            'host' => app_mail_env('APP_MAIL_HOST', ''),
            'port' => (int) $parsedPort,
            'encryption' => strtolower(app_mail_env('APP_MAIL_ENCRYPTION', 'tls')),
            'username' => app_mail_env('APP_MAIL_USERNAME', 'estel.app@estel.edu.pt'),
            'password' => app_mail_env('APP_MAIL_PASSWORD', 'bsoh cgsj fcsb estg'),
            'from_email' => app_mail_env('APP_MAIL_FROM_EMAIL', 'estel.app@estel.edu.pt'),
            'from_name' => app_mail_env('APP_MAIL_FROM_NAME', 'ESTEL SGP'),
        ];
    }
}

if (!function_exists('app_mail_is_valid_email')) {
    function app_mail_is_valid_email(string $email): bool
    {
        $email = trim($email);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('app_mail_is_ready')) {
    function app_mail_is_ready(?array $mailConfig = null): bool
    {
        $cfg = is_array($mailConfig) ? $mailConfig : app_mail_config();

        return isset($cfg['host'], $cfg['username'], $cfg['password'], $cfg['from_email'])
            && trim((string) $cfg['host']) !== ''
            && trim((string) $cfg['username']) !== ''
            && (string) $cfg['password'] !== ''
            && app_mail_is_valid_email((string) $cfg['from_email'])
            && class_exists(PHPMailer::class);
    }
}

if (!function_exists('app_mail_send_plain')) {
    function app_mail_send_plain(string $to, string $subject, string $body, ?string &$error = null): bool
    {
        $to = trim($to);
        if (!app_mail_is_valid_email($to)) {
            $error = 'Destinatário inválido.';
            return false;
        }

        $mailConfig = app_mail_config();
        if (!class_exists(PHPMailer::class)) {
            $error = 'PHPMailer não encontrado. Execute composer install.';
            return false;
        }
        if (!app_mail_is_ready($mailConfig)) {
            $error = 'SMTP não configurado. Defina APP_MAIL_* no ambiente.';
            return false;
        }

        $fromEmail = trim((string) $mailConfig['from_email']);
        $fromName = trim((string) $mailConfig['from_name']);
        if ($fromName === '') {
            $fromName = 'ESTEL SGP';
        }

        try {
            $mailer = new PHPMailer(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = 'base64';
            $mailer->isHTML(false);
            $mailer->isSMTP();

            $mailer->Host = trim((string) $mailConfig['host']);
            $mailer->Port = (int) $mailConfig['port'];

            $smtpSecure = strtolower(trim((string) $mailConfig['encryption']));
            if ($smtpSecure === 'ssl' || $smtpSecure === 'smtps') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mailer->SMTPAuth = true;
            $mailer->Username = trim((string) $mailConfig['username']);
            $mailer->Password = (string) $mailConfig['password'];

            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->AltBody = $body;

            return $mailer->send();
        } catch (Throwable $e) {
            $error = trim($e->getMessage()) !== '' ? $e->getMessage() : 'Erro ao enviar email.';
            return false;
        }
    }
}

if (!function_exists('app_mail_send_html')) {
    function app_mail_send_html(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $altBody = null,
        ?string &$error = null
    ): bool {
        $to = trim($to);
        if (!app_mail_is_valid_email($to)) {
            $error = 'Destinatário inválido.';
            return false;
        }

        $mailConfig = app_mail_config();
        if (!class_exists(PHPMailer::class)) {
            $error = 'PHPMailer não encontrado. Execute composer install.';
            return false;
        }
        if (!app_mail_is_ready($mailConfig)) {
            $error = 'SMTP não configurado. Defina APP_MAIL_* no ambiente.';
            return false;
        }

        $fromEmail = trim((string) $mailConfig['from_email']);
        $fromName = trim((string) $mailConfig['from_name']);
        if ($fromName === '') {
            $fromName = 'ESTEL SGP';
        }

        if ($altBody === null || trim($altBody) === '') {
            $converted = str_replace(["\r\n", "\r"], "\n", $htmlBody);
            $converted = preg_replace('/<br\s*\/?>/i', "\n", $converted);
            $converted = preg_replace('/<\/(p|div|tr|table|li|h1|h2|h3|h4|h5|h6)>/i', "\n", (string) $converted);
            $altBody = trim((string) preg_replace('/\n{3,}/', "\n\n", strip_tags((string) $converted)));
        }

        try {
            $mailer = new PHPMailer(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = 'base64';
            $mailer->isHTML(true);
            $mailer->isSMTP();

            $mailer->Host = trim((string) $mailConfig['host']);
            $mailer->Port = (int) $mailConfig['port'];

            $smtpSecure = strtolower(trim((string) $mailConfig['encryption']));
            if ($smtpSecure === 'ssl' || $smtpSecure === 'smtps') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mailer->SMTPAuth = true;
            $mailer->Username = trim((string) $mailConfig['username']);
            $mailer->Password = (string) $mailConfig['password'];

            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = (string) $altBody;

            return $mailer->send();
        } catch (Throwable $e) {
            $error = trim($e->getMessage()) !== '' ? $e->getMessage() : 'Erro ao enviar email.';
            return false;
        }
    }
}


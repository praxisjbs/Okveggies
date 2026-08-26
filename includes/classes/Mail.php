<?php
/**
 * includes/classes/Mail.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Server-side email through the cPanel SMTP account, using
 * PHPMailer when it is present in vendor/. If PHPMailer is not installed yet,
 * the message is logged rather than silently dropped, so a partial deploy never
 * looks like it delivered mail when it did not.
 *
 * Reads SMTP_* from .env (see .env.example). Transactional copy is plain and
 * always offers a next step. No jargon, no em dash.
 * -----------------------------------------------------------------------------
 */

final class Mail
{
    /** Send an email. Returns true if handed to SMTP, false if it could not be sent. */
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $fromEmail = (string) env('SMTP_FROM_EMAIL', env('SMTP_USER', 'noreply@okveggies.com.ng'));
        $fromName  = (string) env('SMTP_FROM_NAME', 'OK Veggies');

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log("Mail (PHPMailer missing) to=$to subject=" . $subject);
            return false;
        }

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host       = (string) env('SMTP_HOST', 'localhost');
            $mailer->Port       = (int) env('SMTP_PORT', 465);
            $mailer->SMTPAuth   = true;
            $mailer->Username   = (string) env('SMTP_USER', '');
            $mailer->Password   = (string) env('SMTP_PASS', '');
            $enc = strtolower((string) env('SMTP_ENCRYPTION', 'ssl'));
            if ($enc === 'tls') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($enc === 'ssl') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $htmlBody;
            $mailer->AltBody = $textBody ?? trim(strip_tags($htmlBody));
            $mailer->send();
            return true;
        } catch (Throwable $e) {
            error_log('Mail send failed to=' . $to . ' error=' . $e->getMessage());
            return false;
        }
    }

    /**
     * Render a named template from notification_templates, replacing {{tokens}}.
     * Returns [subject, body] or null if the template is missing.
     */
    public static function renderTemplate(string $key, array $vars = []): ?array
    {
        $tpl = Database::one('SELECT subject_template, body_template FROM notification_templates WHERE template_key = :k AND is_active = 1', [':k' => $key]);
        if (!$tpl) {
            return null;
        }
        $subject = self::fill($tpl['subject_template'] ?? '', $vars);
        $body    = self::fill($tpl['body_template'] ?? '', $vars);
        return [$subject, $body];
    }

    private static function fill(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function ($m) use ($vars) {
            return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '';
        }, $template);
    }
}

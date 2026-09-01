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

    /**
     * Render a template and send it inside the branded HTML shell, with a plain
     * text alternative built from the same copy. This is the way a customer
     * facing email is sent: the words live in notification_templates where the
     * admin edits them, the letterhead lives here where the brand guard can see
     * it, and neither can drift from the other.
     *
     * $cta is the one action the email asks for: ['label' => ..., 'url' => ...].
     * Returns false when the template is missing or SMTP refused it, so the
     * caller can tell the person rather than reporting a silent success.
     */
    public static function sendTemplate(string $to, string $key, array $vars = [], ?array $cta = null, string $footNote = ''): bool
    {
        $tpl = self::renderTemplate($key, $vars);
        if ($tpl === null) {
            error_log('Mail::sendTemplate: template missing: ' . $key);
            return false;
        }
        [$subject, $body] = $tpl;
        $cta = $cta ?? self::ctaFromVars($vars);
        return self::send(
            $to,
            $subject,
            self::brandedHtml($subject, $body, $cta, $footNote),
            self::plainText($subject, $body, $cta, $footNote)
        );
    }

    /**
     * The button an email gets when the caller did not name one. Migration 009
     * took the raw link out of the words, so the link now has to come from the
     * variables; this makes sure a sender that passes an address but forgets to
     * ask for a button still sends one, rather than an email with no way on.
     *
     * @return array{label: string, url: string}|null
     */
    public static function ctaFromVars(array $vars): ?array
    {
        $labels = [
            'order_trail_url' => 'Follow your order',
            'activate_url'    => 'Activate your account',
            'reset_url'       => 'Set a new password',
            'invoice_url'     => 'View the invoice',
            'receipt_url'     => 'View the receipt',
        ];
        foreach ($labels as $key => $label) {
            if (!empty($vars[$key])) {
                return ['label' => $label, 'url' => (string) $vars[$key]];
            }
        }
        foreach ($vars as $key => $value) {
            if (str_ends_with((string) $key, '_url') && !empty($value)) {
                return ['label' => 'Open this in your browser', 'url' => (string) $value];
            }
        }
        return null;
    }

    /**
     * The branded HTML email. Tables and inline styles, because that is what an
     * email client renders reliably; the colours come from Brand, which mirrors
     * tailwind.config.js, so an email is never a second set of brand values.
     *
     * The mark is a PNG, not the SVG the site uses, since Gmail drops an SVG.
     * Its alt text is styled, so an email client with images switched off still
     * shows "OK Veggies" in white on the forest band rather than a broken icon.
     */
    public static function brandedHtml(string $heading, string $body, ?array $cta = null, string $footNote = ''): string
    {
        $base    = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $name    = defined('OKV_BOOTSTRAPPED') ? Settings::str('business_name', 'OK Veggies') : 'OK Veggies';
        $tagline = defined('OKV_BOOTSTRAPPED')
            ? Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.')
            : 'Sourced right. Priced right. Delivered right.';
        $support = defined('OKV_BOOTSTRAPPED') ? Settings::str('support_email', 'hello@okveggies.com.ng') : 'hello@okveggies.com.ng';

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $paragraphs = '';
        foreach (preg_split('/\n\s*\n/', trim($body)) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }
            $paragraphs .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:' . Brand::INK . '">'
                . nl2br($e($chunk)) . '</p>';
        }

        $button = '';
        if ($cta && !empty($cta['url']) && !empty($cta['label'])) {
            $button =
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0"><tr>'
                . '<td style="background:' . Brand::FOREST . ';border-radius:6px">'
                . '<a href="' . $e((string) $cta['url']) . '" style="display:inline-block;padding:12px 24px;'
                . 'font-family:' . Brand::FONT_SANS . ';font-size:16px;font-weight:600;line-height:20px;'
                . 'color:' . Brand::WHITE . ';text-decoration:none">' . $e((string) $cta['label']) . '</a>'
                . '</td></tr></table>'
                . '<p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:' . Brand::INK_MUTED . '">'
                . 'Or paste this into your browser: <span style="color:' . Brand::FOREST . '">' . $e((string) $cta['url']) . '</span></p>';
        }

        $note = $footNote !== ''
            ? '<p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:' . Brand::INK_MUTED . '">' . $e($footNote) . '</p>'
            : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($heading) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . Brand::FOREST_TINT . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:' . Brand::FOREST_TINT . ';padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:600px;max-width:100%;background:' . Brand::WHITE . ';border-radius:12px;overflow:hidden;'
            . 'font-family:' . Brand::FONT_SANS . '">'
            // Letterhead.
            . '<tr><td style="background:' . Brand::FOREST . ';padding:24px" align="center">'
            . '<img src="' . $e($base . '/assets/img/brand/lockup-white-720.png') . '" width="240" '
            . 'alt="' . $e($name) . '" style="display:block;width:240px;max-width:100%;height:auto;border:0;'
            . 'color:' . Brand::WHITE . ';font-size:20px;font-weight:800">'
            . '</td></tr>'
            // Body.
            . '<tr><td style="padding:32px 24px">'
            . '<h1 style="margin:0 0 16px;font-size:25px;line-height:1.35;font-weight:800;color:' . Brand::INK . '">'
            . $e($heading) . '</h1>'
            . $paragraphs . $button
            . '</td></tr>'
            // Footer.
            . '<tr><td style="padding:16px 24px 24px;border-top:1px solid ' . Brand::MIST . '">'
            . $note
            . '<p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:' . Brand::INK_MUTED . '">'
            . $e($name) . '. ' . $e($tagline) . '</p>'
            . '<p style="margin:0;font-size:12px;line-height:1.6;color:' . Brand::INK_MUTED . '">'
            . 'Reply to this email or write to <span style="color:' . Brand::FOREST . '">' . $e($support) . '</span>'
            . ' if anything is not right. We will make it right.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * The plain text alternative, from the same copy. It carries the link as
     * text, so a client that shows only plain text still has the next step.
     */
    public static function plainText(string $heading, string $body, ?array $cta = null, string $footNote = ''): string
    {
        $out = $heading . "\n\n" . trim($body);
        if ($cta && !empty($cta['url'])) {
            $label = trim((string) ($cta['label'] ?? 'Open this link'));
            $out .= "\n\n" . $label . ': ' . $cta['url'];
        }
        if ($footNote !== '') {
            $out .= "\n\n" . $footNote;
        }
        return $out . "\n";
    }

    private static function fill(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function ($m) use ($vars) {
            return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '';
        }, $template);
    }
}

<?php
/**
 * public/setup.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One-time first-Owner setup, for a shell-less host. It creates the
 * very first staff account (the Owner) so there is someone to sign in as. After
 * that it closes itself.
 *
 * Same guard shape as public/migrate.php:
 *   - Fails closed: if SETUP_TOKEN is not set in .env, this returns 404.
 *   - A wrong or missing token returns 404, so it does not reveal it exists.
 *   - The token is compared with hash_equals.
 *   - It refuses the moment any staff user exists, so it cannot be used twice.
 *   - The POST is CSRF checked as well.
 *
 * Set a strong SETUP_TOKEN in the server .env (openssl rand -hex 32), create the
 * Owner, then remove SETUP_TOKEN from .env. No exception is ever shown here; the
 * log carries the detail.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
header('X-Robots-Tag: noindex');

// --- Token guard. Fail closed. ----------------------------------------------
$expected = (string) env('SETUP_TOKEN', '');
$given    = $_SERVER['HTTP_X_SETUP_TOKEN'] ?? ($_POST['token'] ?? ($_GET['token'] ?? ''));
if ($expected === '' || !is_string($given) || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

/** True if any staff user already exists (by role or by type). */
function okv_setup_staff_exists(): bool
{
    $roleRows = Database::one('SELECT COUNT(*) AS c FROM user_roles');
    $staffRows = Database::one('SELECT COUNT(*) AS c FROM users WHERE user_type = :t', [':t' => 'staff']);
    return ((int) ($roleRows['c'] ?? 0) > 0) || ((int) ($staffRows['c'] ?? 0) > 0);
}

/** Render a small branded page and stop. */
function okv_setup_page(string $title, string $bodyHtml, int $code = 200): void
{
    http_response_code($code);
    $cssHref = okv_asset('/assets/css/tailwind.css');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . okv_e($title) . ' . OK Veggies</title>'
       . '<link rel="stylesheet" href="' . okv_e($cssHref) . '"></head>'
       . '<body class="min-h-screen bg-forest flex items-center justify-center p-4">'
       . '<div class="w-full max-w-md bg-white rounded-lg shadow-okv-3 p-8">'
       . '<p class="text-center uppercase tracking-[0.2em] text-gold text-xs font-semibold">OK Veggies</p>'
       . $bodyHtml
       . '</div></body></html>';
    exit;
}

try {
    // Already set up? Say so and point at sign in. Never create a second Owner.
    if (okv_setup_staff_exists()) {
        okv_setup_page(
            'Setup complete',
            '<h1 class="text-center font-display font-extrabold text-2xl text-ink mt-1">Setup is already done</h1>'
          . '<p class="text-ink-60 text-sm mt-3 text-center">A staff account already exists, so this page is closed. '
          . 'Please remove SETUP_TOKEN from your server .env.</p>'
          . '<a href="/admin/login.php" class="okv-btn w-full mt-6 text-center">Go to sign in</a>',
            409
        );
    }

    $errors = [];
    $values = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];

    if (okv_is_post()) {
        if (!Csrf::validate()) {
            $errors[] = 'Your session expired. Reload the page and try again.';
        }
        $values['first_name'] = trim((string) okv_input('first_name', ''));
        $values['last_name']  = trim((string) okv_input('last_name', ''));
        $values['email']      = trim((string) okv_input('email', ''));
        $values['phone']      = trim((string) okv_input('phone', ''));
        $password             = (string) okv_input('password', '');
        $confirm              = (string) okv_input('confirm_password', '');

        if ($values['first_name'] === '' || $values['last_name'] === '') {
            $errors[] = 'Enter the first and last name.';
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($values['phone'] === '' || strlen(preg_replace('/[^0-9]/', '', $values['phone'])) < 7) {
            $errors[] = 'Enter a valid phone number.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }
        $policy = Password::policyError($password, $values['email'], $values['phone']);
        if ($policy !== null) {
            $errors[] = $policy;
        }
        if (!$errors) {
            $clash = Database::one(
                'SELECT id FROM users WHERE email = :e OR phone = :p LIMIT 1',
                [':e' => $values['email'], ':p' => $values['phone']]
            );
            if ($clash) {
                $errors[] = 'That email or phone is already in use.';
            }
        }

        if (!$errors) {
            $pdo = Database::getInstance()->getConnection();
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
                     VALUES (:fn, :ln, :em, :ph, :pw, :ut, :st, NOW())'
                )->execute([
                    ':fn' => $values['first_name'],
                    ':ln' => $values['last_name'],
                    ':em' => $values['email'],
                    ':ph' => $values['phone'],
                    ':pw' => Password::hash($password),
                    ':ut' => 'staff',
                    ':st' => 'active',
                ]);
                $userId = (int) $pdo->lastInsertId();

                $role = Database::one('SELECT id FROM roles WHERE name = :n', [':n' => 'owner']);
                if (!$role) {
                    throw new RuntimeException('Owner role missing. Run the migrations first.');
                }
                // The very first Owner has no one to have assigned them.
                $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:u, :r, NULL)')
                    ->execute([':u' => $userId, ':r' => (int) $role['id']]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('setup: owner create failed: ' . $e->getMessage());
                okv_setup_page(
                    'Setup',
                    '<h1 class="text-center font-display font-extrabold text-2xl text-ink mt-1">We could not finish</h1>'
                  . '<p class="text-ink-60 text-sm mt-3 text-center">Something went wrong while creating the account. '
                  . 'Please check the server log and try again.</p>'
                  . '<a href="/public/setup.php?token=' . okv_e(rawurlencode($given)) . '" class="okv-btn w-full mt-6 text-center">Back to setup</a>',
                    500
                );
            }

            okv_setup_page(
                'Setup complete',
                '<h1 class="text-center font-display font-extrabold text-2xl text-ink mt-1">Owner account created</h1>'
              . '<p class="text-ink-60 text-sm mt-3 text-center">You can sign in now. For safety, remove SETUP_TOKEN from your '
              . 'server .env so this page can never run again.</p>'
              . '<a href="/admin/login.php" class="okv-btn w-full mt-6 text-center">Go to sign in</a>'
            );
        }
    }

    // Render the form (first load, or after a validation error).
    $errorHtml = '';
    if ($errors) {
        $items = '';
        foreach ($errors as $e) {
            $items .= '<li>' . okv_e($e) . '</li>';
        }
        $errorHtml = '<div role="alert" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3 mt-5">'
                   . '<ul class="list-disc pl-5 space-y-1">' . $items . '</ul></div>';
    }

    okv_setup_page(
        'First-time setup',
        '<h1 class="text-center font-display font-extrabold text-2xl text-ink mt-1">Create the Owner</h1>'
      . '<p class="text-ink-60 text-sm mt-2 text-center">This runs once, to make the first staff account.</p>'
      . $errorHtml
      . '<form method="POST" action="/public/setup.php" class="mt-6 space-y-4" autocomplete="off">'
      . Csrf::field()
      . '<input type="hidden" name="token" value="' . okv_e($given) . '">'
      . '<div class="grid grid-cols-2 gap-4">'
      . '<div><label for="first_name" class="okv-label">First name</label>'
      . '<input id="first_name" name="first_name" type="text" required class="okv-input" value="' . okv_e($values['first_name']) . '"></div>'
      . '<div><label for="last_name" class="okv-label">Last name</label>'
      . '<input id="last_name" name="last_name" type="text" required class="okv-input" value="' . okv_e($values['last_name']) . '"></div>'
      . '</div>'
      . '<div><label for="email" class="okv-label">Email</label>'
      . '<input id="email" name="email" type="email" required class="okv-input" value="' . okv_e($values['email']) . '"></div>'
      . '<div><label for="phone" class="okv-label">Phone number</label>'
      . '<input id="phone" name="phone" type="text" required class="okv-input" value="' . okv_e($values['phone']) . '"></div>'
      . '<div><label for="password" class="okv-label">Password</label>'
      . '<input id="password" name="password" type="password" required autocomplete="new-password" class="okv-input"></div>'
      . '<div><label for="confirm_password" class="okv-label">Confirm password</label>'
      . '<input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password" class="okv-input"></div>'
      . '<button type="submit" class="okv-btn w-full">Create the Owner account</button>'
      . '</form>'
    );

} catch (Throwable $e) {
    error_log('setup: ' . $e->getMessage());
    http_response_code(500);
    echo 'Configuration error. Please check the server log.';
    exit;
}

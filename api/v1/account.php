<?php
/**
 * api/v1/account.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The signed-in customer's own account: their delivery addresses and
 * their profile. Built in milestone M1 Part 2. See docs/PRD.md Section 10.
 *
 * Every action is for the customer in the current session and is scoped to their
 * own rows, so one person can never read or change another person's address.
 * Reads are GET; every change is a POST with a valid CSRF token. Prepared
 * statements only. No exception ever reaches the client.
 *
 * Actions:
 *   list_addresses       (GET)   the customer's saved delivery addresses
 *   add_address          (POST)  save a new delivery address
 *   update_address       (POST)  edit one of their addresses
 *   delete_address       (POST)  remove one of their addresses
 *   set_default_address  (POST)  choose the default delivery address
 *   update_profile       (POST)  edit name and phone (email stays fixed for now)
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** Guard a write: signed-in customer, POST, valid CSRF. */
function acc_guard_write(): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Customer::requireLoginApi();
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

/** Read and validate the address fields, or stop with a plain message. */
function acc_read_address(): array
{
    $name  = trim((string) okv_input('recipient_name', ''));
    $line1 = trim((string) okv_input('address_line_1', ''));
    $city  = trim((string) okv_input('city', ''));
    $state = trim((string) okv_input('state', ''));
    $phone = Phone::normalize((string) okv_input('recipient_phone', ''));

    if ($name === '') {
        okv_error('Enter who should receive the delivery.', 422, 'missing_recipient');
    }
    if ($phone === null) {
        okv_error('Enter a valid delivery phone number, for example 0803 000 0000.', 422, 'bad_phone');
    }
    if ($line1 === '') {
        okv_error('Enter the street address.', 422, 'missing_line1');
    }
    if ($city === '') {
        okv_error('Enter the city or area.', 422, 'missing_city');
    }
    if ($state === '') {
        okv_error('Enter the state.', 422, 'missing_state');
    }

    $line2 = trim((string) okv_input('address_line_2', ''));
    $land  = trim((string) okv_input('landmark', ''));
    $label = trim((string) okv_input('label', ''));

    return [
        'label'          => $label !== '' ? $label : null,
        'recipient_name' => $name,
        'recipient_phone'=> $phone,
        'address_line_1' => $line1,
        'address_line_2' => $line2 !== '' ? $line2 : null,
        'city'           => $city,
        'state'          => $state,
        'landmark'       => $land !== '' ? $land : null,
    ];
}

function acc_truthy($v): bool
{
    return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
}

switch ($action) {

    case 'list_addresses': {
        Customer::requireLoginApi();
        $rows = Database::all(
            'SELECT id, label, recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark, is_default
               FROM customer_addresses WHERE user_id = :u
              ORDER BY is_default DESC, id DESC',
            [':u' => Customer::id()]
        );
        $out = array_map(static function ($r) {
            $r['id']                     = (int) $r['id'];
            $r['is_default']             = (bool) $r['is_default'];
            $r['recipient_phone_display']= Phone::display($r['recipient_phone']);
            return $r;
        }, $rows);
        okv_json(['status' => 'ok', 'addresses' => $out]);
        break;
    }

    case 'add_address': {
        acc_guard_write();
        $a   = acc_read_address();
        $uid = (int) Customer::id();

        $count   = (int) (Database::one('SELECT COUNT(*) AS c FROM customer_addresses WHERE user_id = :u', [':u' => $uid])['c'] ?? 0);
        $default = acc_truthy(okv_input('is_default', '')) || $count === 0; // the first address is the default

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            if ($default) {
                $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE user_id = :u')->execute([':u' => $uid]);
            }
            $pdo->prepare(
                'INSERT INTO customer_addresses
                    (user_id, label, recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark, is_default)
                 VALUES (:u, :label, :rn, :rp, :l1, :l2, :city, :state, :lm, :def)'
            )->execute([
                ':u' => $uid, ':label' => $a['label'], ':rn' => $a['recipient_name'], ':rp' => $a['recipient_phone'],
                ':l1' => $a['address_line_1'], ':l2' => $a['address_line_2'], ':city' => $a['city'],
                ':state' => $a['state'], ':lm' => $a['landmark'], ':def' => $default ? 1 : 0,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('account.add_address failed: ' . $e->getMessage());
            okv_error('We could not save this address. Please try again.', 500, 'save_failed');
        }
        okv_json(['status' => 'ok', 'message' => 'Address saved.', 'id' => $id], 201);
        break;
    }

    case 'update_address': {
        acc_guard_write();
        $uid = (int) Customer::id();
        $id  = (int) okv_input('address_id', 0);

        $owned = Database::one('SELECT id FROM customer_addresses WHERE id = :id AND user_id = :u', [':id' => $id, ':u' => $uid]);
        if (!$owned) {
            okv_error('That address was not found.', 404, 'not_found');
        }
        $a       = acc_read_address();
        $default = acc_truthy(okv_input('is_default', ''));

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            if ($default) {
                $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE user_id = :u')->execute([':u' => $uid]);
            }
            $pdo->prepare(
                'UPDATE customer_addresses
                    SET label = :label, recipient_name = :rn, recipient_phone = :rp, address_line_1 = :l1,
                        address_line_2 = :l2, city = :city, state = :state, landmark = :lm'
                    . ($default ? ', is_default = 1' : '') .
                  ' WHERE id = :id AND user_id = :u'
            )->execute([
                ':label' => $a['label'], ':rn' => $a['recipient_name'], ':rp' => $a['recipient_phone'],
                ':l1' => $a['address_line_1'], ':l2' => $a['address_line_2'], ':city' => $a['city'],
                ':state' => $a['state'], ':lm' => $a['landmark'], ':id' => $id, ':u' => $uid,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('account.update_address failed: ' . $e->getMessage());
            okv_error('We could not save this address. Please try again.', 500, 'save_failed');
        }
        okv_json(['status' => 'ok', 'message' => 'Address updated.']);
        break;
    }

    case 'delete_address': {
        acc_guard_write();
        $uid = (int) Customer::id();
        $id  = (int) okv_input('address_id', 0);

        $row = Database::one('SELECT id, is_default FROM customer_addresses WHERE id = :id AND user_id = :u', [':id' => $id, ':u' => $uid]);
        if (!$row) {
            okv_error('That address was not found.', 404, 'not_found');
        }
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM customer_addresses WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $uid]);
            // If we removed the default, promote the newest remaining address.
            if ((int) $row['is_default'] === 1) {
                $next = Database::one('SELECT id FROM customer_addresses WHERE user_id = :u ORDER BY id DESC LIMIT 1', [':u' => $uid]);
                if ($next) {
                    $pdo->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = :id AND user_id = :u')->execute([':id' => (int) $next['id'], ':u' => $uid]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('account.delete_address failed: ' . $e->getMessage());
            okv_error('We could not remove this address. Please try again.', 500, 'delete_failed');
        }
        okv_json(['status' => 'ok', 'message' => 'Address removed.']);
        break;
    }

    case 'set_default_address': {
        acc_guard_write();
        $uid = (int) Customer::id();
        $id  = (int) okv_input('address_id', 0);

        $owned = Database::one('SELECT id FROM customer_addresses WHERE id = :id AND user_id = :u', [':id' => $id, ':u' => $uid]);
        if (!$owned) {
            okv_error('That address was not found.', 404, 'not_found');
        }
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE user_id = :u')->execute([':u' => $uid]);
            $pdo->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $uid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('account.set_default_address failed: ' . $e->getMessage());
            okv_error('We could not update your default address. Please try again.', 500, 'save_failed');
        }
        okv_json(['status' => 'ok', 'message' => 'Default delivery address set.']);
        break;
    }

    case 'update_profile': {
        acc_guard_write();
        $uid   = (int) Customer::id();
        $first = trim((string) okv_input('first_name', ''));
        $last  = trim((string) okv_input('last_name', ''));
        $phone = Phone::normalize((string) okv_input('phone', ''));

        if ($first === '' || $last === '') {
            okv_error('Enter your first and last name.', 422, 'missing_name');
        }
        if ($phone === null) {
            okv_error('Enter a valid phone number, for example 0803 000 0000.', 422, 'bad_phone');
        }
        if (Database::one('SELECT id FROM users WHERE phone = :p AND id <> :id LIMIT 1', [':p' => $phone, ':id' => $uid])) {
            okv_error('That phone number is already in use on another account.', 409, 'phone_taken');
        }

        Database::run(
            'UPDATE users SET first_name = :fn, last_name = :ln, phone = :ph WHERE id = :id',
            [':fn' => $first, ':ln' => $last, ':ph' => $phone, ':id' => $uid]
        );
        $_SESSION['first_name'] = $first;

        okv_json(['status' => 'ok', 'message' => 'Your details are saved.', 'first_name' => $first]);
        break;
    }

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}

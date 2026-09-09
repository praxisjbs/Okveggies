<?php
/**
 * includes/classes/StaffCustomers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Finding a customer, and making one, from the back office.
 *
 * Two screens need this and neither of them is the Customers module: a colleague
 * taking an order over the phone (admin/order_new.php) and a colleague typing in
 * a list that arrived on WhatsApp (admin/kitchen_runs.php). Both start the same
 * way, with a name or a number said out loud, and both hit the same wall when
 * the caller has never bought from us before. So the search and the light
 * account live here once rather than twice.
 *
 * Three things are worth knowing.
 *
 *   A staff-made account has no usable password. The customer never chose one,
 *   so we store a hash of random bytes nobody holds. They set a real password
 *   through the ordinary reset-by-email flow whenever they first want to sign
 *   in, and until then the account exists only so their order has an owner, a
 *   trail link and a history.
 *
 *   The phone number is the identity that matters. People ring in and give a
 *   number, not an email address, so the search normalises what was typed and
 *   matches on the stored form. Phone::normalize is the same one checkout uses,
 *   so 0803..., +234803... and 234803... all find the same person.
 *
 *   An email is still required, because the order trail, the confirmation and
 *   every later notification travel by email (PRD Section 15) and the column is
 *   NOT NULL UNIQUE. A caller who has no email gets a placeholder built from
 *   their phone number, which is unique for the same reason the phone is, and
 *   which is visibly a placeholder rather than a plausible address.
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/StaffCustomersTest.php.
 * -----------------------------------------------------------------------------
 */

final class StaffCustomers
{
    /** Longest search term we act on. Anything more is a paste, not a search. */
    public const SEARCH_MAX = 100;

    /** The domain a placeholder email is built under. Never deliverable. */
    public const PLACEHOLDER_DOMAIN = 'no-email.okveggies.invalid';

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    /** Trim a search term to something worth running. Empty means do not search. */
    public static function cleanSearch(string $term): string
    {
        return mb_substr(trim($term), 0, self::SEARCH_MAX);
    }

    /**
     * A placeholder address for a caller with no email, derived from the phone
     * number so it is as unique as the phone is. The local part keeps only
     * digits, so a normalised +2348031234567 becomes 2348031234567@... and the
     * domain says plainly that nothing will ever be delivered to it.
     */
    public static function placeholderEmail(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        return ($digits === '' ? 'customer' : $digits) . '@' . self::PLACEHOLDER_DOMAIN;
    }

    /** True when an address is one of ours rather than one the customer gave. */
    public static function isPlaceholderEmail(string $email): bool
    {
        return str_ends_with(strtolower(trim($email)), '@' . self::PLACEHOLDER_DOMAIN);
    }

    /**
     * Check what a colleague typed into the new-customer form, and hand back the
     * clean version. Throws DomainException with a code the controller turns
     * into a sentence, the way every other write path in this codebase does.
     */
    public static function validateNew(array $input): array
    {
        $first = trim((string) ($input['first_name'] ?? ''));
        $last  = trim((string) ($input['last_name'] ?? ''));
        $type  = (string) ($input['customer_type'] ?? 'household');
        $email = trim((string) ($input['email'] ?? ''));

        if ($first === '' || $last === '') {
            throw new DomainException('name_required');
        }
        if (!in_array($type, Customer::TYPES, true)) {
            throw new DomainException('bad_customer_type');
        }

        $phone = Phone::normalize((string) ($input['phone'] ?? ''));
        if ($phone === null) {
            throw new DomainException('bad_phone');
        }

        // No email is a normal answer on a phone call, so it is allowed and
        // filled in. A typed address that is not an address is a mistake, and
        // saying so beats silently replacing it with a placeholder.
        if ($email === '') {
            $email = self::placeholderEmail($phone);
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('bad_email');
        }

        return [
            'first_name'    => mb_substr($first, 0, 100),
            'last_name'     => mb_substr($last, 0, 100),
            'email'         => mb_substr($email, 0, 255),
            'phone'         => $phone,
            'customer_type' => $type,
            'business_name' => mb_substr(trim((string) ($input['business_name'] ?? '')), 0, 200),
        ];
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Customers matching what a colleague typed: the account name, the email,
     * or the phone number in whatever shape it was said. Staff accounts are
     * never returned, because an order belongs to a buyer.
     *
     * One named placeholder per position. The connection runs native prepared
     * statements, and MySQL refuses the same named placeholder twice in one
     * statement, which is the defect that took the orders screen down once
     * already (see admin/orders.php).
     */
    public static function search(string $term, int $limit = 20): array
    {
        $term = self::cleanSearch($term);
        if ($term === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $like  = '%' . Catalogue::escapeLike($term) . '%';

        // A number typed as 0803... is stored as +234803..., so search both the
        // raw string and the normalised one. Normalize returns null for
        // anything that is not a phone number, and then only the raw match runs.
        $normalised = Phone::normalize($term);

        return Database::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.user_type,
                    u.email_verified_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count
               FROM users u
              WHERE u.user_type IN (\'household\', \'business\')
                AND u.status = \'active\'
                AND (
                     TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) LIKE :name
                     OR u.email LIKE :email
                     OR u.phone LIKE :phone_raw
                     OR u.phone = :phone_exact
                )
              ORDER BY u.first_name, u.last_name
              LIMIT ' . $limit,
            [
                ':name'        => $like,
                ':email'       => $like,
                ':phone_raw'   => $like,
                ':phone_exact' => $normalised ?? '',
            ]
        );
    }

    /** One customer, by id. Null for a staff account or an id that is not there. */
    public static function find(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        return Database::one(
            'SELECT id, first_name, last_name, email, phone, user_type, email_verified_at
               FROM users
              WHERE id = :id AND status = \'active\' AND user_type IN (\'household\', \'business\')',
            [':id' => $userId]
        );
    }

    /** The address we last delivered to for this customer, to prefill the form. */
    public static function lastAddress(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        return Database::one(
            'SELECT recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark
               FROM customer_addresses
              WHERE user_id = :id
              ORDER BY is_default DESC, id DESC
              LIMIT 1',
            [':id' => $userId]
        );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Make the light account a phone order needs. The password hash is of bytes
     * nobody has, so the account cannot be signed into until the customer sets
     * a password through the ordinary reset flow.
     *
     * Runs in the caller's transaction when there is one, so a customer is
     * never left behind by an order that failed to write.
     *
     * @return int the new user id
     */
    public static function create(array $clean, int $staffId): int
    {
        $existing = Database::one(
            'SELECT id, email, phone FROM users WHERE email = :email OR phone = :phone LIMIT 1',
            [':email' => $clean['email'], ':phone' => $clean['phone']]
        );
        if ($existing) {
            throw new DomainException('customer_exists');
        }

        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first, :last, :email, :phone, :hash, :type, \'active\')',
            [
                ':first' => $clean['first_name'],
                ':last'  => $clean['last_name'],
                ':email' => $clean['email'],
                ':phone' => $clean['phone'],
                ':hash'  => Password::hash(bin2hex(random_bytes(32))),
                ':type'  => $clean['customer_type'],
            ]
        );
        $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

        if ($clean['customer_type'] === 'business') {
            // A business account without a profile row has nowhere to hang a
            // credit application, and the Pro Portal reads the row. The trading
            // name starts as whatever the caller gave, falling back to the
            // person's own name, and the Customers module edits it later. Same
            // shape as the self-serve registration in api/v1/auth.php.
            $businessName = trim((string) ($clean['business_name'] ?? ''));
            if ($businessName === '') {
                $businessName = trim($clean['first_name'] . ' ' . $clean['last_name']);
            }
            Database::run(
                'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status)
                 VALUES (:user, :name, :contact, 0, \'not_requested\')',
                [
                    ':user'    => $userId,
                    ':name'    => mb_substr($businessName, 0, 200),
                    ':contact' => mb_substr(trim($clean['first_name'] . ' ' . $clean['last_name']), 0, 150),
                ]
            );
        }

        Audit::record(
            'customer.create',
            'users',
            $userId,
            null,
            [
                'created_by_staff' => true,
                'customer_type'    => $clean['customer_type'],
                'email'            => $clean['email'],
                'placeholder_email' => self::isPlaceholderEmail($clean['email']),
            ],
            $staffId
        );

        return $userId;
    }

    /** Plain copy for each refusal code this class throws. */
    public static function message(string $code): string
    {
        $messages = [
            'name_required'     => 'Enter the first and last name.',
            'bad_customer_type' => 'Choose whether this is a household or a business.',
            'bad_phone'         => 'Enter a valid Nigerian phone number.',
            'bad_email'         => 'That email address does not look right. Leave it blank if they did not give one.',
            'customer_exists'   => 'That phone number or email already belongs to a customer. Search for them instead.',
        ];
        return $messages[$code] ?? 'We could not save that customer. Check the details and try again.';
    }
}

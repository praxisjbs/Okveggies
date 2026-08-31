<?php
/** Basket lines, price snapshots, quantity rules and guest-cart merge. */
final class Basket
{
    private const SESSION_TOKEN_KEY = 'okv_basket_token';
    public const MAX_COMBO_QUANTITY = 99;

    public static function count(): int { return (int) self::state()['count']; }

    /** The active basket, ready for the page, API and shared mini-cart. */
    public static function state(): array
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) {
            return ['cart_id' => null, 'lines' => [], 'count' => 0, 'subtotal_subunit' => 0];
        }
        $lines = Database::all(
            'SELECT ci.id, ci.item_type, ci.product_id, ci.combo_package_id, ci.quantity, ci.unit_price_subunit,
                    p.name AS product_name, p.slug AS product_slug, u.symbol AS product_unit,
                    c.name AS combo_name, c.slug AS combo_slug
               FROM cart_items ci
               LEFT JOIN products p ON p.id = ci.product_id
               LEFT JOIN units_of_measurement u ON u.id = p.unit_id
               LEFT JOIN combo_packages c ON c.id = ci.combo_package_id
              WHERE ci.cart_id = :cart_id
              ORDER BY ci.created_at, ci.id',
            [':cart_id' => $cartId]
        );
        foreach ($lines as &$line) {
            $line['id'] = (int) $line['id'];
            $line['product_id'] = $line['product_id'] === null ? null : (int) $line['product_id'];
            $line['combo_package_id'] = $line['combo_package_id'] === null ? null : (int) $line['combo_package_id'];
            $line['unit_price_subunit'] = (int) $line['unit_price_subunit'];
            $line['line_total_subunit'] = Money::lineTotal($line['quantity'], $line['unit_price_subunit']);
            $line['name'] = $line['item_type'] === 'combo' ? (string) $line['combo_name'] : (string) $line['product_name'];
            $line['slug'] = $line['item_type'] === 'combo' ? (string) $line['combo_slug'] : (string) $line['product_slug'];
            $line['unit'] = $line['item_type'] === 'combo' ? 'basket' : (string) $line['product_unit'];
        }
        unset($line);
        return ['cart_id' => $cartId, 'lines' => $lines, 'count' => count($lines), 'subtotal_subunit' => self::subtotalFromLines($lines)];
    }

    public static function subtotalFromLines(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += Money::lineTotal($line['quantity'] ?? 0, (int) ($line['unit_price_subunit'] ?? 0));
        }
        return $total;
    }

    public static function addProduct(int $productId): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $product = Database::one(
                'SELECT p.id, p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                        COALESCE(pa.availability_status, \'available\') AS availability_status
                   FROM products p LEFT JOIN product_availability pa ON pa.product_id = p.id
                  WHERE p.id = :id AND p.is_active = 1 FOR UPDATE',
                [':id' => $productId]
            );
            if (!$product) { throw new DomainException('not_found'); }
            if ($product['availability_status'] !== 'available') { throw new DomainException('unavailable'); }
            $result = self::addSnapshot(self::activeCartId(), 'product', $productId, (int) $product['current_price_subunit'], (string) $product['minimum_quantity'], (string) $product['quantity_increment']);
            $pdo->commit();
            return $result + ['count' => self::count()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function addCombo(int $comboId): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $combo = Database::one('SELECT id, price_subunit, is_active, available_from, available_until FROM combo_packages WHERE id = :id FOR UPDATE', [':id' => $comboId]);
            if (!$combo) { throw new DomainException('not_found'); }
            if (!Combos::isBuyableNow($combo) || (int) $combo['price_subunit'] < Pricing::MIN_PRICE_SUBUNIT) { throw new DomainException('unavailable'); }
            $result = self::addSnapshot(self::activeCartId(), 'combo', $comboId, (int) $combo['price_subunit'], '1.000', '1.000');
            $pdo->commit();
            return $result + ['count' => self::count()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function updateProduct(int $lineId, string $quantity): void { self::updateLine($lineId, $quantity, 'product'); }
    public static function updateCombo(int $lineId, string $quantity): void { self::updateLine($lineId, $quantity, 'combo'); }
    public static function removeProduct(int $lineId): void { self::removeLine($lineId, 'product'); }
    public static function removeCombo(int $lineId): void { self::removeLine($lineId, 'combo'); }

    /** Merge this browser's guest cart into a just-signed-in customer's cart. */
    public static function mergeGuestIntoAccount(int $userId): void
    {
        $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        if (!is_string($token) || $token === '') { return; }
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $guest = Database::one('SELECT id FROM shopping_carts WHERE session_token = :token AND status = \'active\' FOR UPDATE', [':token' => $token]);
            if (!$guest) {
                $pdo->commit();
                unset($_SESSION[self::SESSION_TOKEN_KEY]);
                return;
            }
            $target = Database::one('SELECT id FROM shopping_carts WHERE user_id = :user_id AND status = \'active\' ORDER BY id DESC LIMIT 1 FOR UPDATE', [':user_id' => $userId]);
            if (!$target) {
                Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:user_id, \'active\')', [':user_id' => $userId]);
                $target = ['id' => (int) $pdo->lastInsertId()];
            }
            $guestLines = Database::all('SELECT id, item_type, product_id, combo_package_id, quantity, unit_price_subunit FROM cart_items WHERE cart_id = :cart_id FOR UPDATE', [':cart_id' => (int) $guest['id']]);
            foreach ($guestLines as $line) {
                $match = self::snapshotLine((int) $target['id'], (string) $line['item_type'], (int) ($line['product_id'] ?? $line['combo_package_id']), (int) $line['unit_price_subunit']);
                if ($match) {
                    Database::run('UPDATE cart_items SET quantity = :quantity WHERE id = :id', [':quantity' => self::addQuantities((string) $match['quantity'], (string) $line['quantity']), ':id' => (int) $match['id']]);
                    Database::run('DELETE FROM cart_items WHERE id = :id', [':id' => (int) $line['id']]);
                } else {
                    Database::run('UPDATE cart_items SET cart_id = :target WHERE id = :id', [':target' => (int) $target['id'], ':id' => (int) $line['id']]);
                }
            }
            Database::run('UPDATE shopping_carts SET status = \'merged\' WHERE id = :id', [':id' => (int) $guest['id']]);
            $pdo->commit();
            unset($_SESSION[self::SESSION_TOKEN_KEY]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function isValidProductQuantity(string $quantity, string $minimum, string $increment): bool
    {
        $value = self::quantityMilli($quantity);
        $min = self::quantityMilli($minimum);
        $step = self::quantityMilli($increment);
        return $value !== null && $min !== null && $step !== null && $value >= $min && $step > 0 && (($value - $min) % $step === 0);
    }

    public static function isValidComboQuantity(string $quantity): bool
    {
        $value = self::quantityMilli($quantity);
        return $value !== null && $value >= 1000 && $value <= self::MAX_COMBO_QUANTITY * 1000 && $value % 1000 === 0;
    }

    public static function productUpdateAction(string $quantity, string $minimum, string $increment): string
    {
        $value = self::quantityMilli($quantity);
        if ($value === 0) { return 'remove'; }
        return self::isValidProductQuantity($quantity, $minimum, $increment) ? 'update' : 'invalid';
    }

    public static function comboUpdateAction(string $quantity): string
    {
        $value = self::quantityMilli($quantity);
        if ($value === 0) { return 'remove'; }
        return self::isValidComboQuantity($quantity) ? 'update' : 'invalid';
    }

    /** Pure merge used by tests and mirrors the transactional database merge. */
    public static function mergeSnapshotLines(array $accountLines, array $guestLines): array
    {
        $merged = array_values($accountLines);
        foreach ($guestLines as $line) {
            $found = null;
            foreach ($merged as $index => $candidate) {
                if (self::sameSnapshot($candidate, $line)) { $found = $index; break; }
            }
            if ($found === null) {
                $merged[] = $line;
            } else {
                $merged[$found]['quantity'] = self::addQuantities((string) $merged[$found]['quantity'], (string) $line['quantity']);
            }
        }
        return $merged;
    }

    public static function repeatAddPlan(?array $existing, int $currentPrice, string $addQuantity): array
    {
        if ($existing && (int) $existing['unit_price_subunit'] === $currentPrice) {
            return ['operation' => 'increment', 'quantity' => self::addQuantities((string) $existing['quantity'], $addQuantity), 'unit_price_subunit' => $currentPrice, 'repriced' => false];
        }
        return ['operation' => 'append', 'quantity' => self::normaliseQuantity($addQuantity), 'unit_price_subunit' => $currentPrice, 'repriced' => $existing !== null];
    }

    private static function addSnapshot(int $cartId, string $type, int $itemId, int $price, string $initialQuantity, string $increment): array
    {
        $same = self::snapshotLine($cartId, $type, $itemId, $price);
        $any = Database::one('SELECT id, quantity, unit_price_subunit FROM cart_items WHERE cart_id = :cart_id AND item_type = :type AND ' . ($type === 'product' ? 'product_id' : 'combo_package_id') . ' = :item_id ORDER BY id LIMIT 1 FOR UPDATE', [':cart_id' => $cartId, ':type' => $type, ':item_id' => $itemId]);
        $plan = self::repeatAddPlan($same ?: $any, $price, $same ? $increment : $initialQuantity);
        if ($same) {
            Database::run('UPDATE cart_items SET quantity = :quantity WHERE id = :id', [':quantity' => $plan['quantity'], ':id' => (int) $same['id']]);
        } else {
            $column = $type === 'product' ? 'product_id' : 'combo_package_id';
            Database::run('INSERT INTO cart_items (cart_id, item_type, ' . $column . ', quantity, unit_price_subunit) VALUES (:cart_id, :type, :item_id, :quantity, :price)', [':cart_id' => $cartId, ':type' => $type, ':item_id' => $itemId, ':quantity' => $plan['quantity'], ':price' => $price]);
        }
        return ['quantity_added' => $plan['quantity'], 'repriced' => $plan['repriced'], 'new_price_subunit' => $price];
    }

    private static function updateLine(int $lineId, string $quantity, string $type): void
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) { throw new DomainException('not_found'); }
        $line = Database::one('SELECT ci.id, p.minimum_quantity, p.quantity_increment FROM cart_items ci LEFT JOIN products p ON p.id = ci.product_id WHERE ci.id = :id AND ci.cart_id = :cart_id AND ci.item_type = :type FOR UPDATE', [':id' => $lineId, ':cart_id' => $cartId, ':type' => $type]);
        if (!$line) { throw new DomainException('not_found'); }
        $action = $type === 'product' ? self::productUpdateAction($quantity, (string) $line['minimum_quantity'], (string) $line['quantity_increment']) : self::comboUpdateAction($quantity);
        if ($action === 'remove') { self::removeLine($lineId, $type, $cartId); return; }
        if ($action !== 'update') { throw new DomainException('invalid_quantity'); }
        Database::run('UPDATE cart_items SET quantity = :quantity WHERE id = :id', [':quantity' => self::normaliseQuantity($quantity), ':id' => $lineId]);
    }

    private static function removeLine(int $lineId, string $type, ?int $cartId = null): void
    {
        $cartId = $cartId ?? self::findActiveCartId();
        if ($cartId === null) { throw new DomainException('not_found'); }
        $changed = Database::run('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart_id AND item_type = :type', [':id' => $lineId, ':cart_id' => $cartId, ':type' => $type]);
        if ($changed === 0) { throw new DomainException('not_found'); }
    }

    private static function snapshotLine(int $cartId, string $type, int $itemId, int $price): ?array
    {
        return Database::one('SELECT id, quantity, unit_price_subunit FROM cart_items WHERE cart_id = :cart_id AND item_type = :type AND ' . ($type === 'product' ? 'product_id' : 'combo_package_id') . ' = :item_id AND unit_price_subunit = :price ORDER BY id LIMIT 1 FOR UPDATE', [':cart_id' => $cartId, ':type' => $type, ':item_id' => $itemId, ':price' => $price]);
    }

    private static function sameSnapshot(array $left, array $right): bool
    {
        return ($left['item_type'] ?? '') === ($right['item_type'] ?? '')
            && (int) ($left['product_id'] ?? 0) === (int) ($right['product_id'] ?? 0)
            && (int) ($left['combo_package_id'] ?? 0) === (int) ($right['combo_package_id'] ?? 0)
            && (int) ($left['unit_price_subunit'] ?? 0) === (int) ($right['unit_price_subunit'] ?? 0);
    }

    private static function activeCartId(): int
    {
        $existing = self::findActiveCartId();
        if ($existing !== null) { return $existing; }
        $userId = Customer::id();
        if ($userId !== null) {
            Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:user_id, \'active\')', [':user_id' => $userId]);
        } else {
            Database::run('INSERT INTO shopping_carts (session_token, status) VALUES (:token, \'active\')', [':token' => self::guestToken()]);
        }
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private static function findActiveCartId(): ?int
    {
        $userId = Customer::id();
        if ($userId !== null) {
            $row = Database::one('SELECT id FROM shopping_carts WHERE user_id = :user_id AND status = \'active\' ORDER BY id DESC LIMIT 1', [':user_id' => $userId]);
        } else {
            $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
            if (!is_string($token) || $token === '') { return null; }
            $row = Database::one('SELECT id FROM shopping_carts WHERE session_token = :token AND status = \'active\' ORDER BY id DESC LIMIT 1', [':token' => $token]);
        }
        return $row ? (int) $row['id'] : null;
    }

    private static function guestToken(): string
    {
        $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_TOKEN_KEY] = $token;
        }
        return $token;
    }

    private static function quantityMilli(string $quantity): ?int
    {
        $quantity = trim($quantity);
        if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,3}))?$/', $quantity, $match)) { return null; }
        return ((int) $match[1]) * 1000 + (int) str_pad($match[2] ?? '', 3, '0');
    }

    private static function normaliseQuantity(string $quantity): string
    {
        $milli = self::quantityMilli($quantity);
        if ($milli === null) { throw new InvalidArgumentException('invalid_quantity'); }
        return self::milliToQuantity($milli);
    }

    private static function addQuantities(string $left, string $right): string
    {
        $leftMilli = self::quantityMilli($left);
        $rightMilli = self::quantityMilli($right);
        if ($leftMilli === null || $rightMilli === null) { throw new InvalidArgumentException('invalid_quantity'); }
        return self::milliToQuantity($leftMilli + $rightMilli);
    }

    private static function milliToQuantity(int $milli): string
    {
        return intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }
}

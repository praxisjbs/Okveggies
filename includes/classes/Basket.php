<?php
/**
 * Minimal product basket seam for catalogue add controls.
 * Basket display, quantity editing, merge-on-login and checkout remain in M4.
 */
final class Basket
{
    private const SESSION_TOKEN_KEY = 'okv_basket_token';

    /**
     * How many lines are in the basket, which is what the badge and its label
     * say. Never the sum of the quantities: those are kilogrammes, bunches and
     * heads, so adding them together counts 2kg of tomatoes as two items.
     */
    public static function count(): int
    {
        $cartId = self::findActiveCartId();
        if ($cartId === null) {
            return 0;
        }
        $row = Database::one(
            'SELECT COUNT(*) AS line_count FROM cart_items WHERE cart_id = :cart_id',
            [':cart_id' => $cartId]
        );
        return (int) ($row['line_count'] ?? 0);
    }

    public static function addProduct(int $productId): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $product = Database::one(
                'SELECT p.id, p.current_price_subunit, p.minimum_quantity, p.quantity_increment,
                        COALESCE(pa.availability_status, \'available\') AS availability_status
                   FROM products p
                   LEFT JOIN product_availability pa ON pa.product_id = p.id
                  WHERE p.id = :product_id AND p.is_active = 1
                  FOR UPDATE',
                [':product_id' => $productId]
            );
            if (!$product) {
                throw new DomainException('not_found');
            }
            if ($product['availability_status'] !== 'available') {
                throw new DomainException('unavailable');
            }

            $cartId = self::activeCartId();
            $item = Database::one(
                'SELECT id, quantity FROM cart_items
                  WHERE cart_id = :cart_id AND item_type = \'product\' AND product_id = :product_id
                  ORDER BY id LIMIT 1 FOR UPDATE',
                [':cart_id' => $cartId, ':product_id' => $productId]
            );

            if ($item) {
                $quantity = (float) $item['quantity'] + (float) $product['quantity_increment'];
                Database::run(
                    'UPDATE cart_items SET quantity = :quantity, unit_price_subunit = :price WHERE id = :id',
                    [':quantity' => $quantity, ':price' => (int) $product['current_price_subunit'], ':id' => (int) $item['id']]
                );
            } else {
                $quantity = (float) $product['minimum_quantity'];
                Database::run(
                    'INSERT INTO cart_items (cart_id, item_type, product_id, quantity, unit_price_subunit)
                     VALUES (:cart_id, \'product\', :product_id, :quantity, :price)',
                    [
                        ':cart_id' => $cartId,
                        ':product_id' => $productId,
                        ':quantity' => $quantity,
                        ':price' => (int) $product['current_price_subunit'],
                    ]
                );
            }

            $pdo->commit();
            return ['quantity_added' => $quantity, 'count' => self::count()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add one combo to the basket. Mirrors addProduct. A combo added for the
     * first time is one cart_items row with item_type = 'combo', quantity 1 and
     * the combo's current price_subunit as the unit price. A combo already in
     * the basket increments the same row's quantity by 1, so a household that
     * wants two Stew Combos ends up with one line reading quantity 2 rather
     * than two identical rows. Fanning the combo out into order_item_components
     * is M4/M5, not this seam.
     *
     * Refuses when the combo is not buyable now: is_active off, or the
     * availability window has closed since the page loaded, or it was
     * unpublished between the page render and the click. Combos::isBuyableNow
     * catches all three from the row we already fetched.
     */
    public static function addCombo(int $comboId): array
    {
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $combo = Database::one(
                'SELECT id, price_subunit, is_active, available_from, available_until
                   FROM combo_packages
                  WHERE id = :combo_id
                  FOR UPDATE',
                [':combo_id' => $comboId]
            );
            if (!$combo) {
                throw new DomainException('not_found');
            }
            if (!Combos::isBuyableNow($combo)) {
                throw new DomainException('unavailable');
            }
            if ((int) $combo['price_subunit'] < 1) {
                throw new DomainException('unavailable');
            }

            $cartId = self::activeCartId();
            $item = Database::one(
                'SELECT id, quantity FROM cart_items
                  WHERE cart_id = :cart_id AND item_type = \'combo\' AND combo_package_id = :combo_id
                  ORDER BY id LIMIT 1 FOR UPDATE',
                [':cart_id' => $cartId, ':combo_id' => $comboId]
            );

            if ($item) {
                $quantity = (float) $item['quantity'] + 1.0;
                Database::run(
                    'UPDATE cart_items SET quantity = :quantity, unit_price_subunit = :price WHERE id = :id',
                    [':quantity' => $quantity, ':price' => (int) $combo['price_subunit'], ':id' => (int) $item['id']]
                );
            } else {
                $quantity = 1.0;
                Database::run(
                    'INSERT INTO cart_items (cart_id, item_type, combo_package_id, quantity, unit_price_subunit)
                     VALUES (:cart_id, \'combo\', :combo_id, :quantity, :price)',
                    [
                        ':cart_id' => $cartId,
                        ':combo_id' => $comboId,
                        ':quantity' => $quantity,
                        ':price' => (int) $combo['price_subunit'],
                    ]
                );
            }

            $pdo->commit();
            return ['quantity_added' => $quantity, 'count' => self::count()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function activeCartId(): int
    {
        $existing = self::findActiveCartId();
        if ($existing !== null) {
            return $existing;
        }

        $userId = Customer::id();
        if ($userId !== null) {
            Database::run(
                'INSERT INTO shopping_carts (user_id, status) VALUES (:user_id, \'active\')',
                [':user_id' => $userId]
            );
        } else {
            $token = self::guestToken();
            Database::run(
                'INSERT INTO shopping_carts (session_token, status) VALUES (:token, \'active\')',
                [':token' => $token]
            );
        }
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private static function findActiveCartId(): ?int
    {
        $userId = Customer::id();
        if ($userId !== null) {
            $row = Database::one(
                'SELECT id FROM shopping_carts WHERE user_id = :user_id AND status = \'active\' ORDER BY id DESC LIMIT 1',
                [':user_id' => $userId]
            );
        } else {
            $token = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
            if (!is_string($token) || $token === '') {
                return null;
            }
            $row = Database::one(
                'SELECT id FROM shopping_carts WHERE session_token = :token AND status = \'active\' ORDER BY id DESC LIMIT 1',
                [':token' => $token]
            );
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
}

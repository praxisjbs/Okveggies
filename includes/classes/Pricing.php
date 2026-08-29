<?php
/**
 * includes/classes/Pricing.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Weekly repricing. This is the one place a product price is allowed
 * to change, so the history can never be bypassed. See docs/PRD.md Section 6.
 *
 * Every change does three things inside one transaction:
 *   1. closes the open history row (stamps effective_to)
 *   2. writes a new history row (old price, new price, reason, who, when)
 *   3. updates products.current_price_subunit
 *
 * If any of those fail, none of them happened. History is append-only: a row is
 * written and later closed, never edited away and never deleted.
 *
 * Money is integer subunits (kobo) throughout. Nothing here takes a float price.
 * -----------------------------------------------------------------------------
 */
final class Pricing
{
    /** A sanity ceiling, ₦10,000,000 per unit. Anything above this is a typo. */
    public const MAX_PRICE_SUBUNIT = 1000000000;

    /** The lowest a price may be set to. A product is never free. */
    public const MIN_PRICE_SUBUNIT = 1;

    public const MODE_PERCENT = 'percent';
    public const MODE_FLAT    = 'flat';

    /**
     * Round subunits to the nearest whole naira. Bulk moves use this: every
     * price in the catalogue is whole naira, and a shelf price of ₦2,970.63
     * from a 10% move is noise, not precision.
     */
    public static function roundToNaira(int $subunit): int
    {
        return (int) (round($subunit / Money::SUBUNITS) * Money::SUBUNITS);
    }

    /**
     * The new price for one product under a bulk adjustment.
     *
     * MODE_PERCENT: $amount is a percentage, so 10 raises by a tenth and -5
     * lowers by a twentieth. MODE_FLAT: $amount is subunits to add, so it may
     * be negative. Both round to whole naira.
     */
    public static function adjust(int $currentSubunit, string $mode, float $amount): int
    {
        if ($mode === self::MODE_PERCENT) {
            $next = (int) round($currentSubunit * (100 + $amount) / 100);
        } elseif ($mode === self::MODE_FLAT) {
            $next = $currentSubunit + (int) round($amount);
        } else {
            throw new InvalidArgumentException('unknown_mode');
        }
        return self::roundToNaira($next);
    }

    /** True when a price is one we are willing to store. */
    public static function isValidPrice(int $subunit): bool
    {
        return $subunit >= self::MIN_PRICE_SUBUNIT && $subunit <= self::MAX_PRICE_SUBUNIT;
    }

    /**
     * Change one product's price and write its history.
     *
     * Returns ['changed' => bool, 'old' => int, 'new' => int]. Setting the price
     * it already has is not an error and writes no history row, so re-importing
     * last week's sheet does not fill the history with noise.
     *
     * Throws DomainException('not_found'), DomainException('invalid_price').
     * Callers that already hold a transaction pass $ownTransaction = false.
     */
    public static function change(
        int $productId,
        int $newPriceSubunit,
        ?string $reason = null,
        ?int $userId = null,
        bool $ownTransaction = true
    ): array {
        if (!self::isValidPrice($newPriceSubunit)) {
            throw new DomainException('invalid_price');
        }

        $pdo = Database::getInstance()->getConnection();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $product = Database::one(
                'SELECT id, current_price_subunit FROM products WHERE id = :id FOR UPDATE',
                [':id' => $productId]
            );
            if (!$product) {
                throw new DomainException('not_found');
            }

            $old = (int) $product['current_price_subunit'];
            if ($old === $newPriceSubunit) {
                if ($ownTransaction) {
                    $pdo->commit();
                }
                return ['changed' => false, 'old' => $old, 'new' => $old];
            }

            self::writeHistory($productId, $old, $newPriceSubunit, $reason, $userId);

            Database::run(
                'UPDATE products SET current_price_subunit = :price WHERE id = :id',
                [':price' => $newPriceSubunit, ':id' => $productId]
            );

            if ($ownTransaction) {
                $pdo->commit();
            }
            return ['changed' => true, 'old' => $old, 'new' => $newPriceSubunit];
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Close the open history row and open a new one. An opening price (a product
     * priced for the first time) passes $oldSubunit as null, which is what the
     * nullable old_price_subunit column is for.
     */
    public static function writeHistory(
        int $productId,
        ?int $oldSubunit,
        int $newSubunit,
        ?string $reason,
        ?int $userId
    ): void {
        Database::run(
            'UPDATE product_price_history
                SET effective_to = NOW()
              WHERE product_id = :product_id AND effective_to IS NULL',
            [':product_id' => $productId]
        );

        $reason = $reason === null ? null : mb_substr(trim($reason), 0, 255);

        Database::run(
            'INSERT INTO product_price_history
                (product_id, old_price_subunit, new_price_subunit, currency, effective_from, change_reason, changed_by)
             VALUES (:product_id, :old_price, :new_price, :currency, NOW(), :reason, :changed_by)',
            [
                ':product_id' => $productId,
                ':old_price'  => $oldSubunit,
                ':new_price'  => $newSubunit,
                ':currency'   => Money::CODE,
                ':reason'     => ($reason === '' ? null : $reason),
                ':changed_by' => $userId,
            ]
        );
    }

    /**
     * What a bulk adjustment would do, without doing it. The pricing screen shows
     * this before anything is written, so nobody reprices 17 items by accident.
     *
     * Returns ['rows' => [...], 'skipped' => [...], 'blocked' => [...]].
     *
     * skipped holds products that carry no price yet. There is nothing to adjust
     * on a draft, so it is passed over rather than treated as a failure: one
     * unpriced product in a category must not stop the weekly reprice.
     *
     * blocked holds products the move would push outside the allowed range, and
     * a single blocked row stops the whole apply: a bulk move is all or nothing.
     */
    public static function previewBulk(int $categoryId, string $mode, float $amount): array
    {
        $products = Database::all(
            'SELECT p.id, p.name, p.sku, p.current_price_subunit, u.symbol AS unit
               FROM products p
               JOIN units_of_measurement u ON u.id = p.unit_id
              WHERE p.category_id = :category_id AND p.is_active = 1
              ORDER BY p.name',
            [':category_id' => $categoryId]
        );

        $rows = [];
        $skipped = [];
        $blocked = [];
        foreach ($products as $product) {
            $current = (int) $product['current_price_subunit'];
            $row = [
                'id'   => (int) $product['id'],
                'name' => $product['name'],
                'sku'  => $product['sku'],
                'unit' => $product['unit'],
                'old'  => $current,
            ];

            if ($current <= 0) {
                $row['new'] = $current;
                $row['changed'] = false;
                $row['reason'] = 'This one has no price yet, so there is nothing to adjust.';
                $skipped[] = $row;
                continue;
            }

            $next = self::adjust($current, $mode, $amount);
            $row['new'] = $next;
            $row['changed'] = $next !== $current;

            if (!self::isValidPrice($next)) {
                $row['reason'] = $next < self::MIN_PRICE_SUBUNIT
                    ? 'That move would take this below ₦1.'
                    : 'That move would take this above the price ceiling.';
                $blocked[] = $row;
                continue;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'blocked' => $blocked];
    }

    /**
     * Apply a bulk adjustment to every active product in a category.
     * All or nothing: if one product cannot take the move, none of them do.
     * A reason is required here, because this is the change you will want
     * explained months later.
     *
     * Throws DomainException('blocked') when any product would fall out of range.
     */
    public static function applyBulk(int $categoryId, string $mode, float $amount, string $reason, ?int $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('reason_required');
        }

        $preview = self::previewBulk($categoryId, $mode, $amount);
        if ($preview['blocked']) {
            throw new DomainException('blocked');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $changed = 0;
            foreach ($preview['rows'] as $row) {
                if (!$row['changed']) {
                    continue;
                }
                $result = self::change($row['id'], $row['new'], $reason, $userId, false);
                if ($result['changed']) {
                    $changed++;
                }
            }
            $pdo->commit();
            return [
                'changed'    => $changed,
                'considered' => count($preview['rows']),
                'skipped'    => count($preview['skipped']),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** The price history for one product, newest first, with who changed it. */
    public static function history(int $productId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT h.id, h.old_price_subunit, h.new_price_subunit, h.effective_from, h.effective_to,
                    h.change_reason,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS changed_by_name
               FROM product_price_history h
               LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.product_id = :product_id
              ORDER BY h.effective_from DESC, h.id DESC
              LIMIT ' . $limit,
            [':product_id' => $productId]
        );
    }
}

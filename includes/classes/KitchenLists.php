<?php
/** Saved, reusable Kitchen Lists for signed-in business customers. */
final class KitchenLists
{
    public const MAX_LINES = 50;
    private const NAME_MAX = 150;
    private const NOTE_MAX = 255;

    public static function allForCustomer(int $userId): array
    {
        return Database::all(
            'SELECT t.id, t.name, t.note, t.created_at, t.updated_at, COUNT(i.id) AS item_count
               FROM kitchen_run_templates t
               LEFT JOIN kitchen_run_template_items i ON i.template_id = t.id
              WHERE t.user_id = :user_id
              GROUP BY t.id
              ORDER BY t.updated_at DESC, t.id DESC',
            [':user_id' => $userId]
        );
    }

    public static function findForCustomer(int $id, int $userId): ?array
    {
        $list = Database::one(
            'SELECT id, user_id, name, note, created_at, updated_at
               FROM kitchen_run_templates
              WHERE id = :id AND user_id = :user_id',
            [':id' => $id, ':user_id' => $userId]
        );
        if ($list === null) {
            return null;
        }
        $list['items'] = Database::all(
            'SELECT i.id, i.product_id, i.item_name, i.quantity, i.unit_id, i.unit_label, i.sort_order, i.note,
                    p.is_active AS product_is_active, p.current_price_subunit AS product_price_subunit
               FROM kitchen_run_template_items i
               LEFT JOIN products p ON p.id = i.product_id
              WHERE i.template_id = :template_id
              ORDER BY i.sort_order, i.id',
            [':template_id' => $id]
        );
        return $list;
    }

    public static function create(int $userId, string $name, ?string $note, array $items): array
    {
        [$name, $note, $lines] = self::validate($userId, $name, $note, $items);
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            self::assertUniqueName($userId, $name, 0);
            Database::run(
                'INSERT INTO kitchen_run_templates (user_id, name, note) VALUES (:user_id, :name, :note)',
                [':user_id' => $userId, ':name' => $name, ':note' => $note]
            );
            $id = (int) $pdo->lastInsertId();
            self::insertLines($pdo, $id, $lines);
            $pdo->commit();
            return ['id' => $id, 'name' => $name, 'item_count' => count($lines)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::rethrowWrite($e);
        }
    }

    public static function update(int $id, int $userId, string $name, ?string $note, array $items): array
    {
        [$name, $note, $lines] = self::validate($userId, $name, $note, $items);
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $owned = Database::one(
                'SELECT id FROM kitchen_run_templates WHERE id = :id AND user_id = :user_id FOR UPDATE',
                [':id' => $id, ':user_id' => $userId]
            );
            if ($owned === null) {
                throw new DomainException('not_found');
            }
            self::assertUniqueName($userId, $name, $id);
            Database::run(
                'UPDATE kitchen_run_templates SET name = :name, note = :note, updated_at = NOW()
                  WHERE id = :id AND user_id = :user_id',
                [':name' => $name, ':note' => $note, ':id' => $id, ':user_id' => $userId]
            );
            Database::run('DELETE FROM kitchen_run_template_items WHERE template_id = :id', [':id' => $id]);
            self::insertLines($pdo, $id, $lines);
            $pdo->commit();
            return ['id' => $id, 'name' => $name, 'item_count' => count($lines)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::rethrowWrite($e);
        }
    }

    public static function delete(int $id, int $userId): void
    {
        $deleted = Database::run(
            'DELETE FROM kitchen_run_templates WHERE id = :id AND user_id = :user_id',
            [':id' => $id, ':user_id' => $userId]
        );
        if ($deleted !== 1) {
            throw new DomainException('not_found');
        }
    }

    public static function saveFromRun(int $runId, int $userId, string $name, ?string $note): array
    {
        $run = Database::one(
            'SELECT id FROM kitchen_run_requests WHERE id = :id AND user_id = :user_id',
            [':id' => $runId, ':user_id' => $userId]
        );
        if ($run === null) {
            throw new DomainException('not_found');
        }
        $items = Database::all(
            'SELECT product_id, item_name, quantity, unit_id, unit_label, note
               FROM kitchen_run_items
              WHERE request_id = :request_id
              ORDER BY sort_order, id',
            [':request_id' => $runId]
        );
        return self::create($userId, $name, $note, $items);
    }

    /** Lines prepared for the existing M7 form. Inactive products become free text. */
    public static function forRun(int $id, int $userId): ?array
    {
        $list = self::findForCustomer($id, $userId);
        if ($list === null) {
            return null;
        }
        foreach ($list['items'] as &$item) {
            if ($item['product_id'] !== null && (empty($item['product_is_active']) || $item['product_price_subunit'] === null)) {
                $item['product_id'] = null;
            }
        }
        unset($item);
        return $list;
    }

    public static function catalogueOptions(): array
    {
        return Database::all(
            'SELECT p.id, p.name, p.unit_id, u.name AS unit_name
               FROM products p
               JOIN units_of_measurement u ON u.id = p.unit_id
              WHERE p.is_active = 1
              ORDER BY p.name'
        );
    }

    public static function unitOptions(): array
    {
        return Database::all('SELECT id, name FROM units_of_measurement ORDER BY id');
    }

    public static function message(string $code): string
    {
        return [
            'invalid_name' => 'Name this list using 150 characters or fewer.',
            'duplicate_name' => 'You already have a saved list with that name. Choose another name.',
            'note_too_long' => 'Keep the list note to 255 characters or fewer.',
            'no_items' => 'Add at least 1 item to this list.',
            'too_many_items' => 'A saved list can hold up to 50 items.',
            'invalid_line' => 'Each item needs a name, quantity and unit.',
            'line_note_too_long' => 'Keep each item note to 255 characters or fewer.',
            'not_found' => 'That saved list is not available.',
            'confirm_delete' => 'Tick the confirmation box before deleting this list.',
        ][$code] ?? 'We could not save that list. Please try again.';
    }

    public static function statusCode(string $code): int
    {
        return $code === 'not_found' ? 404 : ($code === 'duplicate_name' ? 409 : 422);
    }

    /** Pure list-level rules used before any catalogue or unit lookup. */
    public static function validateBasics(string $name, ?string $note, array $items): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new DomainException('invalid_name');
        }
        $note = trim((string) $note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new DomainException('note_too_long');
        }
        if (!$items) {
            throw new DomainException('no_items');
        }
        if (count($items) > self::MAX_LINES) {
            throw new DomainException('too_many_items');
        }
        return [$name, $note === '' ? null : $note];
    }

    private static function validate(int $userId, string $name, ?string $note, array $items): array
    {
        if ($userId < 1) {
            throw new DomainException('not_found');
        }
        [$name, $note] = self::validateBasics($name, $note, $items);
        return [$name, $note, self::normaliseLines($items)];
    }

    private static function normaliseLines(array $items): array
    {
        $productIds = [];
        $unitIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new DomainException('invalid_line');
            }
            $productId = KitchenRuns::positiveInt($item['product_id'] ?? null);
            $unitId = KitchenRuns::positiveInt($item['unit_id'] ?? null);
            if ($productId !== null) {
                $productIds[$productId] = true;
            }
            if ($unitId !== null) {
                $unitIds[$unitId] = true;
            }
        }
        $products = self::rowsById('products', array_keys($productIds), true);
        $units = self::rowsById('units_of_measurement', array_keys($unitIds), false);

        $lines = [];
        foreach ($items as $item) {
            $productId = KitchenRuns::positiveInt($item['product_id'] ?? null);
            $quantity = KitchenRuns::quantity($item['quantity'] ?? null);
            $unitId = KitchenRuns::positiveInt($item['unit_id'] ?? null);
            $unitLabel = trim((string) ($item['unit_label'] ?? ''));
            $name = trim((string) ($item['item_name'] ?? ''));
            $lineNote = trim((string) ($item['note'] ?? ''));
            if (mb_strlen($lineNote) > self::NOTE_MAX) {
                throw new DomainException('line_note_too_long');
            }
            if ($productId !== null) {
                $product = $products[$productId] ?? null;
                if ($product === null) {
                    throw new DomainException('invalid_line');
                }
                $name = (string) $product['name'];
                $unitId = (int) $product['unit_id'];
                $unitLabel = (string) $product['unit_name'];
            } elseif ($unitId !== null) {
                $unit = $units[$unitId] ?? null;
                if ($unit === null) {
                    throw new DomainException('invalid_line');
                }
                $unitLabel = (string) $unit['name'];
            }
            if ($name === '' || mb_strlen($name) > 200 || $quantity === null || ($unitId === null && $unitLabel === '') || mb_strlen($unitLabel) > 80) {
                throw new DomainException('invalid_line');
            }
            $lines[] = [
                'product_id' => $productId,
                'item_name' => $name,
                'quantity' => $quantity,
                'unit_id' => $unitId,
                'unit_label' => $unitLabel === '' ? null : $unitLabel,
                'note' => $lineNote === '' ? null : $lineNote,
            ];
        }
        return $lines;
    }

    private static function rowsById(string $table, array $ids, bool $products): array
    {
        if (!$ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $sql = $products
            ? 'SELECT p.id, p.name, p.unit_id, u.name AS unit_name FROM products p JOIN units_of_measurement u ON u.id = p.unit_id WHERE p.id IN (' . $marks . ')'
            : 'SELECT id, name FROM units_of_measurement WHERE id IN (' . $marks . ')';
        $stmt = Database::getInstance()->getConnection()->prepare($sql);
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }
        return $rows;
    }

    private static function assertUniqueName(int $userId, string $name, int $exceptId): void
    {
        $duplicate = Database::one(
            'SELECT id FROM kitchen_run_templates
              WHERE user_id = :user_id AND name = :name AND id <> :except_id
              LIMIT 1',
            [':user_id' => $userId, ':name' => $name, ':except_id' => $exceptId]
        );
        if ($duplicate !== null) {
            throw new DomainException('duplicate_name');
        }
    }

    private static function insertLines(PDO $pdo, int $id, array $lines): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO kitchen_run_template_items
                (template_id, product_id, item_name, quantity, unit_id, unit_label, sort_order, note)
             VALUES (:template_id, :product_id, :item_name, :quantity, :unit_id, :unit_label, :sort_order, :note)'
        );
        foreach ($lines as $sort => $line) {
            $stmt->execute([
                ':template_id' => $id,
                ':product_id' => $line['product_id'],
                ':item_name' => $line['item_name'],
                ':quantity' => $line['quantity'],
                ':unit_id' => $line['unit_id'],
                ':unit_label' => $line['unit_label'],
                ':sort_order' => $sort,
                ':note' => $line['note'],
            ]);
        }
    }

    private static function rethrowWrite(Throwable $e): never
    {
        if ($e instanceof DomainException) {
            throw $e;
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
            throw new DomainException('duplicate_name');
        }
        throw $e;
    }
}

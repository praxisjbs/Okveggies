<?php
/** Read-only delivery-day manifest built from immutable order snapshots. */
final class DeliveryManifest
{
    public static function forDate(string $date): array
    {
        if (!Delivery::validDate($date)) {
            throw new InvalidArgumentException('invalid_delivery_date');
        }
        $orders = Database::all(
            'SELECT o.id AS order_id, o.order_number, o.order_status,
                    ds.delivery_date, ds.delivery_window, ds.admin_note,
                    z.id AS zone_id, z.name AS zone_name, z.sort_order AS zone_sort,
                    a.recipient_name, a.recipient_phone, a.address_line_1,
                    a.address_line_2, a.city, a.state, a.landmark
               FROM delivery_schedules ds
               JOIN orders o ON o.id = ds.order_id
               LEFT JOIN delivery_zones z ON z.id = o.delivery_zone_id
               LEFT JOIN order_addresses a ON a.order_id = o.id
              WHERE ds.delivery_date = :date AND o.order_status <> \'cancelled\'
              ORDER BY COALESCE(z.sort_order, 2147483647), z.name, o.order_number',
            [':date' => $date]
        );
        // Two queries for the whole day, not two per order. A Saturday with 60
        // orders and a couple of combos each was over 180 round trips before
        // this, on the one screen the team stands at while the van waits.
        $itemsByOrder = self::itemsForOrders(array_map(
            static fn(array $row): int => (int) $row['order_id'],
            $orders
        ));
        foreach ($orders as &$order) {
            $items = $itemsByOrder[(int) $order['order_id']] ?? [];
            $order['items'] = $items;
            $order['packing_lines'] = self::packingLines($items);
        }
        unset($order);
        $zones = self::groupByZone($orders);
        foreach ($zones as &$zone) {
            $zone['packing_totals'] = self::totals($zone['orders']);
        }
        unset($zone);
        return ['date' => $date, 'order_count' => count($orders), 'zones' => $zones];
    }

    /**
     * Every line and every combo component for a day's orders, in two queries.
     *
     * @param  array<int, int> $orderIds
     * @return array<int, array<int, array<string, mixed>>> keyed by order id
     */
    private static function itemsForOrders(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter($orderIds)));
        if (!$orderIds) {
            return [];
        }
        $items = Database::all(
            'SELECT id, order_id, item_type, item_name, quantity, unit_name
               FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')
              ORDER BY order_id, id',
            $orderIds
        );
        if (!$items) {
            return [];
        }
        $itemIds = array_map(static fn(array $row): int => (int) $row['id'], $items);
        $components = Database::all(
            'SELECT order_item_id, product_name, quantity, unit_name
               FROM order_item_components WHERE order_item_id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')
              ORDER BY id',
            $itemIds
        );
        $byItem = [];
        foreach ($components as $component) {
            $byItem[(int) $component['order_item_id']][] = $component;
        }
        $byOrder = [];
        foreach ($items as $item) {
            $item['components'] = $byItem[(int) $item['id']] ?? [];
            $byOrder[(int) $item['order_id']][] = $item;
        }
        return $byOrder;
    }

    public static function groupByZone(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $id = $order['zone_id'] === null ? 'none' : (string) $order['zone_id'];
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'id' => $order['zone_id'],
                    'name' => trim((string) ($order['zone_name'] ?? '')) ?: 'Zone not assigned',
                    'sort' => isset($order['zone_sort']) ? (int) $order['zone_sort'] : PHP_INT_MAX,
                    'orders' => [],
                ];
            }
            $groups[$id]['orders'][] = $order;
        }
        $groups = array_values($groups);
        usort($groups, static function (array $a, array $b): int {
            $sort = $a['sort'] <=> $b['sort'];
            return $sort !== 0 ? $sort : strcasecmp($a['name'], $b['name']);
        });
        return $groups;
    }

    /** Expand combos from their order-time snapshots and multiply by basket count. */
    public static function packingLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lineQty = (float) ($item['quantity'] ?? 0);
            if ((string) ($item['item_type'] ?? '') === 'combo' && !empty($item['components'])) {
                foreach ($item['components'] as $component) {
                    $lines[] = self::line(
                        (string) $component['product_name'],
                        $lineQty * (float) $component['quantity'],
                        (string) $component['unit_name'],
                        (string) $item['item_name']
                    );
                }
                continue;
            }
            $lines[] = self::line((string) $item['item_name'], $lineQty, (string) $item['unit_name'], null);
        }
        return $lines;
    }

    /**
     * One packing line. It carries both the display string a packer reads and
     * the exact number, because the zone totals add these up: rounding each
     * line to three places first and then summing drifts, and a drifting
     * kilogramme total is the one number on this page nobody can check.
     */
    private static function line(string $name, float $quantity, string $unit, ?string $fromCombo): array
    {
        return [
            'name' => $name,
            'quantity' => self::quantity($quantity),
            'quantity_exact' => $quantity,
            'unit' => $unit,
            'from_combo' => $fromCombo,
        ];
    }

    /**
     * What one zone has to buy in total, so a packer can check a crate against
     * a number rather than adding up thirty order lines by hand. Public because
     * it is pure arithmetic on money-adjacent quantities and is unit tested
     * alongside groupByZone and packingLines.
     */
    public static function totals(array $orders): array
    {
        $totals = [];
        foreach ($orders as $order) {
            foreach ($order['packing_lines'] ?? [] as $line) {
                $key = mb_strtolower($line['name'] . '|' . $line['unit']);
                if (!isset($totals[$key])) {
                    $totals[$key] = ['name' => $line['name'], 'unit' => $line['unit'], 'quantity_raw' => 0.0];
                }
                $totals[$key]['quantity_raw'] += (float) ($line['quantity_exact'] ?? $line['quantity']);
            }
        }
        $out = [];
        foreach ($totals as $total) {
            $out[] = ['name' => $total['name'], 'unit' => $total['unit'], 'quantity' => self::quantity($total['quantity_raw'])];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    private static function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}

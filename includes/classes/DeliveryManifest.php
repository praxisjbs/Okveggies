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
        foreach ($orders as &$order) {
            $items = Database::all(
                'SELECT id, item_type, item_name, quantity, unit_name FROM order_items WHERE order_id = :id ORDER BY id',
                [':id' => (int) $order['order_id']]
            );
            foreach ($items as &$item) {
                $item['components'] = (string) $item['item_type'] === 'combo'
                    ? Database::all('SELECT product_name, quantity, unit_name FROM order_item_components WHERE order_item_id = :id ORDER BY id', [':id' => (int) $item['id']])
                    : [];
            }
            unset($item);
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
                    $lines[] = [
                        'name' => (string) $component['product_name'],
                        'quantity' => self::quantity($lineQty * (float) $component['quantity']),
                        'unit' => (string) $component['unit_name'],
                        'from_combo' => (string) $item['item_name'],
                    ];
                }
                continue;
            }
            $lines[] = [
                'name' => (string) $item['item_name'],
                'quantity' => self::quantity($lineQty),
                'unit' => (string) $item['unit_name'],
                'from_combo' => null,
            ];
        }
        return $lines;
    }

    private static function totals(array $orders): array
    {
        $totals = [];
        foreach ($orders as $order) {
            foreach ($order['packing_lines'] ?? [] as $line) {
                $key = mb_strtolower($line['name'] . '|' . $line['unit']);
                if (!isset($totals[$key])) {
                    $totals[$key] = ['name' => $line['name'], 'unit' => $line['unit'], 'quantity_raw' => 0.0];
                }
                $totals[$key]['quantity_raw'] += (float) $line['quantity'];
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

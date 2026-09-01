<?php
/** Secure public Order Trail tokens and read-only order projections. */
final class OrderTrail
{
    public static function newToken(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function hashToken(string $token): string { return hash('sha256', $token); }
    public static function isValidToken(string $token): bool { return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token); }
    public static function findByToken(string $token): ?array
    {
        if (!self::isValidToken($token)) return null;
        return self::find('o.order_trail_token_hash = :value', self::hashToken($token));
    }
    public static function findForCustomer(int $orderId, int $userId): ?array
    {
        return self::find('o.id = :value AND o.user_id = :user_id', $orderId, $userId);
    }
    private static function find(string $where, $value, ?int $userId = null): ?array
    {
        $params=[':value'=>$value]; if($userId!==null)$params[':user_id']=$userId;
        $order=Database::one('SELECT o.id,o.order_number,o.order_status,o.payment_option,o.payment_status,o.order_total_subunit,o.deposit_required_subunit,o.balance_due_subunit,o.preferred_delivery_date,o.created_at,(SELECT p.expected_amount_subunit FROM payments p WHERE p.order_id=o.id ORDER BY p.id LIMIT 1) amount_due_subunit FROM orders o WHERE '.$where.' LIMIT 1',$params);
        if(!$order)return null;
        $order['items']=Database::all('SELECT item_name,quantity,unit_name,unit_price_subunit,line_total_subunit FROM order_items WHERE order_id=:id ORDER BY id',[':id'=>(int)$order['id']]);
        $order['history']=Database::all('SELECT new_status,created_at FROM order_status_history WHERE order_id=:id ORDER BY created_at,id',[':id'=>(int)$order['id']]);
        return $order;
    }
}

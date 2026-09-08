<?php
/** Kitchen Run request, quote and order conversion domain service. */
final class KitchenRuns
{
    public const MODES = ['catalogue', 'custom', 'upload', 'mixed'];
    public const STATUSES = ['submitted', 'quoted', 'approved', 'converted', 'declined', 'cancelled'];

    public static function mayTransition(string $from, string $to): bool
    {
        return in_array([$from, $to], [
            ['submitted', 'quoted'], ['submitted', 'declined'], ['submitted', 'cancelled'],
            ['quoted', 'approved'], ['quoted', 'cancelled'], ['quoted', 'submitted'], ['approved', 'converted'],
        ], true);
    }

    public static function quoteLines(array $lines): array
    {
        $total = 0;
        foreach ($lines as $key => $line) {
            $name = trim((string) ($line['item_name'] ?? ''));
            $quantity = self::quantity($line['quantity'] ?? null);
            $price = self::nonNegativeInt($line['unit_price_subunit'] ?? null);
            if ($name === '' || $quantity === null || $price === null) {
                throw new DomainException('invalid_line');
            }
            $line['item_name'] = $name;
            $line['quantity'] = $quantity;
            $line['unit_price_subunit'] = $price;
            $line['line_total_subunit'] = Money::lineTotal($quantity, $price);
            $lines[$key] = $line;
            $total += $line['line_total_subunit'];
        }
        if (!$lines) throw new DomainException('no_items');
        return ['lines' => array_values($lines), 'total_subunit' => $total];
    }

    public static function withinCap(int $total, ?int $cap): bool
    {
        return $cap === null || $total <= $cap;
    }

    public static function remainingBalance(int $total, int $deposit): int
    {
        return Money::balance($total, $deposit);
    }

    /** Open-budget requests permit a deposit or approved business credit only. */
    public static function paymentAllowed(string $option, string $customerType, bool $openBudget, bool $creditApproved): bool
    {
        if ($option === 'deposit') return true;
        if ($option === 'on_account') return $customerType === 'business' && $creditApproved;
        return !$openBudget && $option === 'pay_in_full';
    }

    /** Validate request inputs without a database write, for forms and tests. */
    public static function validateSubmission(string $mode, string $pricing, array $items): array
    {
        if (!in_array($mode, self::MODES, true) || !in_array($pricing, ['by_us', 'by_customer'], true) || !$items) return ['ok' => false];
        foreach ($items as $item) {
            if (!is_array($item)) return ['ok' => false];
            $catalogue = !empty($item['product_id']);
            if (!$catalogue && trim((string)($item['item_name'] ?? '')) === '') return ['ok' => false];
            if ($pricing === 'by_us' && (self::quantity($item['quantity'] ?? null) === null || (int)($item['unit_id'] ?? 0) < 1)) return ['ok' => false];
            if ($pricing === 'by_customer') {
                $price = $item['target_price_subunit'] ?? $item['unit_price_subunit'] ?? null;
                if (self::positiveInt($price) === null) return ['ok' => false];
            }
        }
        return ['ok' => true];
    }

    public static function allowedUpload(string $filename, string $mime, int $size): bool
    {
        return $size > 0 && $size <= 5 * 1024 * 1024
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,180}\.(?:jpe?g|png|pdf)$/i', $filename) === 1
            && in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true);
    }

    public static function quoteExpired(string $quotedAt, int $days, string $now): bool
    {
        $start = strtotime($quotedAt); $current = strtotime($now);
        return $start !== false && $current !== false && $days >= 1 && $current >= strtotime('+' . $days . ' days', $start);
    }

    public static function canCustomerEdit(string $status): bool { return $status === 'submitted'; }
    public static function notificationEvents(): array { return ['submitted', 'quoted', 'approved', 'converted']; }

    /** Customer submission. Original input is stored untouched for audit. */
    public static function submit(int $userId, string $customerType, array $input, ?string $attachment = null): array
    {
        if ($userId < 1 || !in_array($customerType, Customer::TYPES, true)) throw new DomainException('bad_customer');
        $mode = (string) ($input['input_mode'] ?? '');
        if (!in_array($mode, self::MODES, true)) throw new DomainException('bad_mode');
        $pricing = (string) ($input['pricing_mode'] ?? 'by_us');
        if (!in_array($pricing, ['by_us', 'by_customer'], true)) throw new DomainException('bad_pricing_mode');
        $open = !empty($input['is_open_budget']);
        if ($open && $pricing !== 'by_us') throw new DomainException('open_budget_pricing');
        if ($mode === 'upload' && $attachment === null) throw new DomainException('attachment_required');
        $lines = self::submissionLines($mode, $pricing, $input['items'] ?? []);
        if ($mode === 'catalogue') $lines = self::hydrateCatalogueLines($lines);
        $cap = self::optionalMoney($input['spend_cap_subunit'] ?? null);
        $budget = self::optionalMoney($input['budget_ceiling_subunit'] ?? null);
        if (!$open && ($cap !== null || $budget !== null)) throw new DomainException('budget_not_open');

        $customer = Database::one('SELECT first_name, last_name, email, phone FROM users WHERE id = :id AND status = \'active\'', [':id' => $userId]);
        if (!$customer) throw new DomainException('bad_customer');
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $number = OrderNumber::nextKitchenRunNumber($pdo);
            Database::run(
                'INSERT INTO kitchen_run_requests (request_number,user_id,customer_type,contact_name,contact_phone,contact_email,input_mode,pricing_mode,status,is_open_budget,budget_ceiling_subunit,spend_cap_subunit,attachment_url,original_submission_json,customer_note,created_by)
                 VALUES (:number,:user_id,:type,:name,:phone,:email,:mode,:pricing,\'submitted\',:open,:budget,:cap,:attachment,:original,:note,:created_by)',
                [':number'=>$number, ':user_id'=>$userId, ':type'=>$customerType,
                 ':name'=>trim($customer['first_name'].' '.$customer['last_name']), ':phone'=>$customer['phone'], ':email'=>$customer['email'],
                 ':mode'=>$mode, ':pricing'=>$pricing, ':open'=>$open ? 1 : 0, ':budget'=>$budget, ':cap'=>$cap,
                 ':attachment'=>$attachment, ':original'=>json_encode($input, JSON_THROW_ON_ERROR), ':note'=>self::note($input['customer_note'] ?? ''), ':created_by'=>$userId]
            );
            $id = (int) $pdo->lastInsertId();
            self::insertLines($pdo, $id, $lines);
            $pdo->commit();
            return ['id'=>$id, 'request_number'=>$number, 'status'=>'submitted'];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /** Staff quote is compare-and-swap protected by the row version. */
    public static function quote(int $requestId, int $staffId, int $version, array $input): array
    {
        foreach (($input['items'] ?? []) as $line) {
            if (!is_array($line) || (int) ($line['unit_id'] ?? 0) < 1) throw new DomainException('quantity_unit_required');
        }
        $quoted = self::quoteLines($input['items'] ?? []);
        $deposit = self::optionalMoney($input['deposit_subunit'] ?? null);
        $date = (string) ($input['preferred_delivery_date'] ?? '');
        $zone = (int) ($input['delivery_zone_id'] ?? 0);
        if ($date === '' || $zone < 1) throw new DomainException('delivery_required');
        $pdo = Database::getInstance()->getConnection(); $pdo->beginTransaction();
        try {
            $request = Database::one('SELECT * FROM kitchen_run_requests WHERE id = :id FOR UPDATE', [':id'=>$requestId]);
            if (!$request) throw new DomainException('not_found');
            if ((int)$request['state_version'] !== $version || $request['status'] !== 'submitted') throw new DomainException('stale');
            if (!empty($request['is_open_budget']) && $deposit === null) throw new DomainException('deposit_required');
            if (!self::withinCap($quoted['total_subunit'], $request['spend_cap_subunit'] === null ? null : (int)$request['spend_cap_subunit'])) throw new DomainException('cap_exceeded');
            Database::run('DELETE FROM kitchen_run_items WHERE request_id = :id', [':id'=>$requestId]);
            self::insertLines($pdo, $requestId, $quoted['lines']);
            Database::run('UPDATE kitchen_run_requests SET status=\'quoted\',quoted_total_subunit=:quoted_total,estimated_total_subunit=:estimated_total,deposit_subunit=:deposit,preferred_delivery_date=:date,delivery_zone_id=:zone,admin_note=:note,quoted_by=:staff,state_version=state_version+1 WHERE id=:id',
                [':quoted_total'=>$quoted['total_subunit'], ':estimated_total'=>$quoted['total_subunit'], ':deposit'=>$deposit, ':date'=>$date, ':zone'=>$zone, ':note'=>self::note($input['admin_note'] ?? ''), ':staff'=>$staffId, ':id'=>$requestId]);
            $pdo->commit(); return ['id'=>$requestId,'status'=>'quoted','total_subunit'=>$quoted['total_subunit'],'version'=>$version+1];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function approve(int $requestId, int $userId, int $version): array
    {
        $changed = Database::run('UPDATE kitchen_run_requests SET status=\'approved\',approved_at=NOW(),state_version=state_version+1 WHERE id=:id AND user_id=:user AND status=\'quoted\' AND state_version=:version', [':id'=>$requestId, ':user'=>$userId, ':version'=>$version]);
        if ($changed !== 1) throw new DomainException('stale_or_not_owned');
        return ['id'=>$requestId, 'status'=>'approved', 'version'=>$version+1];
    }

    /** Convert once. The request row lock and converted_order_id make retries safe. */
    public static function convert(int $requestId, int $staffId, int $version, string $paymentOption): array
    {
        $pdo = Database::getInstance()->getConnection(); $pdo->beginTransaction();
        try {
            $r = Database::one('SELECT * FROM kitchen_run_requests WHERE id=:id FOR UPDATE', [':id'=>$requestId]);
            if (!$r) throw new DomainException('not_found');
            if ($r['converted_order_id'] !== null) { $order = Database::one('SELECT id,order_number FROM orders WHERE id=:id', [':id'=>$r['converted_order_id']]); $pdo->commit(); return ['id'=>(int)$order['id'],'order_number'=>$order['order_number'],'already_converted'=>true]; }
            if ($r['status'] !== 'approved' || (int)$r['state_version'] !== $version) throw new DomainException('stale');
            $credit = Database::one('SELECT credit_status FROM business_customers WHERE user_id=:id', [':id'=>$r['user_id']]);
            if (!self::paymentAllowed($paymentOption, $r['customer_type'], !empty($r['is_open_budget']), ($credit['credit_status'] ?? '') === 'approved')) throw new DomainException('payment_not_allowed');
            $lines = Database::all('SELECT i.*, COALESCE(u.name,i.unit_label,\'unit\') AS resolved_unit, p.sku FROM kitchen_run_items i LEFT JOIN units_of_measurement u ON u.id=i.unit_id LEFT JOIN products p ON p.id=i.product_id WHERE i.request_id=:id ORDER BY i.sort_order,i.id', [':id'=>$requestId]);
            $quoted = self::quoteLines($lines); $total = $quoted['total_subunit'];
            if (!self::withinCap($total, $r['spend_cap_subunit'] === null ? null : (int)$r['spend_cap_subunit'])) throw new DomainException('cap_exceeded');
            $deposit = $paymentOption === 'deposit' ? (int)($r['deposit_subunit'] ?? 0) : null;
            if ($paymentOption === 'deposit' && $deposit < 1) throw new DomainException('deposit_required');
            $number = OrderNumber::nextOrderNumber($pdo);
            Database::run('INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,deposit_required_subunit,balance_due_subunit,preferred_delivery_date,delivery_zone_id,delivery_fee_note,customer_note,created_by) VALUES (:number,:user,:type,\'pending\',:option,\'unpaid\',:total,:total,:deposit,:balance,:date,:zone,:fee,:note,:staff)',
                [':number'=>$number, ':user'=>$r['user_id'], ':type'=>$r['customer_type'], ':option'=>$paymentOption, ':total'=>$total, ':deposit'=>$deposit, ':balance'=>Money::balance($total,(int)$deposit), ':date'=>$r['preferred_delivery_date'], ':zone'=>$r['delivery_zone_id'], ':fee'=>'Delivery fee is arranged and settled separately after we confirm your area.', ':note'=>$r['customer_note'], ':staff'=>$staffId]);
            $orderId=(int)$pdo->lastInsertId(); self::insertOrderLines($pdo,$orderId,$quoted['lines']);
            Database::run('INSERT INTO order_status_history (order_id,old_status,new_status,source,changed_by,note) VALUES (:order,NULL,\'pending\',\'kitchen_run\',:staff,:note)', [':order'=>$orderId,':staff'=>$staffId,':note'=>'Converted from Kitchen Run '.$r['request_number']]);
            Database::run('INSERT INTO delivery_schedules (order_id,delivery_date,status,updated_by) VALUES (:order,:date,\'scheduled\',:staff)', [':order'=>$orderId,':date'=>$r['preferred_delivery_date'],':staff'=>$staffId]);
            self::insertPayments($orderId,(int)$r['user_id'],$number,$paymentOption,$total,$deposit,$r['preferred_delivery_date']);
            Database::run('UPDATE kitchen_run_requests SET status=\'converted\',converted_order_id=:order,state_version=state_version+1 WHERE id=:id', [':order'=>$orderId,':id'=>$requestId]);
            $pdo->commit(); return ['id'=>$orderId,'order_number'=>$number,'already_converted'=>false];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /**
     * Conversion entry point used by the controller. The optional callback is
     * deliberately limited to test-only fault injection before any write.
     */
    public static function convertAtomically(int $requestId, int $staffId, ?callable $beforeWrite = null, ?int $version = null, string $paymentOption = 'deposit'): array
    {
        if ($beforeWrite !== null) {
            try {
                $beforeWrite();
            } catch (Throwable $e) {
                return ['ok' => false, 'code' => 'injected_failure'];
            }
        }
        if ($version === null) {
            throw new DomainException('stale');
        }
        return self::convert($requestId, $staffId, $version, $paymentOption);
    }

    public static function findForCustomer(int $id, int $userId): ?array { return Database::one('SELECT * FROM kitchen_run_requests WHERE id=:id AND user_id=:user', [':id'=>$id,':user'=>$userId]); }
    public static function allForCustomer(int $userId): array { return Database::all('SELECT * FROM kitchen_run_requests WHERE user_id=:user ORDER BY id DESC', [':user'=>$userId]); }
    public static function allForStaff(): array { return Database::all('SELECT r.*,u.email FROM kitchen_run_requests r LEFT JOIN users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 100'); }
    public static function lines(int $id): array { return Database::all('SELECT i.*,p.name AS product_name,u.name AS unit_name FROM kitchen_run_items i LEFT JOIN products p ON p.id=i.product_id LEFT JOIN units_of_measurement u ON u.id=i.unit_id WHERE i.request_id=:id ORDER BY i.sort_order,i.id', [':id'=>$id]); }

    private static function submissionLines(string $mode,string $pricing,$items): array
    {
        if (!is_array($items) || !$items) { if ($mode === 'upload') return [['item_name'=>'List awaiting transcription','price_source'=>'admin']]; throw new DomainException('no_items'); }
        $out=[]; foreach ($items as $item) { if (!is_array($item)) throw new DomainException('invalid_line'); $name=trim((string)($item['item_name']??'')); if ($name==='') throw new DomainException('invalid_line'); $qty=self::quantity($item['quantity']??null); $unit=(int)($item['unit_id']??0); $price=self::optionalMoney($item['unit_price_subunit']??null); if ($pricing==='by_us' && ($qty===null || $unit<1)) throw new DomainException('quantity_unit_required'); if ($pricing==='by_customer' && $price===null) throw new DomainException('price_required'); $out[]=['product_id'=>(int)($item['product_id']??0)?:null,'item_name'=>$name,'quantity'=>$qty,'unit_id'=>$unit?:null,'unit_label'=>self::note($item['unit_label']??''),'unit_price_subunit'=>$price,'line_total_subunit'=>($qty!==null&&$price!==null)?Money::lineTotal($qty,$price):null,'price_source'=>$pricing==='by_customer'?'customer':'admin','note'=>self::note($item['note']??'')]; } return $out;
    }
    /** Catalogue prices and names are always read on the server, never posted. */
    private static function hydrateCatalogueLines(array $lines): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($line) => (int)($line['product_id'] ?? 0), $lines))));
        if (count($ids) !== count($lines)) throw new DomainException('invalid_catalogue_item');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::getInstance()->getConnection()->prepare('SELECT p.id,p.name,p.current_price_subunit,p.unit_id,u.name AS unit_name FROM products p JOIN units_of_measurement u ON u.id=p.unit_id WHERE p.id IN ('.$marks.') AND p.is_active=1');
        $rows->execute($ids); $found=[]; foreach($rows->fetchAll() as $row) $found[(int)$row['id']]=$row;
        foreach($lines as &$line) { $row=$found[(int)$line['product_id']]??null; if(!$row || $row['current_price_subunit']===null) throw new DomainException('invalid_catalogue_item'); $line['item_name']=$row['name']; $line['unit_id']=(int)$row['unit_id']; $line['unit_label']=$row['unit_name']; $line['unit_price_subunit']=(int)$row['current_price_subunit']; $line['line_total_subunit']=Money::lineTotal($line['quantity'],(int)$row['current_price_subunit']); $line['price_source']='catalogue'; }
        unset($line); return $lines;
    }
    private static function insertLines(PDO $pdo,int $id,array $lines): void { $s=$pdo->prepare('INSERT INTO kitchen_run_items (request_id,product_id,item_name,quantity,unit_id,unit_label,unit_price_subunit,line_total_subunit,price_source,sort_order,note) VALUES (:request,:product,:name,:quantity,:unit,:label,:price,:total,:source,:sort,:note)'); foreach($lines as $n=>$l) $s->execute([':request'=>$id,':product'=>$l['product_id']??null,':name'=>$l['item_name'],':quantity'=>$l['quantity']??null,':unit'=>$l['unit_id']??null,':label'=>$l['unit_label']??null,':price'=>$l['unit_price_subunit']??null,':total'=>$l['line_total_subunit']??null,':source'=>$l['price_source']??'admin',':sort'=>$n,':note'=>$l['note']??null]); }
    private static function insertOrderLines(PDO $pdo,int $order,array $lines): void { $s=$pdo->prepare('INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit) VALUES (:order,\'product\',:product,:name,:sku,:unit,:quantity,:price,:total)'); foreach($lines as $l) $s->execute([':order'=>$order,':product'=>$l['product_id']??null,':name'=>$l['item_name'],':sku'=>$l['sku']??'KITCHEN-RUN',':unit'=>$l['resolved_unit']??$l['unit_label']??'unit',':quantity'=>$l['quantity'],':price'=>$l['unit_price_subunit'],':total'=>$l['line_total_subunit']]); }
    private static function insertPayments(int $order,int $user,string $number,string $option,int $total,?int $deposit,string $date): void { $due=$option==='deposit'?(int)$deposit:$total; Database::run('INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,status,due_at) VALUES (:number,:user,:order,:provider,:type,:amount,\'unpaid\',:due)', [':number'=>'PAY-'.$number,':user'=>$user,':order'=>$order,':provider'=>$option==='on_account'?'account':'paystack',':type'=>$option,':amount'=>$due,':due'=>$option==='on_account'?$date.' 00:00:00':null]); if($option==='deposit'&&$total>$due) Database::run('INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,status,due_at) VALUES (:number,:user,:order,\'manual\',\'balance\',:amount,\'unpaid\',:due)', [':number'=>'PAY-'.$number.'-B',':user'=>$user,':order'=>$order,':amount'=>$total-$due,':due'=>$date.' 00:00:00']); }
    private static function quantity($value): ?string { if ($value===null||$value==='') return null; $v=(string)$value; return preg_match('/^[0-9]+(?:\\.[0-9]{1,3})?$/',$v)&&(float)$v>0?$v:null; }
    private static function optionalMoney($v): ?int { return $v===null||$v===''?null:self::nonNegativeInt($v); }
    private static function nonNegativeInt($v): ?int
    {
        if (is_int($v)) return $v >= 0 ? $v : null;
        if (!is_string($v) || preg_match('/^[0-9]+$/', $v) !== 1) return null;
        $trimmed = ltrim($v, '0'); if ($trimmed === '') return 0;
        $max = (string) PHP_INT_MAX;
        if (strlen($trimmed) > strlen($max) || (strlen($trimmed) === strlen($max) && strcmp($trimmed, $max) > 0)) return null;
        return (int) $trimmed;
    }
    private static function positiveInt($v): ?int { $n=self::nonNegativeInt($v); return $n !== null && $n > 0 ? $n : null; }
    private static function note($v): ?string { $v=trim((string)$v); return $v===''?null:(mb_strlen($v)>2000?throw new DomainException('note_too_long'):$v); }
}

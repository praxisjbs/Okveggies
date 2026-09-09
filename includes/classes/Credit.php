<?php
/** Shared B2B credit applications, signed-journal calculations and reads. */
final class Credit
{
    public const FACILITY_STATES = ['not_requested', 'requested', 'approved', 'declined', 'suspended', 'withdrawn'];
    public const APPLICATION_STATES = ['pending', 'approved', 'declined', 'withdrawn'];
    public const TRANSACTION_TYPES = ['charge', 'repayment', 'adjustment'];
    public const PER_PAGE = 25;
    private const TZ = 'Africa/Lagos';

    public static function validateApplication($days, $limitNaira, $reason): array
    {
        $daysText = trim((string) $days);
        if (preg_match('/^\d+$/', $daysText) !== 1 || (int) $daysText < 7 || (int) $daysText > 10) {
            throw new DomainException('invalid_days');
        }
        $limit = self::nairaToSubunit($limitNaira);
        if ($limit === null || $limit < 1) {
            throw new DomainException('invalid_limit');
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 20 || mb_strlen($reason) > 1000) {
            throw new DomainException('invalid_reason');
        }
        return ['requested_days' => (int) $daysText, 'requested_limit_subunit' => $limit, 'reason' => $reason];
    }

    public static function apply(int $userId, array $input): array
    {
        $values = self::validateApplication($input['requested_days'] ?? '', $input['requested_limit'] ?? '', $input['reason'] ?? '');
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $business = Database::one(
                'SELECT id, credit_status FROM business_customers WHERE user_id = :user_id FOR UPDATE',
                [':user_id' => $userId]
            );
            if ($business === null) { throw new DomainException('not_found'); }
            if (in_array((string) $business['credit_status'], ['approved', 'suspended'], true)) {
                throw new DomainException('active_credit');
            }
            $pending = Database::one(
                'SELECT id FROM credit_applications WHERE business_customer_id = :business_id AND status = :pending LIMIT 1 FOR UPDATE',
                [':business_id' => (int) $business['id'], ':pending' => 'pending']
            );
            if ($pending !== null) { throw new DomainException('active_application'); }
            Database::run(
                'INSERT INTO credit_applications (business_customer_id, requested_days, requested_limit_subunit, reason, status)
                 VALUES (:business_id, :days, :credit_limit, :reason, :status)',
                [':business_id' => (int) $business['id'], ':days' => $values['requested_days'],
                 ':credit_limit' => $values['requested_limit_subunit'], ':reason' => $values['reason'], ':status' => 'pending']
            );
            $id = (int) $pdo->lastInsertId();
            Database::run(
                'UPDATE business_customers SET credit_requested = :requested, credit_status = :status WHERE id = :id',
                [':requested' => 1, ':status' => 'requested', ':id' => (int) $business['id']]
            );
            $pdo->commit();
            return ['id' => $id] + $values + ['status' => 'pending'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function customerAccount(int $userId, ?DateTimeImmutable $now = null): ?array
    {
        $business = Database::one(
            'SELECT id, business_name, credit_status, credit_days, credit_limit_subunit
               FROM business_customers WHERE user_id = :user_id',
            [':user_id' => $userId]
        );
        if ($business === null) { return null; }
        $application = Database::one(
            'SELECT id, requested_days, requested_limit_subunit, reason, status, decision_reason, reviewed_at, created_at
               FROM credit_applications WHERE business_customer_id = :business_id ORDER BY id DESC LIMIT 1',
            [':business_id' => (int) $business['id']]
        );
        return ['business' => $business, 'application' => $application, 'summary' => self::summaryForBusiness($business, $now)];
    }

    public static function summaryForBusiness(array $business, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->setTimezone(new DateTimeZone(self::TZ));
        $entries = Database::all(
            'SELECT amount_subunit, due_date FROM credit_transactions
              WHERE business_customer_id = :business_id
              ORDER BY (due_date IS NULL), due_date, id',
            [':business_id' => (int) $business['id']]
        );
        return self::snapshotFromTransactions($business, $entries, $now->format('Y-m-d'));
    }

    /** Apply signed reductions to the oldest positive entries for due-date views. */
    public static function snapshotFromTransactions(array $business, array $entries, string $today): array
    {
        $amounts = array_map(static fn(array $entry): int => (int) ($entry['amount_subunit'] ?? 0), $entries);
        $outstanding = max(0, Money::sum($amounts));
        $reductions = abs(Money::sum(array_filter($amounts, static fn(int $amount): bool => $amount < 0)));
        $overdue = 0; $earliest = null;
        foreach ($entries as $entry) {
            $amount = (int) ($entry['amount_subunit'] ?? 0);
            if ($amount <= 0) { continue; }
            $applied = min($amount, $reductions); $remaining = $amount - $applied; $reductions -= $applied;
            if ($remaining < 1 || empty($entry['due_date'])) { continue; }
            $due = (string) $entry['due_date'];
            if ($earliest === null) { $earliest = $due; }
            if ($due < $today) { $overdue += $remaining; }
        }
        return self::snapshot($business, [
            'outstanding_subunit' => $outstanding,
            'past_due_charges' => $overdue,
            'reductions' => 0,
            'earliest_due_date' => $earliest,
        ]);
    }

    public static function snapshot(array $business, array $ledger): array
    {
        $state = in_array((string) ($business['credit_status'] ?? ''), self::FACILITY_STATES, true)
            ? (string) $business['credit_status'] : 'not_requested';
        $limit = max(0, (int) ($business['credit_limit_subunit'] ?? 0));
        $outstanding = max(0, (int) ($ledger['outstanding_subunit'] ?? 0));
        $reductions = min(0, (int) ($ledger['reductions'] ?? 0));
        $overdue = max(0, (int) ($ledger['past_due_charges'] ?? 0) + $reductions);
        return [
            'state' => $state,
            'approved' => $state === 'approved' && $business['credit_limit_subunit'] !== null,
            'limit_subunit' => $limit,
            'outstanding_subunit' => $outstanding,
            'available_subunit' => $state === 'approved' ? max(0, $limit - $outstanding) : 0,
            'overdue_subunit' => $overdue,
            'earliest_due_date' => !empty($ledger['earliest_due_date']) ? (string) $ledger['earliest_due_date'] : null,
        ];
    }

    /**
     * The facility a business may draw on, read only. Checkout, the Kitchen Run
     * conversion panel and the draw itself all read the same shape, so what a
     * customer is shown and what the server allows can never disagree.
     */
    public static function facilityForUser(int $userId, ?DateTimeImmutable $now = null): ?array
    {
        $business = Database::one(
            'SELECT id, business_name, credit_status, credit_days, credit_limit_subunit
               FROM business_customers WHERE user_id = :user_id',
            [':user_id' => $userId]
        );
        if ($business === null) { return null; }
        return self::facility($business, self::summaryForBusiness($business, $now));
    }

    /** One facility shape from a business row and its journal snapshot. */
    public static function facility(array $business, array $summary): array
    {
        return $summary + [
            'business_id'   => (int) ($business['id'] ?? 0),
            'business_name' => (string) ($business['business_name'] ?? ''),
            'days'          => (int) ($business['credit_days'] ?? 0),
        ];
    }

    /**
     * The one rule that decides whether an amount may go on account. It is pure,
     * so ordinary checkout, Kitchen Run conversion and the locked draw all reach
     * the same answer. An amount exactly equal to the available credit passes.
     *
     * @return string '' when the draw is allowed, otherwise the refusal code.
     */
    public static function drawRefusal(?array $facility, int $amount): string
    {
        if ($amount < 1) { return 'invalid_charge'; }
        if ($facility === null) { return 'credit_not_approved'; }
        $days = (int) ($facility['days'] ?? 0);
        if ((string) ($facility['state'] ?? '') !== 'approved' || $days < 7 || $days > 10) {
            return 'credit_not_approved';
        }
        if ($amount > (int) ($facility['available_subunit'] ?? 0)) {
            return 'credit_limit_exceeded';
        }
        return '';
    }

    /** True when this amount may go on account against this facility. */
    public static function mayDraw(?array $facility, int $amount): bool
    {
        return self::drawRefusal($facility, $amount) === '';
    }

    /** The day a charge raised for this delivery falls due, on the approved term. */
    public static function dueDateFor(string $deliveryDate, int $days): string
    {
        return (new DateTimeImmutable($deliveryDate, new DateTimeZone(self::TZ)))
            ->modify('+' . max(0, $days) . ' days')
            ->format('Y-m-d');
    }

    public static function statement(int $userId, string $type, int $page): array
    {
        $type = in_array($type, self::TRANSACTION_TYPES, true) ? $type : '';
        $where = ['bc.user_id = :user_id']; $params = [':user_id' => $userId];
        if ($type !== '') { $where[] = 'ct.transaction_type = :type'; $params[':type'] = $type; }
        $clause = implode(' AND ', $where);
        $count = (int) (Database::one('SELECT COUNT(*) AS total FROM credit_transactions ct JOIN business_customers bc ON bc.id = ct.business_customer_id WHERE ' . $clause, $params)['total'] ?? 0);
        $lastPage = max(1, (int) ceil($count / self::PER_PAGE)); $page = min(max(1, $page), $lastPage); $offset = ($page - 1) * self::PER_PAGE;
        $stmt = Database::getInstance()->getConnection()->prepare(
            'SELECT ct.id, ct.transaction_type, ct.amount_subunit, ct.due_date, ct.paid_at, ct.status, ct.created_at,
                    ct.order_id, ct.payment_id, o.order_number, p.order_id AS payment_order_id
               FROM credit_transactions ct
               JOIN business_customers bc ON bc.id = ct.business_customer_id
               LEFT JOIN orders o ON o.id = ct.order_id
               LEFT JOIN payments p ON p.id = ct.payment_id
              WHERE ' . $clause . ' ORDER BY ct.created_at DESC, ct.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
        $stmt->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        return ['transactions' => $stmt->fetchAll(), 'count' => $count, 'page' => $page, 'last_page' => $lastPage, 'type' => $type];
    }

    public static function mayApply(?array $account): bool
    {
        if ($account === null) { return false; }
        $state = (string) ($account['business']['credit_status'] ?? 'not_requested');
        $application = $account['application'] ?? null;
        return !in_array($state, ['approved', 'suspended'], true)
            && ($application === null || (string) $application['status'] !== 'pending');
    }

    public static function adminApplications(string $search, string $status, int $page): array
    {
        $search = mb_substr(trim($search), 0, 100);
        $status = in_array($status, self::APPLICATION_STATES, true) ? $status : '';
        $where=[];$params=[];
        if($status!==''){$where[]='ca.status = :status';$params[':status']=$status;}
        if($search!==''){$where[]='(bc.business_name LIKE :business OR u.email LIKE :email)';$like='%'.$search.'%';$params[':business']=$like;$params[':email']=$like;}
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $count=(int)(Database::one('SELECT COUNT(*) AS total FROM credit_applications ca JOIN business_customers bc ON bc.id=ca.business_customer_id JOIN users u ON u.id=bc.user_id'.$clause,$params)['total']??0);
        $last=max(1,(int)ceil($count/self::PER_PAGE));$page=min(max(1,$page),$last);$offset=($page-1)*self::PER_PAGE;
        $stmt=Database::getInstance()->getConnection()->prepare('SELECT ca.*,bc.business_name,bc.credit_status,bc.credit_limit_subunit,bc.credit_days,u.id AS user_id,u.email FROM credit_applications ca JOIN business_customers bc ON bc.id=ca.business_customer_id JOIN users u ON u.id=bc.user_id'.$clause.' ORDER BY (ca.status = \'pending\') DESC,ca.id DESC LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value){$stmt->bindValue($key,$value);}$stmt->bindValue(':limit',self::PER_PAGE,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();
        return ['applications'=>$stmt->fetchAll(),'count'=>$count,'page'=>$page,'last_page'=>$last,'search'=>$search,'status'=>$status];
    }

    public static function applicationForAdmin(int $id): ?array
    {
        return Database::one('SELECT ca.*,bc.business_name,bc.business_type,bc.credit_status,bc.credit_limit_subunit,bc.credit_days,u.id AS user_id,u.email,u.phone FROM credit_applications ca JOIN business_customers bc ON bc.id=ca.business_customer_id JOIN users u ON u.id=bc.user_id WHERE ca.id=:id',[':id'=>$id]);
    }

    public static function approveApplication(int $id,int $staffId,$days,$limitNaira): array
    {
        [$days,$limit]=self::facilityValues($days,$limitNaira);$pdo=Database::getInstance()->getConnection();$pdo->beginTransaction();
        try{$app=Database::one('SELECT * FROM credit_applications WHERE id=:id FOR UPDATE',[':id'=>$id]);if(!$app){throw new DomainException('not_found');}
            if($app['status']==='approved'){$bc=Database::one('SELECT credit_days,credit_limit_subunit FROM business_customers WHERE id=:id FOR UPDATE',[':id'=>$app['business_customer_id']]);if((int)$bc['credit_days']===$days&&(int)$bc['credit_limit_subunit']===$limit){$pdo->commit();return ['id'=>$id,'already'=>true];}throw new DomainException('conflicting_review');}
            if($app['status']!=='pending'){throw new DomainException('closed_application');}
            Database::run('UPDATE credit_applications SET status=:status,decision_reason=NULL,reviewed_by=:staff,reviewed_at=NOW() WHERE id=:id',[':status'=>'approved',':staff'=>$staffId,':id'=>$id]);
            Database::run('UPDATE business_customers SET credit_status=:status,credit_requested=:requested,credit_days=:days,credit_limit_subunit=:credit_limit WHERE id=:id',[':status'=>'approved',':requested'=>1,':days'=>$days,':credit_limit'=>$limit,':id'=>$app['business_customer_id']]);$pdo->commit();return ['id'=>$id,'already'=>false];
        }catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function declineApplication(int $id,int $staffId,string $reason): array
    {
        $reason=trim($reason);if(mb_strlen($reason)<10||mb_strlen($reason)>1000){throw new DomainException('invalid_decision_reason');}$pdo=Database::getInstance()->getConnection();$pdo->beginTransaction();
        try{$app=Database::one('SELECT * FROM credit_applications WHERE id=:id FOR UPDATE',[':id'=>$id]);if(!$app){throw new DomainException('not_found');}if($app['status']==='declined'){$pdo->commit();return ['id'=>$id,'already'=>true];}if($app['status']!=='pending'){throw new DomainException('closed_application');}
            Database::run('UPDATE credit_applications SET status=:status,decision_reason=:reason,reviewed_by=:staff,reviewed_at=NOW() WHERE id=:id',[':status'=>'declined',':reason'=>$reason,':staff'=>$staffId,':id'=>$id]);Database::run('UPDATE business_customers SET credit_status=:status WHERE id=:id',[':status'=>'declined',':id'=>$app['business_customer_id']]);$pdo->commit();return ['id'=>$id,'already'=>false];
        }catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function grant(int $businessId,int $staffId,$days,$limitNaira): array
    {
        [$days,$limit]=self::facilityValues($days,$limitNaira);$pdo=Database::getInstance()->getConnection();$pdo->beginTransaction();
        try{$bc=Database::one('SELECT * FROM business_customers WHERE id=:id FOR UPDATE',[':id'=>$businessId]);if(!$bc){throw new DomainException('not_found');}if($bc['credit_status']==='approved'&&(int)$bc['credit_days']===$days&&(int)$bc['credit_limit_subunit']===$limit){$pdo->commit();return ['id'=>$businessId,'already'=>true];}
            Database::run('UPDATE business_customers SET credit_status=:status,credit_requested=:requested,credit_days=:days,credit_limit_subunit=:credit_limit WHERE id=:id',[':status'=>'approved',':requested'=>1,':days'=>$days,':credit_limit'=>$limit,':id'=>$businessId]);
            $pending=Database::one('SELECT id FROM credit_applications WHERE business_customer_id=:business AND status=:pending ORDER BY id DESC LIMIT 1 FOR UPDATE',[':business'=>$businessId,':pending'=>'pending']);if($pending){Database::run('UPDATE credit_applications SET status=:status,decision_reason=NULL,reviewed_by=:staff,reviewed_at=NOW() WHERE id=:id',[':status'=>'approved',':staff'=>$staffId,':id'=>$pending['id']]);}$pdo->commit();return ['id'=>$businessId,'already'=>false];
        }catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function changeTerms(int $businessId,$days,$limitNaira): void
    {
        [$days,$limit]=self::facilityValues($days,$limitNaira);$changed=Database::run('UPDATE business_customers SET credit_days=:days,credit_limit_subunit=:credit_limit WHERE id=:id AND credit_status IN (:approved,:suspended)',[':days'=>$days,':credit_limit'=>$limit,':id'=>$businessId,':approved'=>'approved',':suspended'=>'suspended']);if($changed<1&&!Database::one('SELECT id FROM business_customers WHERE id=:id AND credit_status IN (:approved,:suspended)',[':id'=>$businessId,':approved'=>'approved',':suspended'=>'suspended'])){throw new DomainException('not_found');}
    }

    public static function transitionFacility(int $businessId,string $target): void
    {
        $allowed=['approved'=>['suspended','withdrawn'],'suspended'=>['approved','withdrawn'],'withdrawn'=>[]];$pdo=Database::getInstance()->getConnection();$pdo->beginTransaction();try{$bc=Database::one('SELECT credit_status FROM business_customers WHERE id=:id FOR UPDATE',[':id'=>$businessId]);if(!$bc){throw new DomainException('not_found');}$from=(string)$bc['credit_status'];if($from===$target){$pdo->commit();return;}if(!in_array($target,$allowed[$from]??[],true)){throw new DomainException('invalid_transition');}Database::run('UPDATE business_customers SET credit_status=:target WHERE id=:id',[':target'=>$target,':id'=>$businessId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function recordRepayment(int $businessId,int $paymentId): array
    {
        $pdo=Database::getInstance()->getConnection();$pdo->beginTransaction();try{$bc=Database::one('SELECT id,user_id FROM business_customers WHERE id=:id FOR UPDATE',[':id'=>$businessId]);if(!$bc){throw new DomainException('not_found');}$payment=Database::one('SELECT p.id,p.paid_amount_subunit,p.refunded_amount_subunit,p.confirmed_at FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=:payment AND o.user_id=:user FOR UPDATE',[':payment'=>$paymentId,':user'=>$bc['user_id']]);if(!$payment||$payment['confirmed_at']===null){throw new DomainException('invalid_repayment');}if(Database::one('SELECT id FROM credit_transactions WHERE payment_id=:payment LIMIT 1',[':payment'=>$paymentId])){throw new DomainException('repayment_recorded');}$amount=max(0,(int)$payment['paid_amount_subunit']-(int)$payment['refunded_amount_subunit']);$summary=self::summaryForBusiness($bc);if($amount<1||$amount>(int)$summary['outstanding_subunit']){throw new DomainException('invalid_repayment');}Database::run('INSERT INTO credit_transactions(business_customer_id,payment_id,transaction_type,amount_subunit,paid_at,status)VALUES(:business,:payment,:type,:amount,NOW(),:status)',[':business'=>$businessId,':payment'=>$paymentId,':type'=>'repayment',':amount'=>-$amount,':status'=>'posted']);$id=(int)$pdo->lastInsertId();$pdo->commit();return ['id'=>$id,'amount_subunit'=>$amount];}catch(Throwable $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    }

    public static function ageing(): array
    {
        $rows=Database::all('SELECT bc.id,bc.business_name,bc.user_id,bc.credit_status,bc.credit_limit_subunit,bc.credit_days FROM business_customers bc WHERE bc.credit_status IN (:approved,:suspended,:withdrawn) ORDER BY bc.business_name',[':approved'=>'approved',':suspended'=>'suspended',':withdrawn'=>'withdrawn']);foreach($rows as &$row){$row['summary']=self::summaryForBusiness($row);}unset($row);return $rows;
    }

    /**
     * Reserve available credit and append exactly one charge, inside the
     * caller's transaction.
     *
     * Concurrency: the business row is locked first, so two orders for the same
     * business queue behind one another. The journal is then read with a
     * locking read, because a plain read under REPEATABLE READ would answer
     * from the snapshot this request opened and could miss a charge the other
     * order has just committed. The unique source_key is the last backstop: a
     * retry of the same order can never write a second charge.
     *
     * The charge is the order's balance due, so an amount already settled at
     * placement is never put on account twice.
     */
    public static function drawForOrder(int $userId,int $orderId,int $amount,string $deliveryDate): array
    {
        $pdo = Database::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('credit draw called outside a transaction');
        }

        $key = 'order:' . $orderId . ':charge';
        $existing = Database::one('SELECT id, due_date FROM credit_transactions WHERE source_key = :key', [':key' => $key]);
        if ($existing) {
            return ['id' => (int) $existing['id'], 'due_date' => $existing['due_date'], 'already' => true];
        }

        $order = Database::one(
            'SELECT payment_option, balance_due_subunit FROM orders WHERE id = :id FOR UPDATE',
            [':id' => $orderId]
        );
        if ($order !== null) {
            if ((string) $order['payment_option'] !== 'on_account') {
                throw new DomainException('invalid_charge');
            }
            $amount = (int) $order['balance_due_subunit'];
        }

        $business = Database::one(
            'SELECT id, business_name, credit_status, credit_days, credit_limit_subunit
               FROM business_customers WHERE user_id = :user FOR UPDATE',
            [':user' => $userId]
        );
        $facility = $business === null ? null : self::facility($business, self::lockedSummary($business));

        $refusal = self::drawRefusal($facility, $amount);
        if ($refusal !== '') {
            throw new DomainException($refusal);
        }

        $due = self::dueDateFor($deliveryDate, (int) $facility['days']);
        Database::run(
            'INSERT INTO credit_transactions
                (business_customer_id, order_id, transaction_type, source_key, amount_subunit, due_date, status)
             VALUES (:business, :order, :type, :key, :amount, :due, :status)',
            [':business' => $facility['business_id'], ':order' => $orderId, ':type' => 'charge',
             ':key' => $key, ':amount' => $amount, ':due' => $due, ':status' => 'posted']
        );
        return ['id' => (int) $pdo->lastInsertId(), 'due_date' => $due, 'already' => false, 'amount_subunit' => $amount];
    }

    /**
     * The journal snapshot read under a shared lock, for use inside a draw. A
     * locking read always sees the latest committed rows, which a plain read in
     * the same transaction may not.
     */
    private static function lockedSummary(array $business): array
    {
        $entries = Database::all(
            'SELECT amount_subunit, due_date FROM credit_transactions
              WHERE business_customer_id = :business_id
              ORDER BY (due_date IS NULL), due_date, id
              FOR SHARE',
            [':business_id' => (int) $business['id']]
        );
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
        return self::snapshotFromTransactions($business, $entries, $today);
    }


    public static function adjustCancelledOrder(int $orderId): void
    {
        self::adjustOrder($orderId,'cancellation',PHP_INT_MAX);
    }

    public static function adjustRefund(int $orderId,int $refundId,int $amount): void
    {
        self::adjustOrder($orderId,'refund:'.$refundId,$amount);
    }

    private static function adjustOrder(int $orderId,string $reason,int $maximum): void
    {
        $order=Database::one('SELECT payment_option FROM orders WHERE id=:id',[':id'=>$orderId]);if(!$order||$order['payment_option']!=='on_account'){return;}$key='order:'.$orderId.':'.$reason;if(Database::one('SELECT id FROM credit_transactions WHERE source_key=:key',[':key'=>$key])){return;}$charge=Database::one('SELECT business_customer_id FROM credit_transactions WHERE order_id=:order AND transaction_type=:type ORDER BY id LIMIT 1',[':order'=>$orderId,':type'=>'charge']);if(!$charge){return;}$net=(int)(Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS total FROM credit_transactions WHERE order_id=:order',[':order'=>$orderId])['total']??0);$amount=min(max(0,$net),$maximum);if($amount<1){return;}Database::run('INSERT INTO credit_transactions(business_customer_id,order_id,transaction_type,source_key,amount_subunit,status)VALUES(:business,:order,:type,:key,:amount,:status)',[':business'=>$charge['business_customer_id'],':order'=>$orderId,':type'=>'adjustment',':key'=>$key,':amount'=>-$amount,':status'=>'posted']);
    }

    private static function facilityValues($days,$limitNaira): array
    {
        $d=trim((string)$days);if(preg_match('/^\d+$/',$d)!==1||(int)$d<7||(int)$d>10){throw new DomainException('invalid_days');}$limit=self::nairaToSubunit($limitNaira);if($limit===null||$limit<1){throw new DomainException('invalid_limit');}return [(int)$d,$limit];
    }

    public static function message(string $code): string
    {
        return [
            'invalid_days' => 'Choose credit terms from 7 to 10 days.',
            'invalid_limit' => 'Enter a credit limit greater than ₦0.',
            'invalid_reason' => 'Tell us why this credit will help your kitchen, using 20 to 1,000 characters.',
            'active_application' => 'A credit application is already waiting for review.',
            'active_credit' => 'This account already has an active or suspended credit facility.',
            'not_found' => 'This business credit account is not available.',
            'invalid_decision_reason' => 'Give the customer a reason using 10 to 1,000 characters.',
            'conflicting_review' => 'This application was already approved with different terms.',
            'closed_application' => 'This application has already been decided.',
            'invalid_transition' => 'That credit facility cannot move to the chosen state.',
            'invalid_repayment' => 'Choose a confirmed payment that does not exceed the outstanding credit.',
            'repayment_recorded' => 'That payment has already been credited to this account.',
            'credit_not_approved' => 'This business does not have approved credit for this order.',
            'credit_limit_exceeded' => 'This order is above the credit available on this account.',
            'invalid_charge' => 'This order has no amount to place on account.',
        ][$code] ?? 'We could not save that credit application. Please try again.';
    }

    private static function nairaToSubunit($value): ?int
    {
        $text = str_replace([',', ' ', "\u{20A6}"], '', trim((string) $value));
        if ($text === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $text) !== 1) { return null; }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $maxWhole = (string) intdiv(PHP_INT_MAX - 99, Money::SUBUNITS);
        if (strlen($whole) > strlen($maxWhole) || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            return null;
        }
        $kobo = $fraction === '' ? 0 : (int) str_pad($fraction, 2, '0');
        return ((int) $whole * Money::SUBUNITS) + $kobo;
    }
}

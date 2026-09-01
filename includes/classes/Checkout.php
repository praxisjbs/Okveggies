<?php
/** A fixed code plus copy that has already been approved for customer display. */
final class CheckoutException extends DomainException
{
    public function __construct(string $code,private string $customerMessage){parent::__construct($code);}
    public function customerMessage(): string { return $this->customerMessage; }
}

/** Checkout snapshots and transactional order placement. */
final class Checkout
{
    private const SESSION_KEY='okv_checkout';
    public static function total(array $lines): int { $total=0; foreach($lines as $line)$total+=Money::lineTotal($line['quantity'],(int)$line['unit_price_subunit']); return $total; }
    public static function paymentAllowed(string $option,string $type,bool $activated): bool { if($option==='pay_on_delivery')return $activated; if($option==='on_account')return $type==='business'; return in_array($option,['pay_in_full','deposit'],true); }
    public static function snapshot(array $basket): array { return ['lines'=>$basket['lines']??[],'subtotal_subunit'=>self::total($basket['lines']??[]),'at'=>time()]; }
    public static function bag(): array { $bag=$_SESSION[self::SESSION_KEY]??[]; if(!is_array($bag)||time()-(int)($bag['at']??0)>7200){unset($_SESSION[self::SESSION_KEY]);return [];} return $bag; }
    public static function saveStep(string $step,array $data): array { $bag=self::bag();$bag[$step]=$data;$bag['at']=time();$_SESSION[self::SESSION_KEY]=$bag;return $bag; }
    public static function amountDue(string $option,int $total,float $percentage): int { return $option==='deposit' ? Money::deposit($total,$percentage) : $total; }
    public static function placedMatchesBasket(array $bag,?int $cartId): bool { return !empty($bag['placed'])&&($cartId===null||(int)($bag['placed_cart_id']??0)===$cartId); }
    public static function place(array $input): array
    {
        $bag=self::bag();$basket=Basket::state();$cartId=$basket['cart_id']===null?null:(int)$basket['cart_id'];if(self::placedMatchesBasket($bag,$cartId))return $bag['placed'];if($cartId===null||!$basket['lines'])throw new DomainException('empty_cart');
        $userId=(int)($input['user_id']??0); $type=(string)($input['customer_type']??'household'); $option=(string)($input['payment_option']??'');
        $required=['recipient_name','recipient_phone','address_line_1','city','state'];foreach($required as $field){if(trim((string)($input[$field]??''))==='')throw new DomainException('bad_customer');}
        if($userId<1||!in_array($type,['household','business'],true))throw new DomainException('bad_customer');
        $phone=Phone::normalize((string)$input['recipient_phone']);if($phone===null)throw new DomainException('bad_customer');$input['recipient_phone']=$phone;
        if(!self::paymentAllowed($option,$type,!empty($input['activated'])))throw new DomainException('payment_not_allowed');
        if($option==='on_account'){
            $credit=Database::one('SELECT credit_status FROM business_customers WHERE user_id=:id',[':id'=>$userId]);
            if(!$credit||$credit['credit_status']!=='approved')throw new DomainException('credit_not_approved');
        }
        $eligibility=Delivery::isEligible((string)$input['delivery_date'],$type); if(empty($eligibility['eligible']))throw new CheckoutException('delivery_unavailable',(string)$eligibility['reason']);
        if(!Database::one('SELECT id FROM delivery_zones WHERE id=:id AND is_active=1',[':id'=>(int)$input['delivery_zone_id']]))throw new DomainException('zone_unavailable');
        $pdo=Database::getInstance()->getConnection(); $pdo->beginTransaction();
        try{
            $cart=Database::one('SELECT id,status FROM shopping_carts WHERE id=:id FOR UPDATE',[':id'=>$cartId]);
            if(!$cart)throw new DomainException('empty_cart');
            if($cart['status']!=='active'){
                $existing=Database::one('SELECT id,order_number FROM orders WHERE shopping_cart_id=:cart LIMIT 1',[':cart'=>$cartId]);
                if(!$existing)throw new DomainException('cart_converted');
                $pdo->commit();
                $result=self::placedResult((int)$existing['id'],(string)$existing['order_number'],'');
                $bag['placed']=$result;$bag['placed_cart_id']=$cartId;$bag['at']=time();$_SESSION[self::SESSION_KEY]=$bag;return $result;
            }
            Database::all('SELECT id FROM cart_items WHERE cart_id=:cart_id FOR UPDATE',[':cart_id'=>$cartId]);
            $basket=Basket::state();if(!$basket['lines'])throw new DomainException('empty_cart');$snapshot=self::snapshot($basket);
            $total=(int)$snapshot['subtotal_subunit']; $percentage=Settings::depositPercentage(); $deposit=$option==='deposit'?Money::deposit($total,$percentage):null; $due=self::amountDue($option,$total,$percentage);
            $orderNumber=OrderNumber::nextOrderNumber($pdo,Settings::str('order_number_prefix','OKV')); $token='';
            for($attempt=0;$attempt<5;$attempt++){ $candidate=OrderTrail::newToken(); if(!Database::one('SELECT id FROM orders WHERE order_trail_token_hash=:h',[':h'=>OrderTrail::hashToken($candidate)])){ $token=$candidate; break; } }
            if($token==='')throw new RuntimeException('trail_token_collision');
            Database::run('INSERT INTO orders (order_number,order_trail_token_hash,user_id,shopping_cart_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,deposit_percentage,deposit_required_subunit,balance_due_subunit,preferred_delivery_date,delivery_zone_id,delivery_fee_note,created_by) VALUES (:number,:token,:user_id,:cart_id,:type,\'pending\',:option,\'unpaid\',:subtotal,:order_total,:percentage,:deposit,:balance,:date,:zone,:fee,:created_by)',[':number'=>$orderNumber,':token'=>OrderTrail::hashToken($token),':user_id'=>$userId,':cart_id'=>$cartId,':type'=>$type,':option'=>$option,':subtotal'=>$total,':order_total'=>$total,':percentage'=>$option==='deposit'?$percentage:null,':deposit'=>$deposit,':balance'=>Money::balance($total,0),':date'=>$input['delivery_date'],':zone'=>(int)$input['delivery_zone_id'],':fee'=>'Delivery fee is arranged and settled separately after we confirm your area.',':created_by'=>$userId]);
            $orderId=(int)$pdo->lastInsertId();
            if(isset($input['failure_hook'])&&is_callable($input['failure_hook']))$input['failure_hook']('after_order');
            Database::run('INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,address_line_2,city,state,landmark) VALUES (:order,:name,:phone,:line1,:line2,:city,:state,:landmark)',[':order'=>$orderId,':name'=>$input['recipient_name'],':phone'=>$input['recipient_phone'],':line1'=>$input['address_line_1'],':line2'=>$input['address_line_2']??null,':city'=>$input['city'],':state'=>$input['state'],':landmark'=>$input['landmark']??null]);
            $address=Database::one('SELECT id FROM customer_addresses WHERE user_id=:user_id AND recipient_name=:name AND recipient_phone=:phone AND address_line_1=:line1 AND city=:city AND state=:state LIMIT 1',[':user_id'=>$userId,':name'=>$input['recipient_name'],':phone'=>$input['recipient_phone'],':line1'=>$input['address_line_1'],':city'=>$input['city'],':state'=>$input['state']]);
            if(!$address){
                $default=Database::one('SELECT id FROM customer_addresses WHERE user_id=:user_id AND is_default=1 LIMIT 1',[':user_id'=>$userId]);
                Database::run('INSERT INTO customer_addresses (user_id,label,recipient_name,recipient_phone,address_line_1,address_line_2,city,state,landmark,is_default) VALUES (:user_id,\'Delivery\',:name,:phone,:line1,:line2,:city,:state,:landmark,:is_default)',[':user_id'=>$userId,':name'=>$input['recipient_name'],':phone'=>$input['recipient_phone'],':line1'=>$input['address_line_1'],':line2'=>$input['address_line_2']??null,':city'=>$input['city'],':state'=>$input['state'],':landmark'=>$input['landmark']??null,':is_default'=>$default?0:1]);
            }
            foreach($basket['lines'] as $line){
                $isCombo=$line['item_type']==='combo'; $source=$isCombo?Database::one('SELECT name,sku FROM combo_packages WHERE id=:id',[':id'=>$line['combo_package_id']]):Database::one('SELECT p.name,p.sku,u.name unit_name FROM products p JOIN units_of_measurement u ON u.id=p.unit_id WHERE p.id=:id',[':id'=>$line['product_id']]);
                Database::run('INSERT INTO order_items (order_id,item_type,product_id,combo_package_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit) VALUES (:order,:type,:product,:combo,:name,:sku,:unit,:quantity,:price,:line_total)',[':order'=>$orderId,':type'=>$line['item_type'],':product'=>$line['product_id'],':combo'=>$line['combo_package_id'],':name'=>$source['name'],':sku'=>$source['sku'],':unit'=>$isCombo?'basket':$source['unit_name'],':quantity'=>$line['quantity'],':price'=>$line['unit_price_subunit'],':line_total'=>$line['line_total_subunit']]);
                $orderItemId=(int)$pdo->lastInsertId(); if($isCombo){foreach(Database::all('SELECT ci.product_id,p.name product_name,ci.quantity,u.name unit_name FROM combo_package_items ci JOIN products p ON p.id=ci.product_id JOIN units_of_measurement u ON u.id=ci.unit_id WHERE ci.combo_package_id=:id ORDER BY ci.id',[':id'=>$line['combo_package_id']]) as $component)Database::run('INSERT INTO order_item_components (order_item_id,product_id,product_name,quantity,unit_name) VALUES (:item,:product,:name,:quantity,:unit)',[':item'=>$orderItemId,':product'=>$component['product_id'],':name'=>$component['product_name'],':quantity'=>$component['quantity'],':unit'=>$component['unit_name']]);}
            }
            Database::run('INSERT INTO order_status_history (order_id,old_status,new_status,source,changed_by) VALUES (:order,NULL,\'pending\',\'customer\',:user)',[':order'=>$orderId,':user'=>$userId]);
            Database::run('INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,currency,status) VALUES (:number,:user,:order,:provider,:type,:amount,:currency,\'unpaid\')',[':number'=>'PAY-'.$orderNumber,':user'=>$userId,':order'=>$orderId,':provider'=>$option==='on_account'?'account':($option==='pay_on_delivery'?'manual':'paystack'),':type'=>$option,':amount'=>$due,':currency'=>Money::CODE]);
            Database::run('UPDATE shopping_carts SET status=\'converted\' WHERE id=:id',[':id'=>$cartId]); $pdo->commit();
            $result=self::placedResult($orderId,$orderNumber,$token); $bag['placed']=$result;$bag['placed_cart_id']=$cartId;$bag['at']=time();$_SESSION[self::SESSION_KEY]=$bag;return $result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    private static function placedResult(int $orderId,string $orderNumber,string $token): array
    {
        return ['order_id'=>$orderId,'order_number'=>$orderNumber,'trail_token'=>$token,'confirmation_url'=>'/public/order.php?order='.$orderId,'trail_url'=>$token===''?'':'/public/order.php?token='.rawurlencode($token)];
    }
}

<?php
/** Checkout transaction, token, payment and ownership checks on a scratch MariaDB. */
$root=dirname(__DIR__,2);require_once $root.'/includes/bootstrap.php';
$GLOBALS['t']=0;$GLOBALS['p']=0;$GLOBALS['f']=[];
function t_ok($condition,string $label):void{$GLOBALS['t']++;if($condition){$GLOBALS['p']++;return;}$GLOBALS['f'][]=$label;fwrite(STDERR,"  FAIL: $label\n");}
function t_eq($expected,$actual,string $label):void{t_ok($expected===$actual,$label.' (expected '.var_export($expected,true).', got '.var_export($actual,true).')');}

$pdo=Database::getInstance()->getConnection();
$column=Database::one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders' AND COLUMN_NAME='order_trail_token_hash'");
if(!$column){fwrite(STDERR,"Run migrations through 010 before this test.\n");exit(2);}
$suffix=bin2hex(random_bytes(5));$userIds=[];$orderIds=[];$cartIds=[];
$cleanup=function()use(&$userIds,&$orderIds,&$cartIds):void{
    if($orderIds){$marks=implode(',',array_fill(0,count($orderIds),'?'));$componentIds=Database::all("SELECT id FROM order_items WHERE order_id IN ($marks)",$orderIds);$ids=array_map(static fn($row)=>(int)$row['id'],$componentIds);if($ids){$itemMarks=implode(',',array_fill(0,count($ids),'?'));$pdo=Database::getInstance()->getConnection();$pdo->prepare("DELETE FROM order_item_components WHERE order_item_id IN ($itemMarks)")->execute($ids);}$pdo=Database::getInstance()->getConnection();foreach(['payments','order_status_history','order_addresses','order_items'] as $table)$pdo->prepare("DELETE FROM $table WHERE order_id IN ($marks)")->execute($orderIds);$pdo->prepare("DELETE FROM orders WHERE id IN ($marks)")->execute($orderIds);}
    if($cartIds){$marks=implode(',',array_fill(0,count($cartIds),'?'));$pdo=Database::getInstance()->getConnection();$pdo->prepare("DELETE FROM cart_items WHERE cart_id IN ($marks)")->execute($cartIds);$pdo->prepare("DELETE FROM shopping_carts WHERE id IN ($marks)")->execute($cartIds);}
    if($userIds){$marks=implode(',',array_fill(0,count($userIds),'?'));$pdo=Database::getInstance()->getConnection();$pdo->prepare("DELETE FROM customer_addresses WHERE user_id IN ($marks)")->execute($userIds);$pdo->prepare("DELETE FROM business_customers WHERE user_id IN ($marks)")->execute($userIds);$pdo->prepare("DELETE FROM users WHERE id IN ($marks)")->execute($userIds);}
};
$makeUser=function(string $name)use($pdo,$suffix,&$userIds):int{$email=strtolower($name).'.'.$suffix.'@example.test';$pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status,email_verified_at) VALUES (?, 'Test', ?, ?, ?, 'household', 'active', NOW())")->execute([$name,$email,'+23480'.substr(hash('crc32b',$email),0,8),Password::hash('checkout-test-password')]);$id=(int)$pdo->lastInsertId();$userIds[]=$id;return $id;};
$useUser=function(int $id,string $type='household'):void{$_SESSION=['user_id'=>$id,'user_type'=>$type,'email_verified'=>true,'first_name'=>'Checkout'];};
$input=function(int $id,string $payment='deposit',string $type='household'):array{$dates=Delivery::nextEligibleDates($type,1);$zone=Delivery::zonesActive()[0]??null;if(!$dates||!$zone)throw new RuntimeException('delivery_fixture_missing');return ['user_id'=>$id,'customer_type'=>$type,'activated'=>true,'recipient_name'=>'Checkout Test','recipient_phone'=>'08012345678','address_line_1'=>'1 Test Street','address_line_2'=>'','city'=>'Lagos','state'=>'Lagos','landmark'=>'Test gate','delivery_date'=>$dates[0]['date'],'delivery_zone_id'=>(int)$zone['id'],'payment_option'=>$payment];};
try{
    $first=$makeUser('First');$useUser($first);
    $product=Database::one("SELECT p.id FROM products p LEFT JOIN product_availability pa ON pa.product_id=p.id WHERE p.is_active=1 AND p.current_price_subunit>0 AND COALESCE(pa.availability_status,'available')='available' ORDER BY p.id LIMIT 1");
    $combo=Database::one('SELECT id FROM combo_packages WHERE is_active=1 AND price_subunit>0 ORDER BY id LIMIT 1');
    if(!$product||!$combo)throw new RuntimeException('catalogue_fixture_missing');
    Basket::addProduct((int)$product['id']);Basket::addCombo((int)$combo['id']);$before=Basket::state();$cartIds[]=(int)$before['cart_id'];
    $placed=Checkout::place($input($first));$orderIds[]=(int)$placed['order_id'];
    $order=Database::one('SELECT * FROM orders WHERE id=:id',[':id'=>$placed['order_id']]);
    t_eq((int)$before['subtotal_subunit'],(int)$order['order_total_subunit'],'order total keeps the basket price snapshots');
    t_eq('converted',Database::one('SELECT status FROM shopping_carts WHERE id=:id',[':id'=>$before['cart_id']])['status'],'basket converts after placement');
    t_eq(2,(int)Database::one('SELECT COUNT(*) total FROM order_items WHERE order_id=:id',[':id'=>$placed['order_id']])['total'],'product and combo become separate order lines');
    t_ok((int)Database::one("SELECT COUNT(*) total FROM order_item_components c JOIN order_items i ON i.id=c.order_item_id WHERE i.order_id=:id AND i.item_type='combo'",[':id'=>$placed['order_id']])['total']>0,'combo components are copied into immutable snapshots');
    $expected=Money::deposit((int)$order['order_total_subunit'],Settings::depositPercentage());$payment=Database::one('SELECT expected_amount_subunit,status FROM payments WHERE order_id=:id',[':id'=>$placed['order_id']]);
    t_eq($expected,(int)$payment['expected_amount_subunit'],'pending deposit amount uses the global setting');t_eq('unpaid',$payment['status'],'new payment is not marked successful');
    t_ok(OrderTrail::findByToken($placed['trail_token'])!==null,'valid trail token finds the order');t_ok(OrderTrail::findByToken('not-a-token')===null,'malformed trail token finds nothing');
    $second=$makeUser('Second');t_ok(OrderTrail::findForCustomer((int)$placed['order_id'],$second)===null,'another customer cannot open the signed-in confirmation');
    $same=Checkout::place($input($first));t_eq((int)$placed['order_id'],(int)$same['order_id'],'retry in the same signed session is idempotent');

    $useUser($second);$secondInput=$input($second,'pay_in_full');Basket::addProduct((int)$product['id']);$secondBasket=Basket::state();$cartIds[]=(int)$secondBasket['cart_id'];$failed=false;
    $secondInput['failure_hook']=static function(string $point):void{if($point==='after_order')throw new RuntimeException('injected_checkout_failure');};
    try{Checkout::place($secondInput);}catch(RuntimeException $e){$failed=$e->getMessage()==='injected_checkout_failure';}
    t_ok($failed,'injected failure leaves the checkout through the rollback path');
    t_eq(0,(int)Database::one('SELECT COUNT(*) total FROM orders WHERE shopping_cart_id=:cart',[':cart'=>$secondBasket['cart_id']])['total'],'rollback removes the partly created order');
    t_eq('active',Database::one('SELECT status FROM shopping_carts WHERE id=:id',[':id'=>$secondBasket['cart_id']])['status'],'rollback leaves the basket active');
    unset($secondInput['failure_hook']);$placedSecond=Checkout::place($secondInput);$orderIds[]=(int)$placedSecond['order_id'];
    t_ok($placedSecond['order_number']!==$placed['order_number'],'sequential placements receive unique order numbers');
    t_eq(1,(int)Database::one('SELECT COUNT(*) total FROM orders WHERE shopping_cart_id=:cart',[':cart'=>$secondBasket['cart_id']])['total'],'one converted basket maps to one order');

    $business=$makeUser('Business');Database::run("UPDATE users SET user_type='business' WHERE id=:id",[':id'=>$business]);Database::run("INSERT INTO business_customers (user_id,business_name,contact_person,credit_status) VALUES (:id,'Checkout Kitchen','Checkout Test','requested')",[':id'=>$business]);$useUser($business,'business');Basket::addProduct((int)$product['id']);$businessBasket=Basket::state();$cartIds[]=(int)$businessBasket['cart_id'];$refused=false;
    try{Checkout::place($input($business,'on_account','business'));}catch(DomainException $e){$refused=$e->getMessage()==='credit_not_approved';}
    t_ok($refused,'unapproved business credit is refused');t_eq('active',Database::one('SELECT status FROM shopping_carts WHERE id=:id',[':id'=>$businessBasket['cart_id']])['status'],'credit refusal leaves the business basket active');
    Database::run("UPDATE business_customers SET credit_status='approved' WHERE user_id=:id",[':id'=>$business]);$businessPlaced=Checkout::place($input($business,'on_account','business'));$orderIds[]=(int)$businessPlaced['order_id'];$businessPayment=Database::one('SELECT provider,status FROM payments WHERE order_id=:id',[':id'=>$businessPlaced['order_id']]);
    t_eq('account',$businessPayment['provider'],'approved business credit creates an account payment obligation');t_eq('unpaid',$businessPayment['status'],'business credit is not marked paid at order placement');
}finally{$cleanup();}
$t=$GLOBALS['t'];$p=$GLOBALS['p'];fwrite(STDOUT,"\n$p / $t assertions passed.\n");if($p!==$t){fwrite(STDERR,count($GLOBALS['f'])." failed.\n");exit(1);}fwrite(STDOUT,"All green.\n");

<?php
/** Guest basket merge through the real sign-in session hook on a scratch database. */
$root=dirname(__DIR__,2);require_once $root.'/includes/bootstrap.php';
$tests=0;$passed=0;$check=static function($condition,string $label)use(&$tests,&$passed):void{$tests++;if($condition){$passed++;return;}fwrite(STDERR,"  FAIL: $label\n");};
$pdo=Database::getInstance()->getConnection();$suffix=bin2hex(random_bytes(5));$email='basket.'.$suffix.'@example.test';$userId=0;$guestCartId=0;
try{
    $_SESSION=[];$product=Database::one("SELECT p.id,p.current_price_subunit,p.minimum_quantity FROM products p LEFT JOIN product_availability pa ON pa.product_id=p.id WHERE p.is_active=1 AND p.current_price_subunit>0 AND COALESCE(pa.availability_status,'available')='available' ORDER BY p.id LIMIT 1");$combo=Database::one('SELECT id FROM combo_packages WHERE is_active=1 AND price_subunit>0 ORDER BY id LIMIT 1');
    Basket::addProduct((int)$product['id']);Basket::addCombo((int)$combo['id']);$guest=Basket::state();$guestCartId=(int)$guest['cart_id'];$check(count($guest['lines'])===2,'guest basket contains a product and combo');
    Database::run("INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status,email_verified_at) VALUES ('Merge','Test',:email,:phone,:password,'household','active',NOW())",[':email'=>$email,':phone'=>'+234801'.substr(preg_replace('/[^0-9]/','',hash('crc32b',$email)),0,7),':password'=>Password::hash('merge-test-password')]);$userId=(int)$pdo->lastInsertId();
    Database::run("INSERT INTO shopping_carts (user_id,status) VALUES (:user_id,'active')",[':user_id'=>$userId]);$accountCartId=(int)$pdo->lastInsertId();Database::run("INSERT INTO cart_items (cart_id,item_type,product_id,quantity,unit_price_subunit) VALUES (:cart,'product',:product,:quantity,:price)",[':cart'=>$accountCartId,':product'=>(int)$product['id'],':quantity'=>$product['minimum_quantity'],':price'=>(int)$product['current_price_subunit']]);
    $user=Database::one('SELECT id,user_type,email_verified_at,first_name FROM users WHERE id=:id',[':id'=>$userId]);Auth::startSession($user);$merged=Basket::state();
    $source=Database::one('SELECT status FROM shopping_carts WHERE id=:id',[':id'=>$guestCartId]);$check($source['status']==='merged','source guest cart is marked merged after sign-in');$check(!isset($_SESSION['okv_basket_token']),'guest basket token is removed from the signed-in session');$check(count($merged['lines'])===2,'merge collision combines the product while preserving the combo');
    $productLines=array_values(array_filter($merged['lines'],static fn($line)=>$line['item_type']==='product'));$comboLines=array_values(array_filter($merged['lines'],static fn($line)=>$line['item_type']==='combo'));$expected=(float)$product['minimum_quantity']*2;
    $check(count($productLines)===1&&(float)$productLines[0]['quantity']===$expected,'matching product and price snapshots merge their quantities');$check(count($comboLines)===1,'non-colliding combo moves into the account basket');
}finally{
    if($userId>0)Database::run('DELETE FROM users WHERE id=:id',[':id'=>$userId]);if($guestCartId>0)Database::run('DELETE FROM shopping_carts WHERE id=:id',[':id'=>$guestCartId]);$_SESSION=[];
}
fwrite(STDOUT,"\n$passed / $tests assertions passed.\n");if($passed!==$tests)exit(1);fwrite(STDOUT,"All green.\n");

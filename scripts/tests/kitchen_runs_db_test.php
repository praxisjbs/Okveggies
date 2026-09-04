<?php
/** M7 persistence contract. Run only against a database built from migrations. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests=0; $passed=0;
function krdb_ok($ok,string $label): void { global $tests,$passed; $tests++; if($ok){$passed++;}else fwrite(STDERR,"  FAIL: $label\n"); }
function krdb_eq($want,$got,string $label): void { krdb_ok($want===$got,$label.($want===$got?'':' (expected '.var_export($want,true).', got '.var_export($got,true).')')); }
function krdb_method(string $name): bool { $ok=method_exists(KitchenRuns::class,$name); krdb_ok($ok,'KitchenRuns exposes database operation '.$name); return $ok; }

$suffix=bin2hex(random_bytes(5)); $users=[]; $requestIds=[]; $orderIds=[]; $staffId=0;
try {
    foreach ([['household','Ada'],['household','Bisi'],['business','Chidi']] as [$type,$name]) {
        Database::run('INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status,email_verified_at) VALUES (:name,\'Kitchen\',:email,:phone,:hash,:type,\'active\',NOW())',[':name'=>$name,':email'=>"kr-$name-$suffix@example.test",':phone'=>'+23473'.random_int(10000000,99999999),':hash'=>password_hash('test-only',PASSWORD_BCRYPT),':type'=>$type]);
        $users[]=(int)Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status) VALUES (\'Staff\',\'Kitchen\',:email,:phone,:hash,\'staff\',\'active\')',[':email'=>"kr-staff-$suffix@example.test",':phone'=>'+23472'.random_int(10000000,99999999),':hash'=>password_hash('test-only',PASSWORD_BCRYPT)]);
    $staffId=(int)Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO business_customers (user_id,business_name,contact_person,credit_status) VALUES (:user,:name,:contact,\'approved\')',[':user'=>$users[2],':name'=>'Kitchen Test '.$suffix,':contact'=>'Chidi Kitchen']);
    $unit=Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1'); $unitId=(int)$unit['id'];
    $zone=Database::one('SELECT id FROM delivery_zones WHERE is_active=1 ORDER BY id LIMIT 1'); $zoneId=(int)$zone['id'];
    $product=Database::one('SELECT id,name FROM products WHERE is_active=1 AND current_price_subunit IS NOT NULL ORDER BY id LIMIT 1');

    if (krdb_method('submit')) {
        $submitted=KitchenRuns::submit($users[0],'household',['input_mode'=>'mixed','pricing_mode'=>'by_us','items'=>[
            ['product_id'=>$product['id'],'item_name'=>$product['name'],'quantity'=>'2.000','unit_id'=>$unitId],
            ['item_name'=>'Pomo','quantity'=>'1.000','unit_id'=>$unitId],
        ],'customer_note'=>'Original customer words']);
        $requestId=(int)($submitted['id']??0); $requestIds[]=$requestId;
        krdb_ok($requestId>0,'mixed catalogue and free-text submission creates one request');
        $request=Database::one('SELECT status,original_submission_json,state_version FROM kitchen_run_requests WHERE id=:id',[':id'=>$requestId]);
        krdb_eq('submitted',(string)($request['status']??''),'a new request starts Submitted');
        krdb_ok(trim((string)($request['original_submission_json']??''))!=='','the original submission is retained for audit');
        $items=Database::all('SELECT product_id,item_name,quantity,unit_id,price_source FROM kitchen_run_items WHERE request_id=:id ORDER BY sort_order,id',[':id'=>$requestId]);
        krdb_eq(2,count($items),'mixed submission preserves both lines without an N+1 reconstruction');
        krdb_eq((int)$product['id'],(int)$items[0]['product_id'],'catalogue line keeps its product link');
        krdb_eq('Pomo',(string)$items[1]['item_name'],'free-text line keeps its customer name');

        if (krdb_method('quote')) {
            $quote=KitchenRuns::quote($requestId,$staffId,(int)$request['state_version'],['items'=>[
                ['product_id'=>$product['id'],'item_name'=>$product['name'],'quantity'=>'2.000','unit_id'=>$unitId,'unit_price_subunit'=>270000],
                ['item_name'=>'Pomo','quantity'=>'1.000','unit_id'=>$unitId,'unit_price_subunit'=>300000],
            ],'deposit_subunit'=>200000,'preferred_delivery_date'=>date('Y-m-d',strtotime('+7 days')),'delivery_zone_id'=>$zoneId]);
            krdb_eq(840000,(int)($quote['total_subunit']??0),'staff quote uses server-calculated exact total');
            $afterQuote=Database::one('SELECT status,quoted_total_subunit,deposit_subunit,state_version FROM kitchen_run_requests WHERE id=:id',[':id'=>$requestId]);
            krdb_eq('quoted',(string)$afterQuote['status'],'quoting changes Submitted to Quoted');
            krdb_eq(840000,(int)$afterQuote['quoted_total_subunit'],'quoted total is stored in kobo');
            krdb_eq(200000,(int)$afterQuote['deposit_subunit'],'staff-set deposit is stored in kobo');

            if (krdb_method('approve')) {
                $approved=KitchenRuns::approve($requestId,$users[0],(int)$afterQuote['state_version']);
                krdb_eq('approved',(string)($approved['status']??''),'only the owner can explicitly approve a quote');
                try {
                    KitchenRuns::approve($requestId,$users[1],(int)$afterQuote['state_version']);
                    krdb_ok(false,'another customer cannot approve the request');
                } catch (DomainException $e) {
                    krdb_eq('stale_or_not_owned',$e->getMessage(),'another customer cannot approve the request');
                }
            }
        }
    }

    // Conversion must be atomic and idempotent, including a forced write failure.
    krdb_ok(krdb_method('convertAtomically'),'conversion has an injectable transactional seam for rollback testing');
    if (method_exists(KitchenRuns::class,'convertAtomically') && $requestIds) {
        $before=(int)Database::one('SELECT COUNT(*) n FROM orders')['n'];
        $failed=KitchenRuns::convertAtomically($requestIds[0],$staffId,static function(): void { throw new RuntimeException('test failure'); });
        krdb_ok(empty($failed['ok']??true),'an injected conversion failure is reported safely');
        krdb_eq($before,(int)Database::one('SELECT COUNT(*) n FROM orders')['n'],'a failed conversion rolls back every order write');
        krdb_eq('approved',(string)Database::one('SELECT status FROM kitchen_run_requests WHERE id=:id',[':id'=>$requestIds[0]])['status'],'a failed conversion leaves the request Approved');
    }
} finally {
    foreach($orderIds as $id){ Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id=:id)',[':id'=>$id]); Database::run('DELETE FROM order_items WHERE order_id=:id',[':id'=>$id]); Database::run('DELETE FROM payments WHERE order_id=:id',[':id'=>$id]); Database::run('DELETE FROM order_status_history WHERE order_id=:id',[':id'=>$id]); Database::run('DELETE FROM delivery_schedules WHERE order_id=:id',[':id'=>$id]); Database::run('DELETE FROM orders WHERE id=:id',[':id'=>$id]); }
    foreach($requestIds as $id) Database::run('DELETE FROM kitchen_run_requests WHERE id=:id',[':id'=>$id]);
    foreach($users as $id){ Database::run('DELETE FROM business_customers WHERE user_id=:id',[':id'=>$id]); Database::run('DELETE FROM users WHERE id=:id',[':id'=>$id]); }
    if ($staffId) Database::run('DELETE FROM users WHERE id=:id',[':id'=>$staffId]);
}
fwrite(STDOUT,"\n$passed / $tests Kitchen Run database assertions passed.\n"); exit($passed===$tests?0:1);

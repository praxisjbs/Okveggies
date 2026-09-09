<?php
/** Task F customer applications, signed journal, statement and isolation. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$tests=0;$passed=0;
function ccdb_ok($v,string $l):void{global $tests,$passed;$tests++;if($v){$passed++;}else{fwrite(STDERR,"  FAIL: $l\n");}}
function ccdb_eq($e,$a,string $l):void{ccdb_ok($e===$a,$l.($e===$a?'':' (expected '.var_export($e,true).', got '.var_export($a,true).')'));}
function ccdb_refuses(callable $w,string $c,string $l):void{try{$w();ccdb_ok(false,$l);}catch(DomainException $e){ccdb_eq($c,$e->getMessage(),$l);}}
$suffix=bin2hex(random_bytes(5));$users=[];$businesses=[];
try{
 foreach(['First','Second'] as $i=>$name){Database::run('INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status) VALUES (:first,:last,:email,:phone,:hash,:type,:status)',[':first'=>$name,':last'=>'Credit',':email'=>"ccdb-$i-$suffix@example.test",':phone'=>'+23469'.random_int(10000000,99999999),':hash'=>password_hash('test-only',PASSWORD_BCRYPT),':type'=>'business',':status'=>'active']);$users[$i]=(int)Database::getInstance()->getConnection()->lastInsertId();Database::run('INSERT INTO business_customers (user_id,business_name,contact_person) VALUES (:user,:name,:contact)',[':user'=>$users[$i],':name'=>"$name Credit $suffix",':contact'=>"$name Credit"]);$businesses[$i]=(int)Database::getInstance()->getConnection()->lastInsertId();}
 $input=['requested_days'=>'10','requested_limit'=>'500,000','reason'=>'Weekly market buying for our restaurant kitchen.'];
 $first=Credit::apply($users[0],$input);ccdb_eq('pending',$first['status'],'a business creates a pending application');
 $row=Database::one('SELECT requested_days,requested_limit_subunit,reason,status FROM credit_applications WHERE id = :id',[':id'=>$first['id']]);
 ccdb_eq(10,(int)$row['requested_days'],'requested days are stored');ccdb_eq(50000000,(int)$row['requested_limit_subunit'],'requested limit is stored in kobo');
 ccdb_refuses(static fn()=>Credit::apply($users[0],$input),'active_application','a second pending application is refused');
 $second=Credit::apply($users[1],$input);ccdb_ok((int)$second['id']>0,'another business can apply independently');
 ccdb_eq(null,Credit::customerAccount($users[1])['application']['decision_reason'],'one business sees only its own application data');
 Database::run('UPDATE credit_applications SET status = :declined, decision_reason = :reason WHERE id = :id',[':declined'=>'declined',':reason'=>'More trading history is needed.',':id'=>$first['id']]);
 Database::run('UPDATE business_customers SET credit_status = :declined WHERE id = :id',[':declined'=>'declined',':id'=>$businesses[0]]);
 $again=Credit::apply($users[0],['requested_days'=>'7','requested_limit'=>'400,000','reason'=>'A smaller weekly limit will cover our produce orders.']);
 ccdb_ok((int)$again['id']>(int)$first['id'],'a declined business can submit a new application while history remains');
 Database::run('UPDATE credit_applications SET status = :approved WHERE id = :id',[':approved'=>'approved',':id'=>$again['id']]);
 Database::run('UPDATE business_customers SET credit_status = :approved, credit_days = :days, credit_limit_subunit = :credit_limit WHERE id = :id',[':approved'=>'approved',':days'=>7,':credit_limit'=>50000000,':id'=>$businesses[0]]);
 foreach([['charge',30000000,'2026-09-01'],['charge',12000000,'2026-09-20'],['repayment',-5000000,null],['adjustment',-2000000,null]] as [$type,$amount,$due]){Database::run('INSERT INTO credit_transactions (business_customer_id,transaction_type,amount_subunit,due_date,status) VALUES (:business,:type,:amount,:due,:status)',[':business'=>$businesses[0],':type'=>$type,':amount'=>$amount,':due'=>$due,':status'=>'posted']);}
 $summary=Credit::summaryForBusiness(Database::one('SELECT * FROM business_customers WHERE id = :id',[':id'=>$businesses[0]]),new DateTimeImmutable('2026-09-08',new DateTimeZone('Africa/Lagos')));
 ccdb_eq(35000000,$summary['outstanding_subunit'],'charges, repayment and adjustment sum to the balance');ccdb_eq(15000000,$summary['available_subunit'],'the journal balance leaves the correct available credit');ccdb_eq(23000000,$summary['overdue_subunit'],'repayment and adjustment reduce oldest overdue charges');ccdb_eq('2026-09-01',$summary['earliest_due_date'],'the nearest charge due date is shown');
 $statement=Credit::statement($users[0],'',1);ccdb_eq(4,$statement['count'],'the statement returns all signed journal entries');ccdb_eq(0,Credit::statement($users[1],'charge',1)['count'],'another business cannot read the first statement');
 Database::run('UPDATE business_customers SET credit_status = :suspended WHERE id = :id',[':suspended'=>'suspended',':id'=>$businesses[0]]);ccdb_refuses(static fn()=>Credit::apply($users[0],$input),'active_credit','suspended credit blocks another application');
}finally{
 foreach($businesses as $id){Database::run('DELETE FROM credit_transactions WHERE business_customer_id = :id',[':id'=>$id]);Database::run('DELETE FROM credit_applications WHERE business_customer_id = :id',[':id'=>$id]);Database::run('DELETE FROM business_customers WHERE id = :id',[':id'=>$id]);}
 foreach($users as $id){Database::run('DELETE FROM users WHERE id = :id',[':id'=>$id]);}
}
fwrite(STDOUT,"\n$passed / $tests customer credit database assertions passed.\n");exit($passed===$tests?0:1);

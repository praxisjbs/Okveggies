<?php
/** Seed, admin-write and RBAC checks on a scratch MariaDB. */
$root=dirname(__DIR__,2);require_once $root.'/includes/bootstrap.php';
$tests=0;$passed=0;
$check=static function($condition,string $label)use(&$tests,&$passed):void{$tests++;if($condition){$passed++;return;}fwrite(STDERR,"  FAIL: $label\n");};
$zones=Delivery::zonesActive();$household=Delivery::allowedDaysFor('household');$business=Delivery::allowedDaysFor('business');
$check(count($zones)>0,'seed includes active Lagos zones');
$check(count($household)===4,'seed includes 4 household delivery-day rows');
$check(count($business)===2,'seed includes 2 business delivery-day rows');
$permissionKeys=['delivery.days.edit','delivery.zones.edit','delivery.exceptions.edit'];
foreach($permissionKeys as $key){$permission=Database::one('SELECT id FROM permissions WHERE `key`=:key',[':key'=>$key]);$check($permission!==null,$key.' is seeded');$grant=Database::one('SELECT rp.permission_id FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name=\'manager\' AND p.`key`=:key',[':key'=>$key]);$check($grant!==null,'Manager receives '.$key);}
$pdo=Database::getInstance()->getConnection();$pdo->beginTransaction();
try{
    $day=$household[0];$newActive=empty($day['is_active'])?1:0;
    Database::run('UPDATE allowed_delivery_days SET is_active=:active WHERE customer_type=\'household\' AND day_of_week=:day',[':active'=>$newActive,':day'=>(int)$day['day_of_week']]);
    $changedDay=Database::one('SELECT is_active FROM allowed_delivery_days WHERE customer_type=\'household\' AND day_of_week=:day',[':day'=>(int)$day['day_of_week']]);
    $check((int)$changedDay['is_active']===$newActive,'allowed-day admin write persists');
    $zone=$zones[0];Database::run('UPDATE delivery_zones SET is_active=0 WHERE id=:id',[':id'=>(int)$zone['id']]);
    $check(Database::one('SELECT id FROM delivery_zones WHERE id=:id AND is_active=1',[':id'=>(int)$zone['id']])===null,'inactive zone is removed from customer choices');
    Database::run('INSERT INTO delivery_date_exceptions (exception_date,is_available,reason,replacement_date) VALUES (\'2099-12-30\',0,\'Database test closure\',\'2099-12-31\') ON DUPLICATE KEY UPDATE is_available=VALUES(is_available),reason=VALUES(reason),replacement_date=VALUES(replacement_date)');
    $exception=Delivery::isEligible('2099-12-30','household',new DateTimeImmutable('2099-12-01 10:00:00',new DateTimeZone('Africa/Lagos')));
    $check(!$exception['eligible']&&$exception['reason']==='Database test closure','exception write supplies the customer reason');
    $check(($exception['replacement_date']??null)==='2099-12-31','replacement date survives the database round trip');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
fwrite(STDOUT,"\n$passed / $tests assertions passed.\n");if($passed!==$tests)exit(1);fwrite(STDOUT,"All green.\n");

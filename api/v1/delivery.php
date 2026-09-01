<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
$action=okv_action();
function delivery_write(string $permission): void { if(!okv_is_post()) okv_error('Use POST for this action.',405,'method_not_allowed'); Rbac::requirePermission($permission); if(!Csrf::validate()) okv_error('Your session expired. Reload the page and try again.',419,'csrf_expired'); }
function delivery_success(string $message): void { if(str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json')||!empty($_SERVER['HTTP_X_REQUESTED_WITH']))okv_json(['status'=>'ok','message'=>$message]);okv_redirect('/admin/delivery.php?delivery=updated',303); }
if($action==='eligible_dates'){ $type=(string)okv_input('customer_type','household'); if(!in_array($type,['household','business'],true))okv_error('Choose an account type.',422,'bad_type'); okv_json(['status'=>'ok','dates'=>Delivery::nextEligibleDates($type)]); }
if($action==='zones'){ okv_json(['status'=>'ok','zones'=>Delivery::zonesActive()]); }
try {
 switch($action){
 case 'set_day': delivery_write('delivery.days.edit'); Database::run('UPDATE allowed_delivery_days SET is_active=:active, cutoff_time=:cutoff, minimum_lead_days=:lead WHERE customer_type=:type AND day_of_week=:day',[':active'=>(int)okv_input('is_active',0),':cutoff'=>(string)okv_input('cutoff_time','16:00'),':lead'=>max(0,(int)okv_input('minimum_lead_days',1)),':type'=>(string)okv_input('customer_type',''),':day'=>(int)okv_input('day_of_week',0)]); delivery_success('Delivery day updated.');
 case 'set_zone_active': delivery_write('delivery.zones.edit'); Database::run('UPDATE delivery_zones SET is_active=:active WHERE id=:id',[':active'=>(int)okv_input('is_active',0),':id'=>(int)okv_input('zone_id',0)]); delivery_success('Delivery zone updated.');
 case 'save_exception': delivery_write('delivery.exceptions.edit'); $date=(string)okv_input('exception_date',''); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))okv_error('Choose a valid date.',422,'bad_date'); Database::run('INSERT INTO delivery_date_exceptions (exception_date,is_available,reason,replacement_date,created_by) VALUES (:date,:available,:reason,:replacement,:user) ON DUPLICATE KEY UPDATE is_available=VALUES(is_available),reason=VALUES(reason),replacement_date=VALUES(replacement_date),created_by=VALUES(created_by)',[':date'=>$date,':available'=>(int)okv_input('is_available',0),':reason'=>trim((string)okv_input('reason',''))?:null,':replacement'=>trim((string)okv_input('replacement_date',''))?:null,':user'=>Rbac::userId()]); delivery_success('Delivery date saved.');
 default: okv_error('This action is not available.',400,'unknown_action');
 }
}catch(Throwable $e){error_log('delivery action failed: '.$e->getMessage());okv_error('We could not update delivery settings. Please try again.',500,'failed');}

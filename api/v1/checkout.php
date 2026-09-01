<?php
/** Checkout step persistence and final transactional order placement. */
require_once __DIR__ . '/../../includes/bootstrap.php';
$action=okv_action();
if(!okv_is_post())okv_error('Use POST for this action.',405,'method_not_allowed');
if(!Csrf::validate())okv_error('Your session expired. Reload the page and try again.',419,'csrf_expired');
function checkout_json(): bool { return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json') || !empty($_SERVER['HTTP_X_REQUESTED_WITH']); }
try{
    if($action==='save_step'){
        $step=(string)okv_input('step',''); if(!in_array($step,['customer','delivery','payment'],true))throw new DomainException('bad_step');
        $allowed=['customer'=>['recipient_name','recipient_phone','email','address_line_1','address_line_2','city','state','landmark','customer_type','create_account'], 'delivery'=>['delivery_date','delivery_zone_id'], 'payment'=>['payment_option']];
        $data=[];foreach($allowed[$step] as $key)$data[$key]=okv_input($key,'');Checkout::saveStep($step,$data);
        $next=['customer'=>3,'delivery'=>4,'payment'=>4][$step]; if(checkout_json())okv_json(['status'=>'ok','next_step'=>$next]);okv_redirect('/checkout.php?step='.$next,303);
    }
    if($action!=='place_order')okv_error('This action is not available.',400,'unknown_action');
    $postedPayment=(string)okv_input('payment_option','');if($postedPayment!=='')Checkout::saveStep('payment',['payment_option'=>$postedPayment]);
    $bag=Checkout::bag();$customer=$bag['customer']??[];$delivery=$bag['delivery']??[];$payment=$bag['payment']??[];
    if(!Customer::isLoggedIn()){
        if(empty($customer['create_account']))throw new DomainException('consent_required');
        $email=strtolower(trim((string)($customer['email']??'')));$phone=Phone::normalize((string)($customer['recipient_phone']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$phone===null)throw new DomainException('bad_customer');
        if(Database::one('SELECT id FROM users WHERE email=:email OR phone=:phone',[':email'=>$email,':phone'=>$phone]))throw new DomainException('account_exists');
        Database::run('INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status) VALUES (:first,\'\',:email,:phone,:password,\'household\',\'active\')',[':first'=>(string)$customer['recipient_name'],':email'=>$email,':phone'=>$phone,':password'=>Password::hash(bin2hex(random_bytes(24)))]);$user=Database::one('SELECT id,user_type,email_verified_at,first_name FROM users WHERE id=:id',[':id'=>(int)Database::getInstance()->getConnection()->lastInsertId()]);Auth::startSession($user);
    }
    $input=array_merge($customer,['user_id'=>Customer::id(),'customer_type'=>Customer::type(),'activated'=>Customer::isActivated(),'delivery_date'=>$delivery['delivery_date']??'','delivery_zone_id'=>(int)($delivery['delivery_zone_id']??0),'payment_option'=>$payment['payment_option']??'']);
    $result=Checkout::place($input);$base=rtrim((string)APP_URL,'/');$result['confirmation_url']=$base.$result['confirmation_url'];$result['trail_url']=$result['trail_url']===''?'':$base.$result['trail_url'];
    if(checkout_json())okv_json(['status'=>'ok']+$result);okv_redirect('/public/order.php?order='.(int)$result['order_id'],303);
}catch(DomainException $e){$known=['consent_required'=>['Tick the account consent box to continue.','consent_required'],'payment_not_allowed'=>['That payment choice is not available for this account.','payment_not_allowed'],'credit_not_approved'=>['On-account payment is only available after credit approval.','credit_not_approved'],'delivery_unavailable'=>['That delivery date is no longer available. Pick another date.','delivery_unavailable'],'zone_unavailable'=>['That delivery area is no longer available. Pick another area.','zone_unavailable'],'empty_cart'=>['Your basket is empty.','empty_cart'],'bad_customer'=>['Check your contact and delivery details.','bad_customer'],'account_exists'=>['An account already uses that email address or phone number. Sign in to continue.','account_exists']];$reason=$e->getMessage();[$defaultMessage,$clientCode]=$known[$reason]??['Check your checkout details and try again.','invalid_checkout'];$message=$e instanceof CheckoutException?$e->customerMessage():$defaultMessage;okv_error($message,422,$clientCode);}catch(Throwable $e){error_log('checkout.place_order failed: '.$e->getMessage());okv_error('We could not place your order. Please try again.',500,'failed');}

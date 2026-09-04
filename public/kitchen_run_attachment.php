<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$id=(int)okv_input('request',0); $r=Database::one('SELECT attachment_url,user_id FROM kitchen_run_requests WHERE id=:id',[':id'=>$id]);
if(!$r || ((!Customer::isLoggedIn() || (int)Customer::id() !== (int)$r['user_id']) && !Rbac::can('kitchen_runs.view'))) { http_response_code(404); exit; }
$relative=(string)$r['attachment_url'];
if(!preg_match('#^uploads/kitchen_runs/[a-f0-9]{32}\.(jpg|png|pdf)$#',$relative)) { http_response_code(404); exit; }
$path=dirname(__DIR__).'/'.$relative; if(!is_file($path)){http_response_code(404);exit;}
$ext=pathinfo($path,PATHINFO_EXTENSION); header('Content-Type: '.($ext==='pdf'?'application/pdf':($ext==='png'?'image/png':'image/jpeg'))); header('Content-Disposition: attachment; filename="kitchen-list.'.$ext.'"'); header('Content-Length: '.filesize($path)); readfile($path);

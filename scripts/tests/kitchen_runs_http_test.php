<?php
/** M7 HTTP contract. Requires OKV_TEST_BASE and a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
if (!function_exists('curl_init')) { fwrite(STDERR,"This test needs PHP curl.\n"); exit(2); }

$base=rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123','/'); $tests=0; $passed=0;
function krh_ok($ok,string $label): void { global $tests,$passed; $tests++; if($ok){$passed++;}else fwrite(STDERR,"  FAIL: $label\n"); }
function krh_eq($want,$got,string $label): void { krh_ok($want===$got,$label.($want===$got?'':" (expected $want, got $got)")); }
function krh_request(string $jar,string $url,?array $post=null): array { global $base; $ch=curl_init($base.$url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['X-Requested-With: fetch','Accept: application/json']]); if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));} $raw=(string)curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$size=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);curl_close($ch);return[$code,json_decode(substr($raw,$size),true)?:[]]; }

// The controller must make these security properties true over the real route.
$jar=tempnam(sys_get_temp_dir(),'okv-krh-');
try {
    [$code,$body]=krh_request($jar,'/api/v1/kitchen_runs.php?action=submit');
    krh_eq(405,$code,'Kitchen Run writes reject GET');
    [$code,$body]=krh_request($jar,'/api/v1/kitchen_runs.php',['action'=>'submit','input_mode'=>'custom']);
    krh_eq(419,$code,'Kitchen Run writes reject a missing CSRF token');
    krh_ok(!str_contains(json_encode($body),'Exception')&&!str_contains(json_encode($body),'SQLSTATE'),'Kitchen Run errors do not leak exception details');
    [$code]=krh_request($jar,'/api/v1/kitchen_runs.php',['action'=>'quote','request_id'=>999999]);
    krh_eq(419,$code,'a guest quote without a CSRF token is refused before any action runs');
    [$code]=krh_request($jar,'/api/v1/kitchen_runs.php',['action'=>'convert','request_id'=>999999]);
    krh_eq(419,$code,'a guest conversion without a CSRF token is refused before any action runs');
} finally { if(is_file($jar)) unlink($jar); }
fwrite(STDOUT,"\n$passed / $tests Kitchen Run HTTP assertions passed.\n"); exit($passed===$tests?0:1);

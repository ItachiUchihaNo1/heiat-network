<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{DomainRouting as R,HttpError,Installer};
$surface=$argv[1]??'public';if(!in_array($surface,['public','admin'],true))throw new RuntimeException('surface');define('NAMIRA_SURFACE',$surface);$checks=0;
function ok(bool $b,string $s):void {global $checks;if(!$b)throw new RuntimeException('FAIL '.$s);$checks++;echo 'PASS '.$s.PHP_EOL;}
function rejects(callable $f,int $code,string $s):void {try{$f();}catch(HttpError $e){ok($e->status===$code,$s);return;}throw new RuntimeException('Expected rejection '.$s);}
putenv('PUBLIC_APP_URL');putenv('ADMIN_APP_URL');ok(!R::enabled()&&url('admin')==='/index.php?page=admin','single-domain installations retain existing URLs');
putenv('PUBLIC_APP_URL=https://naamiraa.ir');putenv('ADMIN_APP_URL=https://admin.naamiraa.ir');ok(R::enabled(),'separate secure origins enabled');
foreach(['http://admin.naamiraa.ir','https://admin.naamiraa.ir/path','https://user:pass@admin.naamiraa.ir','https://admin.naamiraa.ir?next=x','https://admin.naamiraa.ir#x',"https://admin.naamiraa.ir\r\nX: y"] as $bad){putenv('ADMIN_APP_URL='.$bad);rejects(fn()=>R::enabled(),503,'invalid origin rejected');}putenv('ADMIN_APP_URL=https://admin.naamiraa.ir');
$_SERVER['HTTP_HOST']='evil.example';$_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_GET=[];rejects(fn()=>R::enforce($surface==='admin'?'admin':'home'),421,'untrusted host never used for redirects');$_SERVER['HTTP_HOST']=$surface==='admin'?'admin.naamiraa.ir':'naamiraa.ir';
if($surface==='public'){
 ok(url('admin',['tab'=>'calls'])==='https://admin.naamiraa.ir/index.php?page=admin&tab=calls','public admin links lead to dedicated origin');
 ok(R::destination('admin','GET',['page'=>'admin','tab'=>'users'])==='https://admin.naamiraa.ir/index.php?page=admin&tab=users','legacy GET management URL canonicalizes safely');
 foreach(['user-save','backup-create','protocol-save','store-product','commerce-refund','call2-publish','call2-review','login-admin-invented'] as $a)rejects(fn()=>R::destination('home','POST',[],$a),403,'management POST blocked on public surface '.$a);
 foreach(['login','logout','mobile-password','commerce-checkout','call2-finalize','call2-upload','member-profile'] as $a)ok(R::destination('home','POST',[],$a)===null,'customer operation preserved '.$a);
 ok(url('login',['legacy'=>1])==='/index.php?page=login&legacy=1','legacy customer email login remains on public site');
}else{
 ok(url('home')==='https://naamiraa.ir/index.php?page=home','admin preview links lead to public site');ok(url('admin',['tab'=>'martyrs'])==='/index.php?page=admin&tab=martyrs','admin navigation stays on admin');ok(url('login')==='/index.php?page=login','admin login stays on admin');ok(url('store-order',['id'=>9])==='/index.php?page=store-order&id=9','administrative order actions retain staff session');ok(url('call-file',['id'=>7])==='/index.php?page=call-file&id=7','authenticated private downloads retain admin session');
 foreach(['login','call2-review','commerce-refund','user-save'] as $a)ok(R::destination('admin','POST',[],$a)===null,'admin endpoint remains subject to existing CSRF and RBAC '.$a);
 ok(R::destination('profile','GET',['id'=>5])==='https://naamiraa.ir/index.php?page=profile&id=5','public profile previews leave management host');
}
$config=Installer::input(['db_host'=>'localhost','db_name'=>'nm_test','db_user'=>'nm_app','db_password'=>'test-only']);ok($config['APP_URL']==='https://naamiraa.ir','installer uses new public origin');
putenv('ADMIN_APP_URL');rejects(fn()=>R::enabled(),503,'partial split configuration fails closed');putenv('ADMIN_APP_URL=https://naamiraa.ir');rejects(fn()=>R::enabled(),503,'identical domains rejected');
echo $checks.' domain separation checks passed ('.$surface.').'.PHP_EOL;

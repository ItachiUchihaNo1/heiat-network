<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('NAMIRA_HOME_TEST')!=='1'||!getenv('NAMIRA_HOME_KEY')||!getenv('NAMIRA_HOME_MANIFEST')){http_response_code(404);exit;}
$m=json_decode(file_get_contents(getenv('NAMIRA_HOME_MANIFEST')),true,20,JSON_THROW_ON_ERROR);$base=realpath(dirname(__DIR__));$storage=realpath($m['storage']);if(!$storage||!str_starts_with(str_replace('\\','/',$storage),str_replace('\\','/',$base).'/storage/home-showcase-test-')||$m['dsn']!=='sqlite:'.$m['storage'].'/db.sqlite'){http_response_code(404);exit;}
putenv('DB_DSN='.$m['dsn']);putenv('DB_USER=');putenv('DB_PASSWORD=');putenv('STORAGE_PATH='.$storage);putenv('APP_ENV=testing');putenv('PUBLIC_APP_URL=');putenv('ADMIN_APP_URL=');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$file=realpath($base.'/public'.$path);if($path!=='/'&&$path!=='/index.php'&&$file&&str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$base).'/public/')&&is_file($file))return false;
if($path==='/__test/login'){
 if(!hash_equals(getenv('NAMIRA_HOME_KEY'),$_SERVER['HTTP_X_NAMIRA_TEST_KEY']??'')){http_response_code(404);exit;}
 require $base.'/app/bootstrap.php';Namira\Security::start();$role=$_GET['role']??'';if(!in_array($role,['admin','member'],true)){http_response_code(404);exit;}session_regenerate_id(true);$_SESSION['user_id']=$m[$role];$_SESSION['csrf']=bin2hex(random_bytes(32));echo '{"ok":true}';exit;
}
require $base.'/public/index.php';

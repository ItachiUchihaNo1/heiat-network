<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('NAMIRA_UI_TEST')!=='1'||!getenv('NAMIRA_UI_MANIFEST')||!getenv('NAMIRA_UI_KEY')){http_response_code(404);exit;}
$manifest=json_decode((string)file_get_contents(getenv('NAMIRA_UI_MANIFEST')),true,20,JSON_THROW_ON_ERROR);$storage=realpath($manifest['storage']);$base=realpath(dirname(__DIR__));if(!$storage||!str_starts_with(str_replace('\\','/',$storage),str_replace('\\','/',$base).'/storage/store-orders-test-')||$manifest['dsn']!=='sqlite:'.$manifest['storage'].'/db.sqlite'){http_response_code(404);exit;}
putenv('DB_DSN='.$manifest['dsn']);putenv('DB_USER=');putenv('DB_PASSWORD=');putenv('STORAGE_PATH='.$storage);putenv('APP_ENV=testing');putenv('PAYMENT_DRIVER=test');putenv('APP_URL=http://127.0.0.1:8789');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$public=$base.'/public';$target=realpath($public.$path);if($path!=='/'&&$path!=='/index.php'&&$target&&str_starts_with(str_replace('\\','/',$target),str_replace('\\','/',$public).'/')&&is_file($target))return false;
if($path==='/__test/login'){if(!hash_equals(getenv('NAMIRA_UI_KEY'),$_SERVER['HTTP_X_NAMIRA_TEST_KEY']??'')){http_response_code(404);exit;}require $base.'/app/bootstrap.php';Namira\Security::start();$role=$_GET['role']??'buyer';if(!in_array($role,['buyer','admin','other','designer'],true)){http_response_code(404);exit;}session_regenerate_id(true);$_SESSION['user_id']=$manifest[$role];$_SESSION['csrf']=bin2hex(random_bytes(32));header('Content-Type: application/json');echo '{"ok":true}';exit;}
require $public.'/index.php';

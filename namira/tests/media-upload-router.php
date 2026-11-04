<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('NAMIRA_MEDIA_TEST')!=='1'||!getenv('NAMIRA_MEDIA_KEY')||!getenv('MEDIA_UPLOAD_MANIFEST')){http_response_code(404);exit;}
$m=json_decode(file_get_contents(getenv('MEDIA_UPLOAD_MANIFEST')),true,20,JSON_THROW_ON_ERROR);$base=realpath(dirname(__DIR__));$storage=realpath($m['storage']);
if(!$storage||!str_starts_with(str_replace('\\','/',$storage),str_replace('\\','/',$base).'/storage/direct-test-')||$m['dsn']!=='sqlite:'.$m['storage'].'/db.sqlite'){http_response_code(404);exit;}
putenv('DB_DSN='.$m['dsn']);putenv('DB_USER=');putenv('DB_PASSWORD=');putenv('STORAGE_PATH='.$storage);putenv('APP_ENV=testing');putenv('PUBLIC_APP_URL=');putenv('ADMIN_APP_URL=');putenv('SMS_ENABLED=0');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$file=realpath($base.'/public'.$path);if($path!=='/'&&$path!=='/index.php'&&$file&&str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$base).'/public/')&&is_file($file))return false;
if(in_array($path,['/__test/login','/__test/state'],true)){
 if(!hash_equals(getenv('NAMIRA_MEDIA_KEY'),$_SERVER['HTTP_X_NAMIRA_TEST_KEY']??'')){http_response_code(404);exit;}
 require $base.'/app/bootstrap.php';Namira\Security::start();header('Content-Type: application/json');
 if($path==='/__test/login'){session_regenerate_id(true);$_SESSION['user_id']=$m['admin'];$_SESSION['csrf']=bin2hex(random_bytes(32));echo '{"ok":true}';exit;}
 $db=Namira\Db::connect();echo json_encode(Namira\Db::query($db,'SELECT i.id,i.title,mm.martyr_id,ma.artist_id FROM images i JOIN media_public_martyrs mm ON mm.image_id=i.id LEFT JOIN media_public_artists ma ON ma.image_id=i.id ORDER BY i.id')->fetchAll());exit;
}
require $base.'/public/index.php';

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('NAMIRA_EITAA_UI')!=='1'||!getenv('NAMIRA_UI_MANIFEST')){http_response_code(404);exit;}
$m=json_decode((string)file_get_contents(getenv('NAMIRA_UI_MANIFEST')),true,16,JSON_THROW_ON_ERROR);$base=realpath(dirname(__DIR__));$storage=realpath($m['storage']);if(!$storage||!str_starts_with(str_replace('\\','/',$storage),str_replace('\\','/',$base).'/storage/eitaa-test-')||$m['dsn']!=='sqlite:'.$m['storage'].'/db.sqlite'){http_response_code(404);exit;}
putenv('DB_DSN='.$m['dsn']);putenv('DB_USER=');putenv('DB_PASSWORD=');putenv('STORAGE_PATH='.$storage);putenv('APP_ENV=testing');putenv('APP_URL=http://127.0.0.1:8795');putenv('PUBLIC_APP_URL=');putenv('ADMIN_APP_URL=');putenv('EITAA_ENABLED=1');putenv('EITAA_TOKEN=test-only-eitaa-token');
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$public=$base.'/public';$target=realpath($public.$uri);if($uri!=='/'&&$uri!=='/index.php'&&$target&&str_starts_with(str_replace('\\','/',$target),str_replace('\\','/',$public).'/')&&is_file($target))return false;
require $public.'/index.php';

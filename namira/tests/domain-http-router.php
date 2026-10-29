<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('NAMIRA_DOMAIN_TEST')!=='1'||!getenv('NAMIRA_UI_MANIFEST')){http_response_code(404);exit;}
$m=json_decode(file_get_contents(getenv('NAMIRA_UI_MANIFEST')),true,20,JSON_THROW_ON_ERROR);$base=realpath(dirname(__DIR__));$storage=realpath($m['storage']);if(!$storage||!str_starts_with(str_replace('\\','/',$storage),str_replace('\\','/',$base).'/storage/calls-test-')||$m['dsn']!=='sqlite:'.$m['storage'].'/db.sqlite'){http_response_code(404);exit;}
$surface=getenv('NAMIRA_TEST_SURFACE');if(!in_array($surface,['public','admin'],true)){http_response_code(404);exit;}define('NAMIRA_SURFACE',$surface);putenv('DB_DSN='.$m['dsn']);putenv('DB_USER=');putenv('DB_PASSWORD=');putenv('STORAGE_PATH='.$storage);putenv('APP_ENV=testing');putenv('APP_URL=https://naamiraa.ir');putenv('PUBLIC_APP_URL=https://naamiraa.ir');putenv('ADMIN_APP_URL=https://admin.naamiraa.ir');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(str_starts_with($path,'/assets/'))return false;require $base.'/public/index.php';

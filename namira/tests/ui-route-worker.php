<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$dsn=Namira\Env::get('DB_DSN');if(!str_starts_with($dsn,'sqlite:')&&!preg_match('/dbname=namira_test_ui(?:;|$)/',$dsn))throw new RuntimeException('Isolated UI database required');
putenv('APP_ENV=testing');$sessions=Namira\Env::storage().'/sessions';if(!is_dir($sessions))mkdir($sessions,0700,true);ini_set('session.save_path',$sessions);session_name('namira_session');session_id('ui'.bin2hex(random_bytes(16)));session_start();$actor=(int)($argv[1]??0);if($actor)$_SESSION['user_id']=$actor;session_write_close();
$_SERVER['REQUEST_METHOD']='GET';$_GET=['page'=>$argv[2]??'request'];register_shutdown_function(function(){fwrite(STDERR,'UI_HTTP_STATUS='.(http_response_code()?:200)."\n");});Namira\Web::run();

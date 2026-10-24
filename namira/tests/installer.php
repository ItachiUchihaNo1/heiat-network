<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Installer,Db,Env,HttpError};
$root=BASE_PATH.'/storage/installer-test-'.bin2hex(random_bytes(8));mkdir($root,0700,true);mkdir($root.'/storage',0700);$key=bin2hex(random_bytes(32));file_put_contents($root.'/.setup.key',$key);$_SESSION=[];$checks=0;
function ok(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException('FAIL '.$message);$checks++;echo 'PASS '.$message."\n";}
function denied(callable $f,int $status,string $message):void{try{$f();}catch(HttpError $e){ok($e->status===$status,$message);return;}throw new RuntimeException('FAIL expected rejection '.$message);}
try{
    $i=new Installer($root);ok($i->enabled(),'installer requires private setup key');denied(fn()=>$i->unlock(str_repeat('0',64)),403,'wrong installation key rejected');$i->unlock($key);ok($i->authorized(),'correct key unlocks setup in session');
    $_SESSION['install_grant_at']=time()-1801;ok(!$i->authorized(),'installation grant expires');$i->unlock($key);
    $config=Installer::input(['db_host'=>'localhost','db_name'=>'namira_new','db_user'=>'cp_namira','db_password'=>"  quote'\"=#\\\nپایان  "]);ok(str_starts_with($config['DB_PASSWORD'],'  '),'database password whitespace preserved');
    denied(fn()=>Installer::input(['db_host'=>'localhost;dbname=other','db_name'=>'namira','db_user'=>'u','db_password'=>'p']),422,'DSN injection rejected');
    $dsn=getenv('TEST_INSTALL_DB_DSN')?:'sqlite:'.$root.'/install.sqlite';
    if(!str_starts_with($dsn,'sqlite:')&&!preg_match('/dbname=namira_test_installer(?:;|$)/',$dsn))throw new RuntimeException('Only namira_test_installer is permitted');
    $db=new PDO($dsn,getenv('TEST_DB_USER')?:'',getenv('TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $in=['admin_email'=>'admin@example.test','admin_password'=>'A-secure-test-password','admin_password_confirm'=>'A-secure-test-password'];
    $bad=$in;$bad['admin_password']='short';denied(fn()=>$i->install($db,$config,$bad),422,'weak admin password rejected before migration');
    $db->exec('CREATE TABLE existing_original_data (id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO existing_original_data VALUES (1)');denied(fn()=>$i->install($db,$config,$in),422,'nonempty database rejected');ok((int)$db->query('SELECT COUNT(*) FROM existing_original_data')->fetchColumn()===1,'existing data untouched');
    // This disposable fixture table belongs only to this isolated test database.
    $db->exec('DROP TABLE existing_original_data');
    $i->install($db,$config,$in);ok(is_file($root.'/config.local.json'),'private configuration persisted');$saved=json_decode(file_get_contents($root.'/config.local.json'),true);ok($saved['DB_PASSWORD']===$config['DB_PASSWORD'],'special-character database password roundtrips');
    $admin=$db->query('SELECT * FROM users')->fetch();ok(password_verify($in['admin_password'],$admin['password_hash']),'administrator password stored as hash');ok($admin['role']==='superadmin','first administrator has correct role');ok(!is_file($root.'/.setup.key')&&is_file($root.'/.setup.lock'),'single-use setup key consumed and installer locked');ok(!$i->enabled(),'installer disabled after configuration');denied(fn()=>$i->install($db,$config,$in),403,'reinstallation rejected');
    $second=$root.'/already-configured';mkdir($second);file_put_contents($second.'/.setup.key',$key);file_put_contents($second.'/.env','APP_ENV=production');ok(!(new Installer($second))->enabled(),'existing dotenv installation cannot be overwritten');
    file_put_contents($root.'/env-test.json',json_encode(['INSTALLER_TEST_SECRET'=>$config['DB_PASSWORD']]));putenv('INSTALLER_TEST_SECRET');Env::loadJson($root.'/env-test.json');ok(getenv('INSTALLER_TEST_SECRET')===$config['DB_PASSWORD'],'JSON configuration loader preserves exact credentials');
    echo "\n$checks installer checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

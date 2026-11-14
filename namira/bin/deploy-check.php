<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
try {
 $private=$argv[1]??'';$backupRoot=$argv[2]??'';
 if($private!=='/var/www/naamiraa/_namira_live'||!preg_match('~^/var/backups/naamiraa/deploy/[a-f0-9]{40}-[a-f0-9]{12}$~D',$backupRoot))throw new RuntimeException('paths');
 define('NAMIRA_SHARED_PATH',$private);putenv('STORAGE_PATH='.$backupRoot);require dirname(__DIR__).'/app/bootstrap.php';
 foreach(['pdo_mysql','gd','mbstring','fileinfo','zip','dom','curl'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('extensions');
 $db=Namira\Db::connect();if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->query('SELECT DATABASE()')->fetchColumn()!=='namira_app')throw new RuntimeException('database');
 foreach(['PlatformSchema','CommunitySchema','ProtocolSchema','MartyrTaxonomySchema','SimpleMartyrSchema','MemberSchema','StoreSchema','StoreOrderSchema','StoreOperationsSchema','CallSchema'] as $schema){$class='Namira\\'.$schema;if(!$class::ready($db))throw new RuntimeException('pending_schema');}
 if(!Namira\AccountUi::ready($db))throw new RuntimeException('pending_schema');
 if(Namira\Env::get('PAYMENT_DRIVER')==='bale'&&!Namira\BalePaymentSchema::ready($db))throw new RuntimeException('pending_schema');
 $backup=Namira\DataBackup::create($db,'before-github-deploy');
 echo json_encode(['ok'=>true,'database'=>'namira_app','tables'=>$backup['tables'],'backup'=>$backup['name'],'backup_bytes'=>$backup['bytes'],'schema_changed'=>false,'real_sms'=>0,'real_payments'=>0],JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){fwrite(STDERR,"Deployment database/extension/schema check failed; no migration was executed.\n");exit(1);}

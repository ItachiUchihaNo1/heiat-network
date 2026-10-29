<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallSchema {
 public const VERSION='012_calls_workflow';
 public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',[self::VERSION])->fetchColumn();}catch(\PDOException){return false;}}
 public static function migrate(PDO $db):void {
  if(self::ready($db))return;CommunitySchema::requireReady($db);
  $lock=fopen(Env::storage().'/.calls-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقای فراخوان در دسترس نیست.');
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقای فراخوان در جریان است.');}
  try{
   if(self::ready($db))return;$before=DataBackup::counts($db);$backup=DataBackup::create($db,'before-012-calls-workflow');
   $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
   $tables=[
    'call_sections'=>"id $id,call_id INTEGER NOT NULL,parent_id INTEGER,content_type_id INTEGER,title VARCHAR(150) NOT NULL,sort_order INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1,config TEXT NOT NULL,FOREIGN KEY(call_id) REFERENCES art_calls(id),FOREIGN KEY(parent_id) REFERENCES call_sections(id),FOREIGN KEY(content_type_id) REFERENCES protocol_terms(id)",
    'call_batches'=>"id $id,call_id INTEGER NOT NULL,user_id INTEGER NOT NULL,token VARCHAR(64) NOT NULL UNIQUE,state VARCHAR(20) NOT NULL DEFAULT 'draft',revision INTEGER NOT NULL DEFAULT 0,payload TEXT NOT NULL,schema_snapshot TEXT NOT NULL,accepted_rules_version VARCHAR(64),accepted_rules_text TEXT NOT NULL,accepted_at VARCHAR(30),created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(call_id) REFERENCES art_calls(id),FOREIGN KEY(user_id) REFERENCES users(id)",
    'call_work_details'=>"submission_id INTEGER PRIMARY KEY,batch_id INTEGER NOT NULL,section_id INTEGER NOT NULL,tracking VARCHAR(50) UNIQUE,review_state VARCHAR(30) NOT NULL DEFAULT 'draft',publication_state VARCHAR(30) NOT NULL DEFAULT 'private',owner_mode VARCHAR(20) NOT NULL DEFAULT 'self',owner_user_id INTEGER,owner_payload TEXT NOT NULL,ai_used INTEGER NOT NULL DEFAULT 0,latest_version_id INTEGER,published_version_id INTEGER,judgement TEXT NOT NULL,submitted_at VARCHAR(30),returned_at VARCHAR(30),FOREIGN KEY(submission_id) REFERENCES art_submissions(id),FOREIGN KEY(batch_id) REFERENCES call_batches(id),FOREIGN KEY(section_id) REFERENCES call_sections(id),FOREIGN KEY(owner_user_id) REFERENCES users(id)",
    'call_work_versions'=>"id $id,submission_id INTEGER NOT NULL,version INTEGER NOT NULL,snapshot TEXT NOT NULL,created_by INTEGER NOT NULL,created_at VARCHAR(30) NOT NULL,UNIQUE(submission_id,version),FOREIGN KEY(submission_id) REFERENCES art_submissions(id),FOREIGN KEY(created_by) REFERENCES users(id)",
    'call_asset_links'=>"id $id,batch_id INTEGER NOT NULL,submission_id INTEGER,asset_id INTEGER NOT NULL,field_key VARCHAR(160) NOT NULL,filename VARCHAR(255) NOT NULL,sequence_no INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(30) NOT NULL,UNIQUE(batch_id,asset_id),FOREIGN KEY(batch_id) REFERENCES call_batches(id),FOREIGN KEY(submission_id) REFERENCES art_submissions(id),FOREIGN KEY(asset_id) REFERENCES incoming_assets(id)",
    'call_publications'=>"id $id,submission_id INTEGER NOT NULL,version_id INTEGER NOT NULL,martyr_id INTEGER NOT NULL,artist_id INTEGER,kind VARCHAR(20) NOT NULL,asset_id INTEGER,image_id INTEGER,slot INTEGER NOT NULL,payload TEXT NOT NULL,visible INTEGER NOT NULL DEFAULT 1,published_by INTEGER NOT NULL,published_at VARCHAR(30) NOT NULL,UNIQUE(submission_id,version_id,slot),FOREIGN KEY(submission_id) REFERENCES art_submissions(id),FOREIGN KEY(version_id) REFERENCES call_work_versions(id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(artist_id) REFERENCES artists(id),FOREIGN KEY(asset_id) REFERENCES incoming_assets(id),FOREIGN KEY(image_id) REFERENCES images(id),FOREIGN KEY(published_by) REFERENCES users(id)",
    'call_export_jobs'=>"id $id,user_id INTEGER NOT NULL,token VARCHAR(64) NOT NULL UNIQUE,state VARCHAR(20) NOT NULL,payload TEXT NOT NULL,cursor_no INTEGER NOT NULL DEFAULT 0,total INTEGER NOT NULL,revision INTEGER NOT NULL DEFAULT 0,error_text TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id)",
    'call_notifications'=>"id $id,user_id INTEGER NOT NULL,submission_id INTEGER NOT NULL,event_key VARCHAR(100) NOT NULL UNIQUE,message TEXT NOT NULL,read_at VARCHAR(30),created_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(submission_id) REFERENCES art_submissions(id)"
   ];
   foreach($tables as $name=>$cols)$db->exec("CREATE TABLE IF NOT EXISTS $name (".($mysql?str_replace(' TEXT',' LONGTEXT',$cols):$cols).")$tail");
   foreach(['call_section_tree'=>'call_sections(call_id,parent_id,active,sort_order)','call_batch_owner'=>'call_batches(user_id,call_id,state)','call_work_queue'=>'call_work_details(review_state,publication_state,batch_id)','call_version_work'=>'call_work_versions(submission_id,id)','call_asset_batch'=>'call_asset_links(batch_id,submission_id,active)','call_public_person'=>'call_publications(martyr_id,visible,kind)','call_public_artist'=>'call_publications(artist_id,visible)','call_notification_owner'=>'call_notifications(user_id,read_at)','call_export_owner'=>'call_export_jobs(user_id,state)'] as $name=>$cols)try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1061&&!str_contains($e->getMessage(),'already exists'))throw $e;}
   DataBackup::verifyCounts($db,$before);
   $report=['backup'=>$backup,'before'=>$before,'after'=>DataBackup::counts($db),'existing_rows_rewritten'=>0,'tables'=>array_keys($tables),'existing_calls_preserved'=>true];
   $path=Env::storage().'/backups/migration-012-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
   if(file_put_contents($path,Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش ارتقای فراخوان ذخیره نشد.');chmod($path,0600);
   Db::insert($db,'migrations',['version'=>self::VERSION,'applied_at'=>date(DATE_ATOM)]);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function requireReady(PDO $db):void {if(!self::ready($db))throw new HttpError(503,'مدیر اصلی یک بار پنل را باز کند تا پشتیبان و ارتقای فراخوان اجرا شود.');}
 public static function lock(PDO $db):string {return $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 public static function permit(PDO $db,array $actor,string $permission):void {if(!Permissions::allows($db,$actor,$permission))throw new HttpError(403,'مجوز این عملیات فراخوان را ندارید.');}
}

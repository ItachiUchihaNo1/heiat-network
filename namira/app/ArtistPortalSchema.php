<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ArtistPortalSchema {
 public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['013_artist_portal'])->fetchColumn();}catch(\PDOException){return false;}}
 public static function requireReady(PDO $db):void {if(!self::ready($db))throw new HttpError(503,'مدیر اصلی باید ارتقای حساب هنرمندان را از پنل مدیریت اجرا کند.');}
 public static function migrate(PDO $db,bool $backup=true):void {
  if(self::ready($db))return;CommunitySchema::requireReady($db);if(!MemberSchema::ready($db))throw new HttpError(503,'ابتدا ارتقای حساب کاربران لازم است.');
  $lock=fopen(Env::storage().'/.artist-portal-upgrade.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new HttpError(409,'ارتقای حساب هنرمندان در حال اجراست.');
  try {if(self::ready($db))return;$before=DataBackup::counts($db);$snapshot=$backup?DataBackup::create($db,'before-013-artist-portal'):null;
   $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
   $tables=[
    'artist_join_requests'=>"id $id,user_id INTEGER NOT NULL UNIQUE,artist_id INTEGER UNIQUE,status VARCHAR(30) NOT NULL DEFAULT 'pending',payload TEXT NOT NULL,avatar_asset_id INTEGER,revision INTEGER NOT NULL DEFAULT 0,review_note TEXT NOT NULL,reviewed_by INTEGER,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(artist_id) REFERENCES artists(id),FOREIGN KEY(avatar_asset_id) REFERENCES file_assets(id)",
    'artist_work_claims'=>"id $id,user_id INTEGER NOT NULL,artist_id INTEGER NOT NULL,image_id INTEGER NOT NULL,batch_key VARCHAR(64) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending',revision INTEGER NOT NULL DEFAULT 0,review_note TEXT NOT NULL,reviewed_by INTEGER,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,UNIQUE(artist_id,image_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(artist_id) REFERENCES artists(id),FOREIGN KEY(image_id) REFERENCES images(id)",
    'artist_archive_selections'=>"user_id INTEGER NOT NULL,image_id INTEGER NOT NULL,created_at VARCHAR(30) NOT NULL,PRIMARY KEY(user_id,image_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(image_id) REFERENCES images(id)",
    'artist_work_requests'=>"submission_id INTEGER PRIMARY KEY,proposal TEXT NOT NULL,martyr_state VARCHAR(30) NOT NULL,created_martyr_id INTEGER,processed_by INTEGER,FOREIGN KEY(submission_id) REFERENCES art_submissions(id),FOREIGN KEY(created_martyr_id) REFERENCES martyrs(id)"
   ];foreach($tables as $table=>$cols){if($mysql)$cols=str_replace(' TEXT',' LONGTEXT',$cols);$db->exec("CREATE TABLE IF NOT EXISTS $table ($cols)$tail");}
   foreach(['artist_join_queue'=>'artist_join_requests(status,created_at)','artist_claim_queue'=>'artist_work_claims(status,image_id)','artist_claim_owner'=>'artist_work_claims(user_id,id)','artist_work_person'=>'artist_work_requests(martyr_state,submission_id)'] as $name=>$cols)try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if(!str_contains($e->getMessage(),'already exists')&&(int)($e->errorInfo[1]??0)!==1061)throw $e;}
   foreach(Permissions::defaults() as $role=>$grants)foreach($grants as $grant)if(!Db::query($db,'SELECT permission FROM role_permissions WHERE role_key=? AND permission=?',[$role,$grant])->fetchColumn())Db::insert($db,'role_permissions',['role_key'=>$role,'permission'=>$grant]);
   DataBackup::verifyCounts($db,$before);$report=['backup'=>$snapshot,'before'=>$before,'after'=>DataBackup::counts($db),'legacy_artist_links_created'=>0,'legacy_profiles_changed'=>0];$dir=Env::storage().'/backups';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/migration-013-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';if(file_put_contents($file,Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش ارتقا ذخیره نشد.');chmod($file,0600);
   Db::insert($db,'migrations',['version'=>'013_artist_portal','applied_at'=>date(DATE_ATOM)]);
  }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
 }
}

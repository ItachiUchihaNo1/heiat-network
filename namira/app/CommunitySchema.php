<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CommunitySchema {
    public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['004_community'])->fetchColumn();}catch(\PDOException){return false;}}
    public static function requireReady(PDO $db):void {if(!self::ready($db))throw new HttpError(503,'مدیر اصلی باید یک بار پنل را باز کند تا بخش مشارکت و حساب عمومی فعال شود.');}
    public static function migrate(PDO $db,bool $backup=true):void {
        if(self::ready($db))return;
        if(!PlatformSchema::ready($db))throw new HttpError(503,'ابتدا ارتقای بانک هنرمندان و رسانه انجام شود.');
        $lock=fopen(Env::storage().'/.community-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقا در دسترس نیست.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقای بخش مشارکت در پنجره دیگری در جریان است.');}
        try{
            if(self::ready($db))return;
            $before=DataBackup::counts($db);$snapshot=$backup?DataBackup::create($db,'before-004-community'):null;
            $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
            $tables=[
                'account_profiles'=>"user_id INTEGER PRIMARY KEY,display_name VARCHAR(255) NOT NULL,phone VARCHAR(30) NOT NULL DEFAULT '',notification_optin INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id)",
                'incoming_assets'=>"id $id,owner_id INTEGER NOT NULL,original_path VARCHAR(255) NOT NULL UNIQUE,preview_path VARCHAR(255) NOT NULL,mime VARCHAR(80) NOT NULL,sha256 VARCHAR(64) NOT NULL,width INTEGER NOT NULL,height INTEGER NOT NULL,bytes INTEGER NOT NULL,stored_bytes INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(owner_id) REFERENCES users(id)",
                'submission_assets'=>"submission_id INTEGER NOT NULL,asset_id INTEGER NOT NULL,sequence_no INTEGER NOT NULL,is_current INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(submission_id,asset_id),FOREIGN KEY(submission_id) REFERENCES art_submissions(id),FOREIGN KEY(asset_id) REFERENCES incoming_assets(id)",
                'contribution_file_assets'=>"file_id INTEGER PRIMARY KEY,asset_id INTEGER NOT NULL,FOREIGN KEY(file_id) REFERENCES contribution_files(id),FOREIGN KEY(asset_id) REFERENCES incoming_assets(id)",
                'contribution_merges'=>"id $id,contribution_id INTEGER NOT NULL,request_key VARCHAR(64) NOT NULL UNIQUE,martyr_id INTEGER NOT NULL,field_key VARCHAR(100) NOT NULL,accepted_text TEXT NOT NULL,before_value TEXT NOT NULL,after_value TEXT NOT NULL,media_ids TEXT NOT NULL,created_by INTEGER NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(contribution_id) REFERENCES public_contributions(id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(created_by) REFERENCES users(id)",
                'artist_account_states'=>"artist_id INTEGER NOT NULL,user_id INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1,reason TEXT NOT NULL,updated_by INTEGER NOT NULL,updated_at VARCHAR(30) NOT NULL,PRIMARY KEY(artist_id,user_id),FOREIGN KEY(artist_id,user_id) REFERENCES artist_accounts(artist_id,user_id)",
                'call_terms'=>"call_id INTEGER NOT NULL,term_id INTEGER NOT NULL,kind VARCHAR(30) NOT NULL,PRIMARY KEY(call_id,term_id,kind),FOREIGN KEY(call_id) REFERENCES art_calls(id),FOREIGN KEY(term_id) REFERENCES protocol_terms(id)",
                'call_martyrs'=>"call_id INTEGER NOT NULL,martyr_id INTEGER NOT NULL,PRIMARY KEY(call_id,martyr_id),FOREIGN KEY(call_id) REFERENCES art_calls(id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id)"
            ];
            foreach($tables as $name=>$cols){if($mysql)$cols=str_replace(' TEXT',' LONGTEXT',$cols);$db->exec("CREATE TABLE IF NOT EXISTS $name ($cols)$tail");}
            foreach(['incoming_owner'=>'incoming_assets(owner_id,id)','submission_owner'=>'art_submissions(user_id,status)','submission_quota'=>'art_submissions(call_id,user_id)','contribution_owner'=>'public_contributions(user_id,status)','claim_queue'=>'artist_account_claims(status,created_at)','call_public'=>'art_calls(status,start_at,end_at)'] as $name=>$cols)try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if(!str_contains($e->getMessage(),'already exists')&&(int)($e->errorInfo[1]??0)!==1061)throw $e;}
            if(!Db::query($db,"SELECT setting_key FROM app_settings WHERE setting_key='incoming_storage_gate'")->fetchColumn())Db::insert($db,'app_settings',['setting_key'=>'incoming_storage_gate','value'=>'1','updated_at'=>date('Y-m-d H:i:s')]);
            foreach(Permissions::defaults() as $role=>$grants)foreach($grants as $grant)if(!Db::query($db,'SELECT permission FROM role_permissions WHERE role_key=? AND permission=?',[$role,$grant])->fetchColumn())Db::insert($db,'role_permissions',['role_key'=>$role,'permission'=>$grant]);
            DataBackup::verifyCounts($db,$before);Db::insert($db,'migrations',['version'=>'004_community','applied_at'=>date('Y-m-d H:i:s')]);Db::audit($db,'migration.004',$snapshot['name']??'fixture');
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

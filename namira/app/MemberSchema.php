<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MemberSchema {
    public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['007_members'])->fetchColumn();}catch(\PDOException){return false;}}
    public static function migrate(PDO $db,bool $backup=true):void {
        if(self::ready($db))return;CommunitySchema::requireReady($db);
        $lock=fopen(Env::storage().'/.members-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقا در دسترس نیست.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقای حساب‌ها در حال اجراست.');}
        try {
            if(self::ready($db))return;$before=DataBackup::counts($db);$snapshot=$backup?DataBackup::create($db,'before-007-members'):null;
            $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
            $tables=[
                'account_identities'=>"user_id INTEGER PRIMARY KEY,first_name VARCHAR(150) NOT NULL,last_name VARCHAR(150) NOT NULL,mobile VARCHAR(20) UNIQUE,mobile_verified_at VARCHAR(30),revision INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id)",
                'otp_challenges'=>"id VARCHAR(64) PRIMARY KEY,mobile VARCHAR(20) NOT NULL,purpose VARCHAR(20) NOT NULL,bound_user_id INTEGER,session_digest VARCHAR(64) NOT NULL,code_digest VARCHAR(64) NOT NULL,expires_at INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,consumed_at INTEGER,delivered INTEGER NOT NULL DEFAULT 0",
                'account_cart_owners'=>"user_id INTEGER PRIMARY KEY,cart_token VARCHAR(64) NOT NULL UNIQUE,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(cart_token) REFERENCES carts(token)",
                'account_order_owners'=>"order_id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,linked_at VARCHAR(30) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id),FOREIGN KEY(user_id) REFERENCES users(id)",
                'account_request_owners'=>"request_id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,linked_at VARCHAR(30) NOT NULL,FOREIGN KEY(request_id) REFERENCES design_requests(id),FOREIGN KEY(user_id) REFERENCES users(id)",
                'account_addresses'=>"id $id,user_id INTEGER NOT NULL,title VARCHAR(100) NOT NULL,recipient VARCHAR(150) NOT NULL,phone VARCHAR(20) NOT NULL,postal_code VARCHAR(10) NOT NULL,address TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id)",
                'design_request_messages'=>"id $id,request_id INTEGER NOT NULL,user_id INTEGER NOT NULL,message TEXT NOT NULL,file_id INTEGER,kind VARCHAR(30) NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(request_id) REFERENCES design_requests(id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(file_id) REFERENCES request_files(id)"
            ];
            foreach($tables as $table=>$cols)$db->exec("CREATE TABLE IF NOT EXISTS $table ($cols)$tail");
            foreach(['otp_mobile_created'=>'otp_challenges(mobile,created_at)','member_orders'=>'account_order_owners(user_id,order_id)','member_requests'=>'account_request_owners(user_id,request_id)','member_addresses'=>'account_addresses(user_id,id)'] as $name=>$cols)try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if(!str_contains($e->getMessage(),'already exists')&&(int)($e->errorInfo[1]??0)!==1061)throw $e;}
            DataBackup::verifyCounts($db,$before);$after=DataBackup::counts($db);
            $report=['version'=>'007_members','created_at'=>date(DATE_ATOM),'backup'=>$snapshot,'before'=>$before,'after'=>$after,'legacy_accounts_auto_verified'=>0,'legacy_services_auto_claimed'=>0];
            $path=Env::storage().'/backups';if(!is_dir($path)&&!mkdir($path,0700,true))throw new HttpError(503,'گزارش ارتقا ذخیره نشد.');
            if(file_put_contents($path.'/migration-007-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json',Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش ارتقا ذخیره نشد.');
            Db::insert($db,'migrations',['version'=>'007_members','applied_at'=>date(DATE_ATOM)]);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

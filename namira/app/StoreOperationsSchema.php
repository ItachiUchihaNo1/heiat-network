<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreOperationsSchema {
 public const VERSION='011_store_operations';
 public static function ready(PDO $db):bool{try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',[self::VERSION])->fetchColumn();}catch(\PDOException){return false;}}
 public static function migrate(PDO $db):void{
  if(self::ready($db))return;if(!StoreOrderSchema::ready($db))throw new HttpError(503,'ابتدا سفارش‌ها ارتقا یابند.');
  $lock=fopen(Env::storage().'/.store-operations-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقا در دسترس نیست.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقا در جریان است.');}
  try{if(self::ready($db))return;$before=DataBackup::counts($db);$backup=DataBackup::create($db,'before-011-store-operations');$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
   $tables=[
    'store_item_schedule'=>"item_id INTEGER PRIMARY KEY,due_at VARCHAR(30),started_at VARCHAR(30),completed_at VARCHAR(30),quality_required INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(item_id) REFERENCES order_items(id)",
    'store_production_records'=>"id $id,item_id INTEGER NOT NULL,user_id INTEGER NOT NULL,event VARCHAR(30) NOT NULL,quantity INTEGER NOT NULL,quality VARCHAR(20) NOT NULL,note TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(item_id) REFERENCES order_items(id),FOREIGN KEY(user_id) REFERENCES users(id)",
    'store_material_links'=>"product_id INTEGER NOT NULL,variant_id INTEGER NOT NULL,units INTEGER NOT NULL,PRIMARY KEY(product_id,variant_id),FOREIGN KEY(product_id) REFERENCES products(id),FOREIGN KEY(variant_id) REFERENCES variants(id)",
    'store_notification_deliveries'=>"id $id,notification_id INTEGER NOT NULL,channel VARCHAR(20) NOT NULL,state VARCHAR(30) NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,reference VARCHAR(150) NOT NULL DEFAULT '',last_error VARCHAR(1000) NOT NULL DEFAULT '',updated_at VARCHAR(30) NOT NULL,UNIQUE(notification_id,channel),FOREIGN KEY(notification_id) REFERENCES store_notifications(id)",
    'store_return_cases'=>"id $id,order_id INTEGER NOT NULL,item_id INTEGER,message_id INTEGER NOT NULL UNIQUE,quantity INTEGER NOT NULL,state VARCHAR(30) NOT NULL DEFAULT 'requested',reason TEXT NOT NULL,review_note TEXT NOT NULL,restocked INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 0,created_by INTEGER NOT NULL,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id),FOREIGN KEY(item_id) REFERENCES order_items(id),FOREIGN KEY(message_id) REFERENCES store_messages(id),FOREIGN KEY(created_by) REFERENCES users(id)"
   ];foreach($tables as $name=>$cols)$db->exec("CREATE TABLE IF NOT EXISTS $name (".($mysql?str_replace(' TEXT',' LONGTEXT',$cols):$cols).")$tail");
   foreach(['store_production_item'=>'store_production_records(item_id,event,created_at)','store_due_queue'=>'store_item_schedule(due_at,completed_at)','store_returns_state'=>'store_return_cases(state,order_id)','store_delivery_state'=>'store_notification_deliveries(state,updated_at)'] as $name=>$cols)try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1061&&!str_contains($e->getMessage(),'already exists'))throw $e;}
   DataBackup::verifyCounts($db,$before);$report=['backup'=>$backup,'before'=>$before,'after'=>DataBackup::counts($db),'existing_rows_rewritten'=>0,'tables'=>array_keys($tables)];$path=Env::storage().'/backups/migration-011-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';if(file_put_contents($path,Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش مهاجرت ذخیره نشد.');chmod($path,0600);Db::insert($db,'migrations',['version'=>self::VERSION,'applied_at'=>date(DATE_ATOM)]);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}

<?php
 declare(strict_types=1);
 namespace Namira;
 use PDO;
 final class StoreSchema {
 public const VERSION='009_store_catalog';
 public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',[self::VERSION])->fetchColumn();}catch(\PDOException){return false;}}
 public static function migrate(PDO $db):void {
 if(self::ready($db))return;
 $lock=fopen(Env::storage().'/.store-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقای فروشگاه در دسترس نیست.');
 if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقای فروشگاه در جریان است.');}
 try {if(self::ready($db))return;
 $before=DataBackup::counts($db);$backup=DataBackup::create($db,'before-009-store-catalog');
 $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
 $tables=[
 'store_product_details'=>"product_id INTEGER PRIMARY KEY,slug VARCHAR(150) NOT NULL UNIQUE,delivery VARCHAR(20) NOT NULL DEFAULT 'physical',production VARCHAR(20) NOT NULL DEFAULT 'ready',pricing VARCHAR(20) NOT NULL DEFAULT 'fixed',featured INTEGER NOT NULL DEFAULT 0,sort_order INTEGER NOT NULL DEFAULT 0,archived INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 0,payload TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id)",
 'store_categories'=>"id $id,parent_id INTEGER,title VARCHAR(150) NOT NULL,slug VARCHAR(150) NOT NULL UNIQUE,description TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(parent_id) REFERENCES store_categories(id)",
 'store_category_products'=>"product_id INTEGER NOT NULL,category_id INTEGER NOT NULL,PRIMARY KEY(product_id,category_id),FOREIGN KEY(product_id) REFERENCES products(id),FOREIGN KEY(category_id) REFERENCES store_categories(id)",
 'store_attributes'=>"id $id,attribute_key VARCHAR(80) NOT NULL UNIQUE,title VARCHAR(150) NOT NULL,filterable INTEGER NOT NULL DEFAULT 1,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0",
 'store_attribute_values'=>"id $id,attribute_id INTEGER NOT NULL,value_key VARCHAR(80) NOT NULL,title VARCHAR(150) NOT NULL,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0,UNIQUE(attribute_id,value_key),FOREIGN KEY(attribute_id) REFERENCES store_attributes(id)",
 'store_variant_values'=>"variant_id INTEGER NOT NULL,value_id INTEGER NOT NULL,PRIMARY KEY(variant_id,value_id),FOREIGN KEY(variant_id) REFERENCES variants(id),FOREIGN KEY(value_id) REFERENCES store_attribute_values(id)",
 'store_variant_details'=>"variant_id INTEGER PRIMARY KEY,active INTEGER NOT NULL DEFAULT 1,unlimited INTEGER NOT NULL DEFAULT 0,reorder_point INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(variant_id) REFERENCES variants(id)",
 'store_media'=>"id $id,product_id INTEGER NOT NULL,path VARCHAR(255) NOT NULL,preview_path VARCHAR(255) NOT NULL,mime VARCHAR(80) NOT NULL,bytes INTEGER NOT NULL,sha256 VARCHAR(64) NOT NULL,alt VARCHAR(255) NOT NULL,sort_order INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id)",
 'store_inventory_movements'=>"id $id,variant_id INTEGER NOT NULL,delta INTEGER NOT NULL,before_stock INTEGER NOT NULL,after_stock INTEGER NOT NULL,reason TEXT NOT NULL,user_id INTEGER NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(variant_id) REFERENCES variants(id),FOREIGN KEY(user_id) REFERENCES users(id)",
 'store_audit'=>"id $id,user_id INTEGER NOT NULL,action VARCHAR(80) NOT NULL,entity VARCHAR(40) NOT NULL,entity_id INTEGER NOT NULL,before_value TEXT NOT NULL,after_value TEXT NOT NULL,reason TEXT NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id)"
 ];
 foreach($tables as $name=>$cols)$db->exec("CREATE TABLE IF NOT EXISTS $name (".($mysql?str_replace(' TEXT',' LONGTEXT',$cols):$cols).")$tail");
 foreach(['store_catalog_public'=>'store_product_details(archived,delivery,production,sort_order)','store_category_lookup'=>'store_category_products(category_id,product_id)','store_value_lookup'=>'store_variant_values(value_id,variant_id)','store_media_product'=>'store_media(product_id,sort_order)','store_stock_history'=>'store_inventory_movements(variant_id,created_at)'] as $name=>$cols){try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1061&&!str_contains($e->getMessage(),'already exists'))throw $e;}}
 DataBackup::verifyCounts($db,$before);$report=['version'=>self::VERSION,'backup'=>$backup,'before'=>$before,'after'=>DataBackup::counts($db),'existing_rows_rewritten'=>0,'tables_added'=>array_keys($tables)];
 $file=Env::storage().'/backups/migration-009-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';if(file_put_contents($file,Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش ارتقای فروشگاه ذخیره نشد.');chmod($file,0600);
 Db::insert($db,'migrations',['version'=>self::VERSION,'applied_at'=>date(DATE_ATOM)]);
 }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 }

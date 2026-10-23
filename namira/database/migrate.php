<?php
declare(strict_types=1);
use Namira\Db;
return static function(PDO $db): void {
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(30) NOT NULL)$tail");
    if(Db::query($db,'SELECT version FROM migrations WHERE version=?',['001_initial'])->fetch()) return;
    $tables=[
    'users'=>"id $id,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(30) NOT NULL,active INTEGER NOT NULL DEFAULT 1",
    'martyrs'=>"id $id,code VARCHAR(80) NOT NULL UNIQUE,first_name VARCHAR(150) NOT NULL,last_name VARCHAR(150) NOT NULL,father_name VARCHAR(150) NOT NULL DEFAULT '',province VARCHAR(100) NOT NULL DEFAULT '',city VARCHAR(100) NOT NULL DEFAULT '',job VARCHAR(150) NOT NULL DEFAULT '',organization VARCHAR(150) NOT NULL DEFAULT '',birth_date VARCHAR(40) NOT NULL DEFAULT '',death_date VARCHAR(40) NOT NULL DEFAULT '',burial_place VARCHAR(255) NOT NULL DEFAULT '',summary TEXT NOT NULL,biography TEXT NOT NULL,will_text TEXT NOT NULL,memories TEXT NOT NULL,published INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(30) NOT NULL",
    'categories'=>"id $id,name VARCHAR(150) NOT NULL UNIQUE",
    'martyr_categories'=>'martyr_id INTEGER NOT NULL,category_id INTEGER NOT NULL,PRIMARY KEY(martyr_id,category_id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(category_id) REFERENCES categories(id)',
    'images'=>"id $id,martyr_id INTEGER NOT NULL,sequence_no INTEGER NOT NULL,title VARCHAR(255) NOT NULL,designer VARCHAR(150) NOT NULL DEFAULT '',license_text TEXT NOT NULL,kind VARCHAR(20) NOT NULL DEFAULT 'design',original_path VARCHAR(255) NOT NULL,preview_path VARCHAR(255) NOT NULL,thumb_path VARCHAR(255) NOT NULL,sha256 VARCHAR(64) NOT NULL UNIQUE,mime VARCHAR(50) NOT NULL,width INTEGER NOT NULL,height INTEGER NOT NULL,bytes INTEGER NOT NULL,downloads INTEGER NOT NULL DEFAULT 0,published INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(30) NOT NULL,UNIQUE(martyr_id,sequence_no),FOREIGN KEY(martyr_id) REFERENCES martyrs(id)",
    'design_requests'=>"id $id,tracking VARCHAR(64) NOT NULL UNIQUE,martyr_name VARCHAR(255) NOT NULL,martyr_code VARCHAR(80) NOT NULL DEFAULT '',requester VARCHAR(150) NOT NULL,phone VARCHAR(20) NOT NULL,notes TEXT NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'submitted',created_at VARCHAR(30) NOT NULL",
    'request_files'=>"id $id,request_id INTEGER NOT NULL,path VARCHAR(255) NOT NULL,mime VARCHAR(50) NOT NULL,FOREIGN KEY(request_id) REFERENCES design_requests(id)",
    'rate_limits'=>'bucket VARCHAR(100) PRIMARY KEY,hits INTEGER NOT NULL,expires INTEGER NOT NULL',
    'audit_logs'=>"id $id,user_id INTEGER,action VARCHAR(100) NOT NULL,target VARCHAR(255) NOT NULL,created_at VARCHAR(30) NOT NULL",
    'products'=>"id $id,title VARCHAR(255) NOT NULL,category VARCHAR(100) NOT NULL,description TEXT NOT NULL,image_id INTEGER,published INTEGER NOT NULL DEFAULT 1,FOREIGN KEY(image_id) REFERENCES images(id)",
    'variants'=>"id $id,product_id INTEGER NOT NULL,sku VARCHAR(100) NOT NULL UNIQUE,attributes VARCHAR(255) NOT NULL,price INTEGER NOT NULL,sale_price INTEGER,stock INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(product_id) REFERENCES products(id)",
    'carts'=>'token VARCHAR(64) PRIMARY KEY,created_at VARCHAR(30) NOT NULL',
    'cart_items'=>'cart_token VARCHAR(64) NOT NULL,variant_id INTEGER NOT NULL,quantity INTEGER NOT NULL,PRIMARY KEY(cart_token,variant_id),FOREIGN KEY(cart_token) REFERENCES carts(token),FOREIGN KEY(variant_id) REFERENCES variants(id)',
    'discounts'=>'code VARCHAR(80) PRIMARY KEY,percent INTEGER NOT NULL,max_uses INTEGER NOT NULL,uses INTEGER NOT NULL DEFAULT 0,min_total INTEGER NOT NULL DEFAULT 0,expires VARCHAR(30) NOT NULL',
    'orders'=>"id $id,token VARCHAR(64) NOT NULL UNIQUE,checkout_key VARCHAR(64) NOT NULL UNIQUE,cart_token VARCHAR(64) NOT NULL,customer VARCHAR(150) NOT NULL,phone VARCHAR(20) NOT NULL,address TEXT NOT NULL,postal_code VARCHAR(10) NOT NULL,subtotal INTEGER NOT NULL,discount INTEGER NOT NULL,shipping INTEGER NOT NULL,total INTEGER NOT NULL,discount_code VARCHAR(80),status VARCHAR(40) NOT NULL,tracking VARCHAR(100) NOT NULL DEFAULT '',created_at VARCHAR(30) NOT NULL",
    'order_items'=>"id $id,order_id INTEGER NOT NULL,variant_id INTEGER NOT NULL,title VARCHAR(255) NOT NULL,attributes VARCHAR(255) NOT NULL,price INTEGER NOT NULL,quantity INTEGER NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id),FOREIGN KEY(variant_id) REFERENCES variants(id)",
    'payments'=>"id $id,order_id INTEGER NOT NULL UNIQUE,driver VARCHAR(30) NOT NULL,reference VARCHAR(100) NOT NULL UNIQUE,status VARCHAR(30) NOT NULL,amount INTEGER NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id)",
    'order_history'=>"id $id,order_id INTEGER NOT NULL,status VARCHAR(40) NOT NULL,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id)",
    'return_requests'=>"id $id,order_id INTEGER NOT NULL UNIQUE,reason TEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'requested',created_at VARCHAR(30) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id)"
    ];
    foreach($tables as $name=>$columns) $db->exec("CREATE TABLE IF NOT EXISTS $name ($columns)$tail");
    // Keep DDL restartable on MySQL (DDL commits implicitly).
    foreach(['images_martyr'=>'images(martyr_id,published)','martyrs_geo'=>'martyrs(province,city)'] as $name=>$columns) {
        try { $db->exec("CREATE INDEX $name ON $columns"); } catch(PDOException $e) { if(!in_array((int)($e->errorInfo[1]??0),[1061],true)&&!str_contains($e->getMessage(),'already exists')) throw $e; }
    }
    Db::insert($db,'migrations',['version'=>'001_initial','applied_at'=>date('Y-m-d H:i:s')]);
};

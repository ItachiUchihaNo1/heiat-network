<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class BalePaymentSchema {
 public const VERSION='013_bale_payments';
 public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',[self::VERSION])->fetchColumn();}catch(\PDOException){return false;}}
 public static function migrate(PDO $db):void {
  if(self::ready($db))return;if(!StoreOrderSchema::ready($db))throw new HttpError(503,'ساختار سفارش‌ها آماده نیست.');
  $lock=fopen(Env::storage().'/.bale-upgrade.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new HttpError(409,'ارتقای پرداخت در جریان است.');
  try{if(self::ready($db))return;$before=DataBackup::counts($db);$backup=DataBackup::create($db,'before-013-bale-payments');$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
   $db->exec("CREATE TABLE IF NOT EXISTS bale_payments (attempt_id INTEGER PRIMARY KEY,state VARCHAR(30) NOT NULL,payer_id VARCHAR(30),transaction_id VARCHAR(150) UNIQUE,receipt VARCHAR(150),message_id VARCHAR(30),updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(attempt_id) REFERENCES store_payment_attempts(id))".($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
   DataBackup::verifyCounts($db,$before);$report=Env::storage().'/backups/migration-013-'.bin2hex(random_bytes(8)).'.json';file_put_contents($report,Protocol::encode(['backup'=>$backup,'before'=>$before,'after'=>DataBackup::counts($db),'existing_rows_rewritten'=>0]),LOCK_EX);chmod($report,0600);Db::insert($db,'migrations',['version'=>self::VERSION,'applied_at'=>date(DATE_ATOM)]);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Durable external delivery interface. No production provider is silently simulated. */
final class StoreDelivery {
 public static function queue(PDO $db,int $notification):void{
  if(!StoreOperationsSchema::ready($db))return;$s=StoreSettings::get($db);foreach(['sms','email'] as $channel){if(empty($s['notify_'.$channel]))continue;if(Db::query($db,'SELECT id FROM store_notification_deliveries WHERE notification_id=? AND channel=?',[$notification,$channel])->fetchColumn())continue;Db::insert($db,'store_notification_deliveries',['notification_id'=>$notification,'channel'=>$channel,'state'=>'queued','updated_at'=>date('Y-m-d H:i:s')]);}
 }
 public static function dispatch(PDO $db,int $limit=50):int{
  $count=0;foreach(Db::query($db,"SELECT id FROM store_notification_deliveries WHERE state IN('queued','retry') ORDER BY id LIMIT ".max(1,min(100,$limit)))->fetchAll() as $row){Db::transaction($db,function()use($db,$row,&$count){$r=Db::query($db,'SELECT * FROM store_notification_deliveries WHERE id=?'.StoreAccess::lock($db),[$row['id']])->fetch();if(!in_array($r['state'],['queued','retry'],true))return;$test=Env::get('APP_ENV')==='testing'&&Env::get('STORE_NOTIFICATION_DRIVER')==='test';$state=$test?'test_delivered':'not_configured';Db::query($db,'UPDATE store_notification_deliveries SET state=?,attempts=attempts+1,reference=?,last_error=?,updated_at=? WHERE id=?',[$state,$test?'TEST-NOTIFICATION-'.$r['id']:'',$test?'':'اتصال معتبر سرویس ارسال تنظیم نشده؛ هیچ پیام خارجی ارسال نشده است.',date('Y-m-d H:i:s'),$r['id']]);$count++;});}return $count;
 }
}

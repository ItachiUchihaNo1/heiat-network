<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreAdmin {
 public static function show(PDO $db,array $user,string $tab):void {
 if(!StoreOrderSchema::ready($db))throw new HttpError(503,'ارتقای سفارش‌ها لازم است.');$old=$_SESSION['commerce_feedback']['input']??[];unset($_SESSION['commerce_feedback']);$data=compact('db','user','tab','old');
 if(in_array($tab,['store-operations','orders','store-dashboard','store-reports'],true)){$data['result']=StoreOrders::listing($db,$user,$_GET);if(in_array($tab,['store-dashboard','store-reports'],true))$data['stats']=StoreFinance::dashboard($db,$user,$_GET);}
 elseif($tab==='returns')StoreAccess::require($db,$user,'orders');elseif($tab==='store-notifications'&&!Permissions::allows($db,$user,'shop.manage'))throw new HttpError(403,'مشاهده سوابق اعلان مجاز نیست.');elseif($tab==='store-forms')StoreAccess::require($db,$user,'orders');elseif($tab==='store-coupons')StoreAccess::require($db,$user,'finance');elseif($tab==='store-settings'&&!Permissions::allows($db,$user,'settings.manage'))throw new HttpError(403,'مدیریت تنظیمات مجاز نیست.');
 view('store-operations',$data);
 }
}

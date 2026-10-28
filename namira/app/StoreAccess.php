<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreAccess {
 public static function roles():array{return ['store_operator'=>['اپراتور فروش',['dashboard.view','store.portal','store.orders','store.messages']], 'store_designer'=>['طراح فروشگاه',['dashboard.view','store.portal','store.design']], 'store_production'=>['تولید و انبار',['dashboard.view','store.portal','store.production']], 'store_shipping'=>['ارسال فروشگاه',['dashboard.view','store.portal','store.shipping']], 'store_finance'=>['مالی فروشگاه',['dashboard.view','store.portal','store.finance','store.reports']]];}
 public static function allows(PDO $db,array|false $actor,string $right):bool{return Permissions::allows($db,$actor,'shop.manage')||Permissions::allows($db,$actor,'store.'.$right);}
 public static function require(PDO $db,array|false $actor,string $right):array {if(!$actor||!self::allows($db,$actor,$right))throw new HttpError(403,'مجوز این عملیات فروشگاه را ندارید.');return $actor;}
 public static function owner(PDO $db,int $order,array $actor):bool{return (bool)Db::query($db,'SELECT order_id FROM store_order_details WHERE order_id=? AND user_id=?',[$order,$actor['id']])->fetchColumn();}
 public static function item(PDO $db,int $id,array $actor,string $right='read'):array {
 $i=Db::query($db,'SELECT d.*,i.order_id,i.variant_id,i.title,i.attributes,i.quantity,i.price FROM store_item_details d JOIN order_items i ON i.id=d.item_id WHERE d.item_id=?',[$id])->fetch();if(!$i)throw new HttpError(404,'قلم سفارش پیدا نشد.');
 $owner=self::owner($db,(int)$i['order_id'],$actor);$assigned=(int)$i['assigned_user_id']===(int)$actor['id']&&self::allows($db,$actor,'design');
 if($right==='customer'?!$owner:($right==='design'?!($assigned||self::allows($db,$actor,'orders')):(!$owner&&!$assigned&&!self::allows($db,$actor,'orders')&&!self::allows($db,$actor,'production')&&!self::allows($db,$actor,'shipping')&&!self::allows($db,$actor,'finance'))))throw new HttpError(404,'قلم سفارش پیدا نشد.');if(!$owner&&!$assigned&&!self::allows($db,$actor,'orders')&&!self::allows($db,$actor,'finance')&&($i['delivery']==='digital'||!Db::query($db,"SELECT id FROM payments WHERE order_id=? AND status='verified'",[$i['order_id']])->fetchColumn()))throw new HttpError(404,'قلم مجاز عملیاتی پیدا نشد.');return $i;
 }
 public static function audit(PDO $db,array $actor,string $action,string $entity,int $id,array $before,array $after,string $reason=''):void{Db::insert($db,'store_audit',['user_id'=>$actor['id'],'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'before_value'=>Protocol::encode($before),'after_value'=>Protocol::encode($after),'reason'=>$reason,'created_at'=>date(DATE_ATOM)]);}
 public static function lock(PDO $db):string{return $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 public static function money(int $amount):int{if($amount<0||$amount>2000000000)throw new HttpError(422,'مبلغ از محدوده مجاز بیشتر است.');return $amount;}
}

<?php
declare(strict_types=1);
require __DIR__.'/store-orders.php';
use Namira\{StoreSettings,StoreCart,StoreOrders,Protocol,Db};
$start=$checks;putenv('APP_ENV=testing');putenv('PAYMENT_DRIVER=test');asActor($buyer);
foreach(StoreCart::items($db,$buyer) as $row)StoreCart::quantity($db,$buyer,(int)$row['id'],0);
$before=StoreSettings::get($db);
$method=['id'=>'postpaid','title'=>'پس‌کرایه','base'=>5000,'per_kg'=>2000,'free_above'=>100000,'collect_on_delivery'=>true,'provinces'=>[],'cities'=>[]];
StoreSettings::save($db,$admin,['shipping'=>[$method]]);$settings=StoreSettings::get($db);
ok($settings['sales_terms']===$before['sales_terms'],'shipping save preserves policy');
ok(StoreSettings::shipping($settings,'postpaid',[],10000,4000)===0,'postpaid does not collect carriage online');
$restricted=$settings;$restricted['shipping'][0]['provinces']=['محدوده آزمایش'];no(fn()=>StoreSettings::shipping($restricted,'postpaid',['province'=>'دیگر'],10000,4000),422,'postpaid retains destination restrictions');
[$pp,$pv]=product('physical','ready');StoreCart::add($db,$buyer,$pv,1);$po=checkout($buyer,['shipping_method'=>'postpaid']);
$details=StoreOrders::get($db,$buyer,(int)$po['id']);ok((int)$po['shipping']===0,'persisted invoice excludes postpaid charge');ok(!empty(Protocol::decode($details['o']['payload'])['shipping_collect']),'order snapshots collection at delivery');
$html=render('store-order',$details+['db'=>$db,'user'=>$buyer,'old'=>[]]);ok(str_contains($html,'پس‌کرایه؛ پرداخت جداگانه به شرکت حمل'),'customer sees separate carriage obligation');
StoreSettings::save($db,$admin,['shipping'=>[['id'=>'postpaid','title'=>'ارسال عادی','base'=>7000,'per_kg'=>0,'free_above'=>0]]]);$html=render('store-order',StoreOrders::get($db,$buyer,(int)$po['id'])+['db'=>$db,'user'=>$buyer,'old'=>[]]);ok(str_contains($html,'پس‌کرایه؛ پرداخت جداگانه به شرکت حمل'),'historical postpaid promise survives settings changes');
ok(StoreSettings::shipping(StoreSettings::get($db),'postpaid',[],10000,4000)===7000,'normal shipping calculation unchanged');
$html=render('footer',['db'=>$db,'user'=>false]);ok(!str_contains($html,'همراهان نامیرا'),'companions omitted as requested');ok(substr_count($html,'trustseal.enamad.ir')===1&&str_contains($html,'/assets/enamad.png'),'official Enamad retained');ok(str_contains($html,'راهنما و پشتیبانی'),'shared footer renders');
echo ($checks-$start)." footer and postpaid checks passed\n";

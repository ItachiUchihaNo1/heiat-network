<?php
declare(strict_types=1);
require __DIR__.'/bale-payments.php';
use Namira\{StoreCart,StoreOrders,Security};
putenv('APP_ENV=testing');putenv('ADMIN_APP_URL=https://admin.naamiraa.example.test');asActor($buyer);
foreach(StoreCart::items($db,$buyer) as $row)StoreCart::quantity($db,$buyer,(int)$row['id'],0);
[$uiProduct,$uiVariant]=product('physical','ready');StoreCart::add($db,$buyer,$uiVariant,1);
$target=getenv('CHECKOUT_UI_DIR')?:BASE_PATH.'/storage/checkout-ui';if(!is_dir($target))mkdir($target,0700,true);
file_put_contents($target.'/cart.html',render('store-cart',['db'=>$db,'user'=>$buyer]));
file_put_contents($target.'/order.html',render('store-order',StoreOrders::get($db,$buyer,(int)$timeout['id'])+['db'=>$db,'user'=>$buyer,'old'=>[]]));
$csp=(new ReflectionClass(Security::class))->getConstant('CSP');file_put_contents($target.'/policy.json',json_encode(['before'=>$csp,'after'=>str_replace("form-action 'self'","form-action 'self' https://ble.ir",$csp)]));

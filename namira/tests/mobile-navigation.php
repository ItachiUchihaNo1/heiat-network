<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\MobileNavigation as N;
$_SESSION=[];$checks=0;
function ok(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException($label);$checks++;echo 'PASS '.$label.PHP_EOL;}
$guest=N::items('home',false,true);
ok(array_column($guest['bottom'],'label')===['خانه','تصاویر','فروشگاه','هنرمندان','حساب کاربری'],'five bottom destinations in RTL source order');
ok(count(array_filter($guest['bottom'],fn($x)=>$x['active']))===1,'one home active destination');
ok(str_contains($guest['bottom'][4]['href'],'page=member-login'),'guest account uses existing member login');
foreach(['image'=>'gallery','profile'=>'gallery','artist'=>'artists','product'=>'shop','cart'=>'shop','store-order'=>'account','store-account'=>'account','member-login'=>'account'] as $page=>$section){
 $m=N::items($page,false,true);ok(N::section($page)===$section&&count(array_filter($m['bottom'],fn($x)=>$x['active']))===1,'active parent for '.$page);
}
$member=N::items('account',['id'=>1],true);ok(str_contains($member['bottom'][4]['href'],'page=account'),'member links shared account');
$links=array_merge(...array_column($guest['groups'],'items'));$labels=array_column($links,'label');
ok(in_array('تماس با ما',$labels,true)&&in_array('سؤالات متداول',$labels,true)&&in_array('قوانین و مقررات',$labels,true),'information links use existing store sections');
ok(!in_array('بانک شهدا',$labels,true),'no standalone martyr bank added');
ok(in_array('/index.php?page=gallery#gallery-filters',array_column($links,'href'),true),'categories link targets existing gallery controls');
$legacy=N::items('home',false,false);$legacyLinks=array_merge(...array_column($legacy['groups'],'items'));
ok(!in_array('store-info',array_column($legacyLinks,'page'),true),'unavailable store routes omitted before migration');
foreach($links as $link)ok(str_starts_with($link['href'],'/index.php?page=')&&!str_contains($link['href'],'javascript:'),'same-origin existing route: '.$link['label']);
ok(str_contains(N::icon('home'),'aria-hidden="true"')&&!str_contains(N::icon('unknown'),'<script'),'decorative inline icons safe');
echo $checks.' navigation model checks passed'.PHP_EOL;

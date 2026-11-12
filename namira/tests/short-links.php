<?php
declare(strict_types=1);
require __DIR__.'/clean-urls.php';
use Namira\{ShortLinks,Db,CleanUrls as C};
$start=$checks;putenv('PUBLIC_APP_URL=https://naamiraa.ir');putenv('ADMIN_APP_URL=https://admin.naamiraa.ir');
foreach(['profile'=>[$person,'m'],'image'=>[$image,'i']] as $route=>[$id,$prefix]){
 $short='/'.$prefix.'/'.$id;$parsed=C::resolve($short,['page'=>'admin','id'=>999]);
 ok($parsed['page']===$route&&$parsed['id']===$id,'short path overrides hostile query '.$route);
 ok(ShortLinks::url($route,$id)==='https://naamiraa.ir'.$short,'stable public short link '.$route);
 $destination=C::canonical($route,$parsed,$short,'GET');ok($destination===C::path($route,['id'=>$id]),'short link redirects to public canonical '.$route);
 ok(C::canonical($route,$parsed,$destination,'GET')===null,'short link canonical has no redirect loop '.$route);
 ok(C::canonical($route,$parsed,$short,'POST')===null,'short route does not redirect POST '.$route);
}
$before=ShortLinks::url('profile',$person);Db::query($db,'UPDATE martyrs SET first_name=? WHERE id=?',['تغییر عنوان آزمایشی',$person]);C::bind($db);ok(ShortLinks::url('profile',$person)===$before,'name changes never change short link');ok(C::canonical('profile',['id'=>$person],'/m/'.$person,'GET')===C::path('profile',['id'=>$person]),'short link always follows current slug');
foreach(['/m/0','/i/-1','/m/abc','/m/1/extra','/i/999999999999999999999999'] as $bad)reject(fn()=>C::resolve($bad,[]),404,'invalid short link rejected');
reject(fn()=>C::canonical('profile',['id'=>$privatePerson],'/m/'.$privatePerson,'GET'),404,'unpublished martyr stays private');
Db::query($db,'UPDATE images SET published=0 WHERE id=?',[$image]);C::bind($db);reject(fn()=>C::canonical('image',['id'=>$image],'/i/'.$image,'GET'),404,'unpublished image stays private');Db::query($db,'UPDATE images SET published=1 WHERE id=?',[$image]);C::bind($db);
ob_start();view('share-tools',['sharePage'=>'image','shareId'=>$image,'shareTitle'=>'عنوان <img src=x>']);$markup=ob_get_clean();ok(str_contains($markup,'https://naamiraa.ir/i/'.$image),'image share renders stable short link');ok(str_contains($markup,'&lt;img')&&!str_contains($markup,'<img src=x>'),'share title is escaped');ok(str_contains($markup,'aria-live="polite"')&&str_contains($markup,'readonly'),'copy status and selectable link accessible');
echo ($checks-$start)." short link checks passed.\n";

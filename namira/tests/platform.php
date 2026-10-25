<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,Archive,Upload,Protocol,PlatformSchema,DataBackup,Permissions,Artists,Media,HttpError};
$root=BASE_PATH.'/storage/platform-test-'.bin2hex(random_bytes(8));mkdir($root,0700,true);putenv('STORAGE_PATH='.$root);putenv('APP_ENV=testing');
$dsn=getenv('TEST_PLATFORM_DB_DSN')?:'sqlite:'.$root.'/db.sqlite';if(!str_starts_with($dsn,'sqlite:')&&!preg_match('/dbname=namira_test_platform(?:;|$)/',$dsn))throw new RuntimeException('Isolated namira_test_platform required.');
putenv('DB_DSN='.$dsn);putenv('DB_USER='.(getenv('TEST_DB_USER')?:''));putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));$db=Db::connect();$_SESSION=[];$tests=0;
function ok(bool $v,string $name):void{global $tests;if(!$v)throw new RuntimeException('FAIL '.$name);$tests++;echo "PASS $name\n";}
function reject(callable $f,int $status,string $name):void{try{$f();}catch(HttpError $e){ok($e->status===$status,$name);return;}throw new RuntimeException('FAIL expected rejection '.$name);}
try{
(require BASE_PATH.'/database/migrate.php')($db);
$adminId=Db::insert($db,'users',['email'=>'admin@example.test','password_hash'=>password_hash('only-a-fixture-password',PASSWORD_DEFAULT),'role'=>'superadmin']);$actor=['id'=>$adminId,'role'=>'superadmin'];$_SESSION['user_id']=$adminId;
$contentId=Db::insert($db,'users',['email'=>'content@example.test','password_hash'=>'fixture-only','role'=>'content']);$content=['id'=>$contentId,'role'=>'content'];
$person=(new Archive($db))->save(['code'=>'000101','first_name'=>"علی ' آزمایشی",'last_name'=>'نگهداری','summary'=>"خط یک\nخط دو",'published'=>'1']);
$person2=(new Archive($db))->save(['code'=>'000102','first_name'=>'دوم','last_name'=>'آزمایشی','published'=>'1']);
$file=$root.'/original.jpg';$g=imagecreatetruecolor(40,60);imagefill($g,0,0,imagecolorallocate($g,70,100,80));imagejpeg($g,$file);unset($g);$image=Upload::image($db,$file,'000101-1.jpg',['designer'=>'نام قدیمی','license_text'=>'مجوز آزمایشی']);
$before=Db::query($db,'SELECT * FROM images WHERE id=?',[$image])->fetch();$personBefore=Db::query($db,'SELECT * FROM martyrs WHERE id=?',[$person])->fetch();
$upgradeLock=fopen($root.'/.platform-upgrade.lock','c');flock($upgradeLock,LOCK_EX);
reject(fn()=>PlatformSchema::migrate($db),409,'parallel migration is blocked before any database mutation');flock($upgradeLock,LOCK_UN);fclose($upgradeLock);
PlatformSchema::migrate($db);$backups=glob($root.'/backups/*.sql');
ok(PlatformSchema::ready($db)&&count($backups)===1,'additive migration creates private database backup first');
ok(Db::query($db,'SELECT * FROM images WHERE id=?',[$image])->fetch()===$before,'existing image metadata and identifiers unchanged');
ok(Db::query($db,'SELECT * FROM martyrs WHERE id=?',[$person])->fetch()===$personBefore,'existing martyr code and data unchanged');
ok(Db::query($db,'SELECT status FROM media_details WHERE image_id=?',[$image])->fetchColumn()==='published','legacy published image remains published');
ok((int)Db::query($db,'SELECT COUNT(*) FROM media_public_martyrs WHERE image_id=?',[$image])->fetchColumn()===1,'legacy relationship copied without changing source relationship');
PlatformSchema::migrate($db);ok(count(glob($root.'/backups/*.sql'))===1,'repeated platform migration does not repeat backup or inserts');
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$restored=new PDO('sqlite:'.$root.'/restored.sqlite');$restored->exec(file_get_contents($backups[0]));ok((int)$restored->query('SELECT COUNT(*) FROM images')->fetchColumn()===1,'SQL backup restores original table counts');ok($restored->query('SELECT first_name FROM martyrs WHERE id='.$person)->fetchColumn()===$personBefore['first_name'],'SQL backup preserves quotes, Persian and newlines');}
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
$restoreDsn=getenv('TEST_PLATFORM_RESTORE_DSN')?:'';if(!preg_match('/dbname=namira_test_platform_restore(?:;|$)/',$restoreDsn))throw new RuntimeException('Isolated backup restore DSN required.');
$restored=new PDO($restoreDsn,getenv('TEST_DB_USER')?:'',getenv('TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$restored->exec(file_get_contents($backups[0]));ok((int)$restored->query('SELECT COUNT(*) FROM images')->fetchColumn()===1,'SQL backup restores original table counts');ok($restored->query('SELECT first_name FROM martyrs WHERE id='.$person)->fetchColumn()===$personBefore['first_name'],'SQL backup preserves quotes, Persian and newlines');
}
ok(Permissions::allows($db,$content,'artists.edit')&&!Permissions::allows($db,$content,'artists.publish'),'registrar can edit artist but cannot publish');
ok(!Permissions::allows($db,['id'=>333,'role'=>'shop'],'media.publish'),'shop role cannot publish media');
$artists=new Artists($db,$actor);$artist=$artists->save(['display_name'=>'هنرمند آزمون','data'=>['summary'=>'معرفی','private_email'=>'private@example.test','private_phone'=>'09120000000'],'public_fields'=>['summary']]);
function artistTransition(Artists $s,int $id,string $to):void{$s->transition($id,$to,['revision'=>$s->get($id)['revision'],'publication_ack'=>'1']);}
artistTransition($artists,$artist,'pending');artistTransition($artists,$artist,'approved');artistTransition($artists,$artist,'published');
$public=$artists->get($artist,true);ok(!str_contains(json_encode($public),'private@example.test')&&!isset($public['payload']),'public artist response excludes private contacts and draft payload');
$artists->save(['display_name'=>'نام ویرایش‌نشده عمومی','revision'=>$artists->get($artist)['revision'],'data'=>['summary'=>'معرفی جدید','private_email'=>'secret@example.test'],'public_fields'=>['summary']],$artist);
ok($artists->get($artist,true)['display_name']==='هنرمند آزمون','published artist name remains frozen during draft edit');
artistTransition($artists,$artist,'pending');ok($artists->get($artist,true)['display_name']==='هنرمند آزمون','artist public snapshot remains visible while draft awaits review');
reject(fn()=>(new Artists($db,$content))->save(['display_name'=>'ویرایش قدیمی','revision'=>$artists->get($artist)['revision']+1,'data'=>[]],$artist),409,'stale artist revision rejected');
$media=new Media($db,$actor);$old=$media->get($image);
$media->save($image,['revision'=>$old['revision'],'title'=>'اثر مشترک','type_id'=>PlatformSchema::termId($db,'media_type','portrait'),'martyrs'=>[$person,$person2],'creators'=>[['artist_id'=>$artist,'role'=>'تصویرگر']],'rights_text'=>'اجازه انتشار آزمایشی','download_allowed'=>'1','reason'=>'تکمیل انتساب']);
ok((new Archive($db))->image($image)!==false,'metadata draft edit does not break existing public download identity');
ok(Db::query($db,'SELECT title FROM images WHERE id=?',[$image])->fetchColumn()===$before['title'],'draft media title does not overwrite published title');
function mediaTransition(Media $s,int $id,string $to):void{$s->transition($id,$to,['revision'=>$s->get($id)['revision'],'publication_ack'=>'1']);}
mediaTransition($media,$image,'pending');mediaTransition($media,$image,'approved');mediaTransition($media,$image,'published');
ok((int)Db::query($db,'SELECT COUNT(*) FROM media_public_martyrs WHERE image_id=?',[$image])->fetchColumn()===2,'one published artwork links to multiple martyrs');
ok((new Archive($db))->images(['q'=>'دوم'])['count']===1,'shared artwork can be found by the secondary martyr name');
ok(Db::query($db,'SELECT artist_id FROM media_public_artists WHERE image_id=?',[$image])->fetchColumn()==$artist,'creator link uses stable artist id');
ok(hash_file('sha256',Upload::absolute($before['original_path']))===hash_file('sha256',$file),'artist attribution never rewrites original image bytes');
reject(fn()=>(new Media($db,$content))->transition($image,'published',['revision'=>$media->get($image)['revision'],'publication_ack'=>'1']),403,'registrar cannot bypass media publication permission');
ok((int)Db::query($db,'SELECT COUNT(*) FROM media_changes WHERE image_id=?',[$image])->fetchColumn()>=4,'attribution and review changes have audit history');

$specialty=PlatformSchema::termId($db,'art_specialty','graphic');
$hiddenArtist=$artists->save(['display_name'=>'رشته خصوصی','data'=>['specialties'=>[$specialty]],'public_fields'=>['summary']]);artistTransition($artists,$hiddenArtist,'pending');artistTransition($artists,$hiddenArtist,'approved');artistTransition($artists,$hiddenArtist,'published');
ok((int)Db::query($db,'SELECT COUNT(*) FROM artist_public_terms WHERE artist_id=?',[$hiddenArtist])->fetchColumn()===0,'hidden artist specialties are excluded from public filter index');
reject(fn()=>(new Artists($db,[]))->listing([],false),403,'private artist listing requires permission');
$privateRead=(new Artists($db,$content))->get($artist);ok(!isset($privateRead['payload'])&&!isset($privateRead['data']['private_email']),'registrar cannot read private artist contact payload');
$id=Namira\Accounts::save($db,$actor,['email'=>'reviewer@example.test','password'=>'fixture-reviewer-password','roles'=>['reviewer'],'active'=>'1']);
$reviewer=Db::query($db,'SELECT * FROM users WHERE id=?',[$id])->fetch();ok(Permissions::allows($db,$reviewer,'media.publish')&&!Permissions::allows($db,$reviewer,'users.manage'),'browser-managed reviewer receives limited grants');
reject(fn()=>Namira\Accounts::save($db,$actor,['id'=>$adminId,'email'=>'admin@example.test','roles'=>['member'],'active'=>'1']),422,'current administrator cannot remove own access');
Namira\Accounts::save($db,$actor,['id'=>$id,'email'=>'reviewer@example.test','roles'=>['member'],'active'=>'1']);$reduced=Db::query($db,'SELECT * FROM users WHERE id=?',[$id])->fetch();ok(!Permissions::allows($db,$reduced,'media.publish'),'role removal revokes previous role grants');
$db->exec('UPDATE martyrs SET published=0 WHERE id='.$person);
ok((new Archive($db))->image($image)!==false,'secondary published martyr keeps shared artwork available when primary is hidden');
$publicCounts=array_column((new Artists($db,[]))->listing([],true)['items'],'work_count','id');ok((int)($publicCounts[$artist]??0)===1&&Namira\AdminQueries::martyrs($db,['completion'=>'no-images'])['count']===0,'shared media counts and no-image filters use all martyr relationships');
$db->exec('UPDATE martyrs SET published=1 WHERE id='.$person);
$photo=$root.'/photo.png';$g=imagecreatetruecolor(25,25);imagepng($g,$photo);unset($g);$newImage=Upload::image($db,$photo,'000101-2.png',['kind'=>'photo','license_text'=>'مجاز']);
ok((new Archive($db))->image($newImage)===false&&Db::query($db,'SELECT status FROM media_details WHERE image_id=?',[$newImage])->fetchColumn()==='draft','new uploaded media remains private until reviewed');

Namira\Accounts::save($db,$actor,['id'=>$id,'email'=>'reviewer@example.test','roles'=>['content','shop'],'active'=>'1']);$_SESSION['user_id']=$id;ok(Namira\Security::requireRole($db,'shop')['id']==$id,'secondary shop role is enforced on legacy shop actions');$_SESSION['user_id']=$adminId;
reject(fn()=>Namira\Accounts::save($db,$actor,['email'=>'weak@example.test','password'=>'short','roles'=>['content'],'active'=>'1']),422,'browser account creation rejects weak passwords');
$photoDetails=$media->get($newImage);$media->save($newImage,['revision'=>$photoDetails['revision'],'title'=>'عکس اصلی آزمایشی','type_id'=>PlatformSchema::termId($db,'media_type','photo'),'martyrs'=>[$person],'rights_text'=>'مجوز نمایش، بدون دانلود اصل']);
mediaTransition($media,$newImage,'pending');mediaTransition($media,$newImage,'approved');mediaTransition($media,$newImage,'published');
ok((new Archive($db))->images()['count']===1,'public main archive excludes published documentary photos');
ok((new Archive($db))->forPerson($person)['count']===2,'person profile retains published documentary media');
ok(count((new Archive($db))->relatedPeople($image))===2,'public work exposes links to every published related martyr');
ok((new Archive($db))->forPerson($person,1,PlatformSchema::termId($db,'media_type','photo'))['count']===1,'profile media type filter returns only the selected type');
ok(count((new Archive($db))->personTypes($person))===2,'profile media tabs use actual published counts');
ok((int)(new Archive($db))->image($newImage)['download_allowed']===0,'publication keeps original download disabled without permission');
$originalDigest=hash_file('sha256',Upload::absolute($before['original_path']));
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
foreach(['dashboard','martyrs','artists','images','import','categories','requests','products','orders','returns','users','reports','settings'] as $tab){
    $_GET=['tab'=>$tab];ob_start();Namira\AdminPanel::show($db,Db::query($db,'SELECT * FROM users WHERE id=?',[$adminId])->fetch());$html=ob_get_clean();ok(str_contains($html,'admin-sidebar'),'admin '.$tab.' renders without PHP warnings');
}
foreach([['tab'=>'artists','edit'=>$artist],['tab'=>'artists','new'=>1],['tab'=>'images','edit'=>$image],['tab'=>'martyrs','new'=>1],['tab'=>'users','edit'=>$adminId],['tab'=>'users','new'=>1]] as $query){
    $_GET=$query;ob_start();Namira\AdminPanel::show($db,Db::query($db,'SELECT * FROM users WHERE id=?',[$adminId])->fetch());$html=ob_get_clean();ok(str_contains($html,'<form'),'editor '.$query['tab'].' renders without warnings');
}
$_GET=['tab'=>'artists','edit'=>$artist];Namira\Accounts::save($db,$actor,['id'=>$id,'email'=>'reviewer@example.test','roles'=>['reviewer'],'active'=>'1']);$_SESSION['user_id']=$id;ob_start();Namira\AdminPanel::show($db,Db::query($db,'SELECT * FROM users WHERE id=?',[$id])->fetch());$html=ob_get_clean();ok(str_contains($html,'artist-transition')&&!str_contains($html,'artist-save'),'reviewer sees review action without unauthorized edit form');
$_SESSION['user_id']=$adminId;restore_error_handler();
ok(hash_file('sha256',Upload::absolute($before['original_path']))===$originalDigest,'all administration renders leave original file bytes unchanged');
echo "\n$tests platform checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

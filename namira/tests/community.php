<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,Archive,Upload,PlatformSchema,CommunitySchema,Accounts,ArtistAccounts,Artists,Calls,Protocol,IncomingAssets,HttpError};
$root=BASE_PATH.'/storage/community-test-'.bin2hex(random_bytes(8));mkdir($root,0700,true);putenv('APP_ENV=testing');putenv('STORAGE_PATH='.$root);
$dsn=getenv('TEST_COMMUNITY_DB_DSN')?:'sqlite:'.$root.'/db.sqlite';if(!str_starts_with($dsn,'sqlite:')&&!preg_match('/dbname=namira_test_community(?:;|$)/',$dsn))throw new RuntimeException('Isolated community database required.');
putenv('DB_DSN='.$dsn);putenv('DB_USER='.(getenv('TEST_DB_USER')?:''));putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));$db=Db::connect();$_SESSION=[];$tests=0;
function ok(bool $yes,string $name):void{global $tests;if(!$yes)throw new RuntimeException('FAIL '.$name);$tests++;echo "PASS $name\n";}
function denied(callable $f,int $status,string $name):void{try{$f();}catch(HttpError $e){ok($e->status===$status,$name);return;}throw new RuntimeException('FAIL expected rejection '.$name);}
try{
(require BASE_PATH.'/database/migrate.php')($db);
$admin=Db::insert($db,'users',['email'=>'admin@example.test','password_hash'=>password_hash('fixture-admin-password',PASSWORD_DEFAULT),'role'=>'superadmin']);$actor=Db::query($db,'SELECT id,email,role FROM users WHERE id=?',[$admin])->fetch();$_SESSION['user_id']=$admin;
$person=(new Archive($db))->save(['code'=>'000888','first_name'=>'فرد آزمون','last_name'=>'مشارکت','published'=>'1']);
$original=$root.'/old.jpg';$image=imagecreatetruecolor(40,60);imagefill($image,0,0,imagecolorallocate($image,31,100,80));imagejpeg($image,$original);unset($image);$oldImage=Upload::image($db,$original,'000888-1.jpg',['license_text'=>'مجوز قدیمی']);
$before=Db::query($db,'SELECT * FROM images WHERE id=?',[$oldImage])->fetch();
PlatformSchema::migrate($db);$lock=fopen($root.'/.community-upgrade.lock','c');flock($lock,LOCK_EX);denied(fn()=>CommunitySchema::migrate($db),409,'concurrent community migration is blocked');flock($lock,LOCK_UN);fclose($lock);
CommunitySchema::migrate($db);ok(CommunitySchema::ready($db)&&count(glob($root.'/backups/*.sql'))===2,'community upgrade creates its own private backup');
CommunitySchema::migrate($db);ok(count(glob($root.'/backups/*.sql'))===2,'community migration is idempotent');ok(Db::query($db,'SELECT * FROM images WHERE id=?',[$oldImage])->fetch()===$before,'community migration preserves existing archive identity and paths');
Db::transaction($db,function()use($db){Db::insert($db,'app_settings',['setting_key'=>'outer','value'=>'kept','updated_at'=>date(DATE_ATOM)]);try{Db::transaction($db,function()use($db){Db::insert($db,'app_settings',['setting_key'=>'inner','value'=>'discarded','updated_at'=>date(DATE_ATOM)]);throw new RuntimeException('inner rollback');});}catch(RuntimeException){}});ok((int)$db->query("SELECT COUNT(*) FROM app_settings WHERE setting_key IN ('outer','inner')")->fetchColumn()===1&&$db->query("SELECT value FROM app_settings WHERE setting_key='outer'")->fetchColumn()==='kept','nested transaction failure rolls back only its savepoint');
$ownerId=Accounts::register($db,['email'=>'OWNER@example.test','display_name'=>'حساب هنرمند آزمون','password'=>'  exact-fixture-password  ','password_confirm'=>'  exact-fixture-password  ','consent'=>'1','roles'=>['superadmin']]);$owner=Db::query($db,'SELECT id,email,role FROM users WHERE id=?',[$ownerId])->fetch();$hash=Db::query($db,'SELECT password_hash FROM users WHERE id=?',[$ownerId])->fetchColumn();
ok($owner['role']==='member'&&password_verify('  exact-fixture-password  ',$hash),'public registration preserves password bytes and cannot grant staff roles');
$otherId=Accounts::register($db,['email'=>'other@example.test','display_name'=>'کاربر دیگر','password'=>'fixture-other-password','password_confirm'=>'fixture-other-password','consent'=>'1']);$other=Db::query($db,'SELECT id,email,role FROM users WHERE id=?',[$otherId])->fetch();
denied(fn()=>Accounts::register($db,['email'=>'owner@example.test','display_name'=>'تکراری','password'=>'fixture-password','password_confirm'=>'fixture-password','consent'=>'1']),409,'duplicate normalized registration email rejected');
denied(fn()=>Accounts::register($db,['email'=>'weak@example.test','display_name'=>'آزمون','password'=>'short','password_confirm'=>'short','consent'=>'1']),422,'weak public account password rejected');
$artists=new Artists($db,$actor);$artist=$artists->save(['display_name'=>'هنرمند عمومی آزمون','data'=>['private_email'=>'private-artist@example.test'],'public_fields'=>['summary']]);
foreach(['pending','approved','published'] as $state)$artists->transition($artist,$state,['revision'=>$artists->get($artist)['revision'],'publication_ack'=>'1']);
$claim=ArtistAccounts::claim($db,$owner,['artist_id'=>$artist,'evidence'=>'توضیح مستند ارتباط با پروفایل']);
ok(!ArtistAccounts::owns($db,$owner,$artist),'claim alone never grants ownership');
denied(fn()=>(new Artists($db,$owner))->get($artist),403,'unverified claimant cannot read private artist data');
denied(fn()=>ArtistAccounts::review($db,$owner,$claim,['status'=>'approved','note'=>'خودتأییدی','verification_ack'=>'1']),403,'claimant cannot approve own artist-account link');
denied(fn()=>ArtistAccounts::review($db,$actor,$claim,['status'=>'approved','note'=>'بررسی شد']),422,'ownership approval requires explicit acknowledgement');
ArtistAccounts::review($db,$actor,$claim,['status'=>'approved','note'=>'ارتباط با مدارک آزمون بررسی شد','verification_ack'=>'1']);
ok(ArtistAccounts::owns($db,$owner,$artist),'reviewed claim grants access only to linked artist');
$owned=new Artists($db,$owner);ok($owned->get($artist)['data']['private_email']==='private-artist@example.test','verified artist can access own private contact');
$owned->save(['revision'=>$owned->get($artist)['revision'],'display_name'=>'نام پیشنهادی مالک','data'=>['summary'=>'ویرایش مالک'],'public_fields'=>['summary']],$artist);
ok($artists->get($artist,true)['display_name']==='هنرمند عمومی آزمون','owner edits remain private until reviewed publication');
denied(fn()=>(new Artists($db,$other))->save(['display_name'=>'تصاحب','data'=>[]],$artist),403,'other member cannot edit an artist they do not own');
denied(fn()=>$owned->transition($artist,'published',['revision'=>$owned->get($artist)['revision'],'publication_ack'=>'1']),403,'verified ownership does not grant publishing permission');
ArtistAccounts::review($db,$actor,$claim,['status'=>'revoked','note'=>'قطع ارتباط آزمایشی']);
ok(!ArtistAccounts::owns($db,$owner,$artist),'revoking link removes artist edit access while preserving history');
ok((int)Db::query($db,"SELECT COUNT(*) FROM submission_history WHERE entity_type='artist_claim' AND entity_id=?",[$claim])->fetchColumn()>=3,'claim and revocation retain actor history');
$calls=new Calls($db,$actor);$call=$calls->save(['title'=>'فراخوان آزمون','description'=>'توضیحات واقعی برای آزمون گردش فراخوان','terms_text'=>'شرایط انتشار با رضایت هنرمند','max_entries'=>'2','max_file_mb'=>'1','start_at'=>'2020-01-01T00:00','end_at'=>'2099-01-01T00:00','media_types'=>[PlatformSchema::termId($db,'media_type','portrait')],'martyrs'=>[$person]]);
ok($calls->get($call,true)===false,'new call is a private draft');
$calls->transition($call,['status'=>'published','revision'=>$calls->get($call)['revision'],'publication_ack'=>'1']);ok(Calls::accepting($db,$call)['id']==$call,'published call within date range accepts entries');
denied(fn()=>(new Calls($db,$owner))->save(['title'=>'غیرمجاز']),403,'public member cannot create a call');
$calls->transition($call,['status'=>'paused','revision'=>$calls->get($call)['revision']]);denied(fn()=>Calls::accepting($db,$call),422,'paused call cannot accept uploads');
$calls->transition($call,['status'=>'published','revision'=>$calls->get($call)['revision'],'publication_ack'=>'1']);
$source=$root.'/incoming.png';$image=imagecreatetruecolor(30,50);imagefill($image,0,0,imagecolorallocate($image,40,80,150));imagepng($image,$source);unset($image);
$asset=IncomingAssets::save($db,$ownerId,$source);
ok(hash_file('sha256',Upload::absolute($asset['original_path']))===hash_file('sha256',$source),'incoming original stays byte-for-byte unchanged');
ok(getimagesize(Upload::absolute($asset['preview_path']))['mime']==='image/webp','incoming preview is a separate bounded WebP');
ok(IncomingAssets::file($db,$asset['id'],$owner)['id']==$asset['id'],'owner can retrieve own incoming file');
denied(fn()=>IncomingAssets::file($db,$asset['id'],$other),404,'other account cannot retrieve private incoming file');
$newPerson=(new Protocol($db,$actor))->save(0,1,['form_complete'=>'1','data'=>['display_name'=>'پرونده بدون کد آرشیو','identity_status'=>'unknown']]);$created=[];$promoted=IncomingAssets::promote($db,$asset,$newPerson,'اثر بدون نام فایل کددار',PlatformSchema::termId($db,'media_type','portrait'),$created);
ok((int)Db::query($db,'SELECT martyr_id FROM images WHERE id=?',[$promoted])->fetchColumn()===$newPerson,'reviewed attachment can target a stable person id without archive code');
ok((new Archive($db))->image($promoted)===false,'promotion creates a media draft rather than publishing automatically');
ok(hash_file('sha256',Upload::absolute($before['original_path']))===hash_file('sha256',$original),'community work never modifies existing original files');


$artFile=$root.'/art.png';$g=imagecreatetruecolor(45,65);imagefill($g,0,0,imagecolorallocate($g,155,40,60));imagepng($g,$artFile);unset($g);
$body=['call_id'=>$call,'title'=>'اثر ارسالی آزمون','artist_name'=>'هنرمند عمومی آزمون','artist_id'=>$artist,'martyr_id'=>$person,'specialty_id'=>PlatformSchema::termId($db,'art_specialty','graphic'),'type_id'=>PlatformSchema::termId($db,'media_type','portrait'),'rights_text'=>'اجازه نمایش؛ اصل بدون اجازه دانلود','description'=>'شرح اثر','consent'=>'1'];
$ownerSub=new Namira\Submissions($db,$owner);$reviewerSub=new Namira\Submissions($db,$actor);$entry=$ownerSub->submit($body,$artFile);
ok($ownerSub->get($entry)['status']==='submitted'&&$ownerSub->get($entry)['artist_id']===null,'unverified artist selection remains a claim rather than an automatic attribution');
denied(fn()=>(new Namira\Submissions($db,$other))->get($entry),403,'other member cannot inspect a private submission');
denied(fn()=>$ownerSub->review($entry,['status'=>'approved','note'=>'خودتأییدی']),403,'submitter cannot approve own work');
function reviewEntry(Namira\Submissions $service,int $id,string $status,array $extra=[]):void{$service->review($id,['status'=>$status,'note'=>'یادداشت بررسی آزمون','revision'=>$service->get($id)['revision']]+$extra);}
reviewEntry($reviewerSub,$entry,'reviewing',['artist_id'=>$artist,'martyr_id'=>$person]);
denied(fn()=>$reviewerSub->review($entry,['status'=>'approved','revision'=>$reviewerSub->get($entry)['revision'],'note'=>'بدون تطبیق']),422,'submission approval requires an explicit identity match');
reviewEntry($reviewerSub,$entry,'approved',['artist_id'=>$artist,'martyr_id'=>$person,'identity_ack'=>'1','reviewed_rights'=>'اجازه نمایش مورد بررسی قرار گرفت','creator_role'=>'طراح','allow_download'=>'1']);
denied(fn()=>$reviewerSub->publish($entry,['revision'=>$reviewerSub->get($entry)['revision']]),422,'submission publication requires an explicit acknowledgement');
$published=$reviewerSub->publish($entry,['revision'=>$reviewerSub->get($entry)['revision'],'publication_ack'=>'1']);$public=(new Archive($db))->image($published);
ok($public!==false&&(int)$public['download_allowed']===0,'reviewed artwork publishes with original download disabled when author withheld consent');
ok((int)(new Archive($db))->creators($published)[0]['artist_id']===$artist,'published work links to the reviewed artist id');
ok(hash_file('sha256',Upload::absolute($public['original_path']))===hash_file('sha256',$artFile),'submission publication preserves the full-quality original');
$again=$reviewerSub->publish($entry,['revision'=>0,'publication_ack'=>'1']);ok($again===$published,'repeated publication returns the same archive id');
$second=$ownerSub->submit($body+['title'=>'اثر دوم'],$artFile);$beforeAssets=(int)$db->query('SELECT COUNT(*) FROM incoming_assets')->fetchColumn();
denied(fn()=>$ownerSub->submit($body,$artFile),422,'call per-user entry quota is enforced before storing another file');
ok((int)$db->query('SELECT COUNT(*) FROM incoming_assets')->fetchColumn()===$beforeAssets,'quota rejection leaves no extra asset record');
reviewEntry($reviewerSub,$second,'needs_changes');
$changedBody=$body;$changedBody['title']='عنوان اصلاح‌شده';$changedBody['revision']=$ownerSub->get($second)['revision'];
$ownerSub->submit($changedBody,null,null,$second);ok($ownerSub->get($second)['title']==='عنوان اصلاح‌شده'&&$ownerSub->get($second)['status']==='submitted','owner can resubmit only a requested correction with a current revision');
ok(count($ownerSub->get($second)['assets'])===1,'text correction retains the original attachment and file history');
$big=$root.'/oversized.png';file_put_contents($big,file_get_contents($artFile).str_repeat("\0",1048576));
denied(fn()=>(new Namira\Submissions($db,$other))->submit($body,$big),422,'call-specific byte limit is enforced independently of global upload limit');
$community=new Namira\Contributions($db,$owner);$reviews=new Namira\Contributions($db,$actor);$beforeCore=Db::query($db,'SELECT biography FROM martyrs WHERE id=?',[$person])->fetchColumn();
$contribution=$community->submit(['kind'=>'biography','martyr_id'=>$person,'text'=>'بخش قابل قبول و اطلاعات خصوصی 09121111111','source_text'=>'منبع خانوادگی آزمون','phone'=>'09121111111','consent'=>'1']);
ok(Db::query($db,'SELECT biography FROM martyrs WHERE id=?',[$person])->fetchColumn()===$beforeCore,'public contribution does not edit the master record automatically');
denied(fn()=>(new Namira\Contributions($db,$other))->get($contribution),403,'contribution text and contact details are private to owner and reviewers');
function reviewContribution(Namira\Contributions $service,int $id,string $status):void{$service->review($id,['status'=>$status,'note'=>'بررسی مستند مشارکت','revision'=>$service->get($id)['revision']]);}
reviewContribution($reviews,$contribution,'reviewing');reviewContribution($reviews,$contribution,'partial_approved');
$bundle=(new Protocol($db,$actor))->get($person);$merge=['request_key'=>bin2hex(random_bytes(16)),'revision'=>$reviews->get($contribution)['revision'],'person_revision'=>$bundle['profile']['revision'],'compared_person_id'=>$person,'destination'=>'biography','accepted_text'=>'فقط بخش بررسی‌شده پذیرفته شد.','reason'=>'پذیرش جزئی مطابق منبع','merge_ack'=>'1'];
denied(fn()=>$community->merge($contribution,$merge),403,'contributor cannot merge their own proposal');
$result=$reviews->merge($contribution,$merge);$private=(new Protocol($db,$actor))->get($person);
ok(str_contains($private['sections'][1]['biography'],'فقط بخش بررسی‌شده')&&!str_contains($private['sections'][1]['biography'],'09121111111'),'partial approval copies only the reviewer-selected excerpt');
ok(Db::query($db,'SELECT biography FROM martyrs WHERE id=?',[$person])->fetchColumn()===$beforeCore,'merging contribution creates a draft and leaves published legacy data unchanged');
ok(str_contains($community->get($contribution)['data']['text'],'09121111111'),'original public submission is preserved separately from accepted excerpt');
$again=$reviews->merge($contribution,$merge);ok($again===$result&&(int)Db::query($db,'SELECT COUNT(*) FROM contribution_merges WHERE contribution_id=?',[$contribution])->fetchColumn()===1,'repeated contribution merge is idempotent');
$newContribution=$community->submit(['kind'=>'new_martyr','martyr_name'=>'فرد آزمون تازه','text'=>'پیشنهاد ثبت پرونده','consent'=>'1']);reviewContribution($reviews,$newContribution,'reviewing');reviewContribution($reviews,$newContribution,'approved');
$newResult=$reviews->merge($newContribution,['request_key'=>bin2hex(random_bytes(16)),'revision'=>$reviews->get($newContribution)['revision'],'destination'=>'none','create_new'=>'1','new_name'=>'فرد آزمون تازه','new_code'=>'000889','new_identity_status'=>'unknown','reason'=>'بررسی پیشنهاد و ایجاد پیش‌نویس','merge_ack'=>'1']);
ok((new Archive($db))->find($newResult['martyr_id'])===false,'approved new-person proposal creates an unpublished protocol draft');
ok(hash_file('sha256',Upload::absolute($before['original_path']))===hash_file('sha256',$original),'submission and partial merge flows preserve all existing original bytes');


ArtistAccounts::claim($db,$owner,['artist_id'=>$artist,'evidence'=>'مدرک تکمیل‌شده ارتباط']);ArtistAccounts::review($db,$actor,$claim,['status'=>'approved','note'=>'اتصال دوباره پس از بررسی','verification_ack'=>'1']);
$owned->save(['revision'=>$owned->get($artist)['revision'],'display_name'=>$owned->get($artist)['display_name'],'data'=>Namira\ProtocolValidator::formValues(Artists::definitions(),$owned->get($artist)['data']),'public_fields_present'=>'1'],$artist);
ok($owned->get($artist)['visible_fields']===[],'deselecting all visibility fields does not restore public defaults');
$bytes=file_get_contents(Upload::absolute($asset['original_path']));file_put_contents(Upload::absolute($asset['original_path']),$bytes."\0");
denied(fn()=>IncomingAssets::verify($asset),409,'modified incoming original fails the stored SHA-256 integrity check');file_put_contents(Upload::absolute($asset['original_path']),$bytes);
$oldLimit=ini_get('memory_limit');ini_set('memory_limit','512M');
$high=$root.'/high.jpg';$g=imagecreatetruecolor(8000,6000);imagefill($g,0,0,imagecolorallocate($g,30,100,80));imagejpeg($g,$high,95);unset($g);
$untrusted=$root.'/untrusted.webp';$g=imagecreatetruecolor(1600,1200);imagefill($g,0,0,imagecolorallocate($g,180,25,30));imagewebp($g,$untrusted,80);unset($g);
$verified=$root.'/verified.webp';$g=imagecreatetruecolor(1600,1200);imagefill($g,0,0,imagecolorallocate($g,30,100,80));imagewebp($g,$verified,80);unset($g);ini_set('memory_limit','128M');
$largeBody=array_replace($body,['call_id'=>0,'title'=>'اثر بزرگ برای بررسی اصل']);$large=$ownerSub->submit($largeBody,$high,$untrusted);
reviewEntry($reviewerSub,$large,'reviewing',['artist_id'=>$artist,'martyr_id'=>$person]);reviewEntry($reviewerSub,$large,'approved',['artist_id'=>$artist,'martyr_id'=>$person,'identity_ack'=>'1','reviewed_rights'=>'اصل با اجازه بررسی شد','creator_role'=>'طراح']);
$countImages=(int)$db->query('SELECT COUNT(*) FROM images')->fetchColumn();
denied(fn()=>$reviewerSub->publish($large,['revision'=>$reviewerSub->get($large)['revision'],'publication_ack'=>'1']),422,'large public publication cannot reuse an untrusted submitter companion');
ok((int)$db->query('SELECT COUNT(*) FROM images')->fetchColumn()===$countImages&&$reviewerSub->get($large)['status']==='approved','failed publication rolls back metadata and keeps the review decision');
denied(fn()=>$reviewerSub->publish($large,['revision'=>$reviewerSub->get($large)['revision'],'publication_ack'=>'1'],$verified),422,'reviewer-generated preview requires a separate final visual acknowledgement');
$highId=$reviewerSub->publish($large,['revision'=>$reviewerSub->get($large)['revision'],'publication_ack'=>'1','verified_visual_ack'=>'1'],$verified);$highRow=(new Archive($db))->image($highId);
$thumb=imagecreatefromwebp(Upload::absolute($highRow['preview_path']));$color=imagecolorsforindex($thumb,imagecolorat($thumb,10,10));unset($thumb);
ok(abs($color['green']-100)<15&&$color['red']<60,'public preview uses the authenticated reviewer companion rather than submitter red preview');
ok(hash_file('sha256',Upload::absolute($highRow['original_path']))===hash_file('sha256',$high),'verified high-resolution publication preserves the original under 128 MB PHP memory');
ini_set('memory_limit',$oldLimit);

$photo=$root.'/contribution-photo.png';$g=imagecreatetruecolor(42,59);imagefill($g,0,0,imagecolorallocate($g,90,120,35));imagepng($g,$photo);unset($g);
$photoBody=['kind'=>'photo','martyr_id'=>$person,'text'=>'','rights_text'=>'اجازه بررسی و نمایش پس از تأیید','consent'=>'1'];
$photoContribution=$community->submit($photoBody,[['source'=>$photo]]);
reviewContribution($reviews,$photoContribution,'needs_changes');$photoEdit=$photoBody;$photoEdit['revision']=$community->get($photoContribution)['revision'];$community->submit($photoEdit,[],$photoContribution);
ok(count($community->get($photoContribution)['files'])===1,'photo-only correction can retain the original attachment without resending');
reviewContribution($reviews,$photoContribution,'reviewing');reviewContribution($reviews,$photoContribution,'approved');
$photoRow=$reviews->get($photoContribution);$fid=(int)$photoRow['files'][0]['id'];$bundle=(new Protocol($db,$actor))->get($person);
$badMerge=['request_key'=>bin2hex(random_bytes(16)),'revision'=>$photoRow['revision'],'person_revision'=>$bundle['profile']['revision'],'compared_person_id'=>$person,'destination'=>'biography','accepted_text'=>'این متن نباید پس از خطا باقی بماند','files'=>[$fid],'type_id'=>999999,'reason'=>'آزمون اتمی بودن انتقال','merge_ack'=>'1'];
$beforeBio=$bundle['sections'][1]['biography'];$beforeOriginals=count(glob($root.'/originals/*'));$beforeImages=(int)$db->query('SELECT COUNT(*) FROM images')->fetchColumn();
denied(fn()=>$reviews->merge($photoContribution,$badMerge),422,'invalid attachment type rejects the complete mixed text/file merge');
ok((new Protocol($db,$actor))->get($person)['sections'][1]['biography']===$beforeBio&&(int)$db->query('SELECT COUNT(*) FROM images')->fetchColumn()===$beforeImages&&count(glob($root.'/originals/*'))===$beforeOriginals,'failed mixed merge rolls back draft text, media rows and new original files');
$compareFailure=$badMerge;$compareFailure['request_key']=bin2hex(random_bytes(16));$compareFailure['compared_person_id']=$newPerson;
denied(fn()=>$reviews->merge($photoContribution,$compareFailure),409,'text cannot merge into a different person than the comparison target');
reviewEntry($reviewerSub,$second,'reviewing',['artist_id'=>$artist,'martyr_id'=>$person]);reviewEntry($reviewerSub,$second,'approved',['artist_id'=>$artist,'martyr_id'=>$person,'identity_ack'=>'1','reviewed_rights'=>'اجازه بررسی شد','creator_role'=>'طراح']);

$quotaFileCount=count(glob($root.'/incoming-originals/*'));$quotaRow=Db::query($db,'SELECT stored_bytes FROM incoming_assets WHERE id=?',[$asset['id']])->fetchColumn();$priorUserQuota=getenv('INCOMING_USER_QUOTA_MB');$priorTotalQuota=getenv('INCOMING_TOTAL_QUOTA_MB');
putenv('INCOMING_USER_QUOTA_MB=20');Db::query($db,'UPDATE incoming_assets SET stored_bytes=? WHERE id=?',[20*1048576,$asset['id']]);
denied(fn()=>IncomingAssets::save($db,$ownerId,$source),413,'per-account intake storage quota is enforced before writing files');
ok(count(glob($root.'/incoming-originals/*'))===$quotaFileCount,'quota rejection creates no original file');
putenv('INCOMING_USER_QUOTA_MB=500');putenv('INCOMING_TOTAL_QUOTA_MB=20');
denied(fn()=>IncomingAssets::save($db,$ownerId,$source),503,'global incoming storage quota is enforced transactionally');
Db::query($db,'UPDATE incoming_assets SET stored_bytes=? WHERE id=?',[$quotaRow,$asset['id']]);putenv($priorUserQuota===false?'INCOMING_USER_QUOTA_MB':'INCOMING_USER_QUOTA_MB='.$priorUserQuota);putenv($priorTotalQuota===false?'INCOMING_TOTAL_QUOTA_MB':'INCOMING_TOTAL_QUOTA_MB='.$priorTotalQuota);
ok((int)$quotaRow===$asset['bytes']+filesize(Upload::absolute($asset['preview_path'])),'storage accounting includes both original and private preview bytes');
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
foreach(['overview','profile','artists','claims','submissions','contributions','security'] as $section){
 $_GET=['section'=>$section];ob_start();Namira\CommunityWeb::show($db,'account',$owner);$html=ob_get_clean();ok(str_contains($html,'account-workspace'),'member account '.$section.' renders without warnings');
}
foreach([['section'=>'submissions','id'=>$entry],['section'=>'contributions','id'=>$contribution],['section'=>'artists','id'=>$artist]] as $query){
 $_GET=$query;ob_start();Namira\CommunityWeb::show($db,'account',$owner);$html=ob_get_clean();ok(str_contains($html,'account-workspace'),'member detail '.$query['section'].' renders without warnings');
}
foreach([['calls',[],$owner],['call',['id'=>$call],$owner],['register',[],false],['submit-art',['call_id'=>$call],$owner],['contribute',['martyr_id'=>$person],$owner]] as [$page,$query,$u]){
 $_GET=$query;ob_start();Namira\CommunityWeb::show($db,$page,$u);$html=ob_get_clean();ok(str_contains($html,'<h1>'),'public '.$page.' renders without PHP warnings');
}
foreach(['calls','submissions','contributions','claims'] as $tab){
 $_GET=['tab'=>$tab];ob_start();Namira\AdminPanel::show($db,$actor);$html=ob_get_clean();ok(str_contains($html,'admin-sidebar'),'new admin queue '.$tab.' renders without warnings');
}
foreach([['calls',$call],['submissions',$entry],['submissions',$second],['contributions',$contribution],['contributions',$newContribution],['contributions',$photoContribution],['claims',$claim]] as [$tab,$edit]){
 $_GET=['tab'=>$tab,'edit'=>$edit];ob_start();Namira\AdminPanel::show($db,$actor);$html=ob_get_clean();ok(str_contains($html,'admin-sidebar'),'admin detail '.$tab.' renders without warnings');
}
$_GET=['tab'=>'calls','new'=>1];ob_start();Namira\AdminPanel::show($db,$actor);$html=ob_get_clean();ok(str_contains($html,'call-save'),'new-call form renders without warnings');
restore_error_handler();
$photoMerge=['request_key'=>bin2hex(random_bytes(16)),'revision'=>$reviews->get($photoContribution)['revision'],'destination'=>'none','files'=>[$fid],'reason'=>'پذیرش پیوست تصویری','merge_ack'=>'1'];
$photoResult=$reviews->merge($photoContribution,$photoMerge);$photoMedia=(int)$photoResult['media_ids'][0];
ok((new Archive($db))->image($photoMedia)===false&&Db::query($db,'SELECT status FROM media_details WHERE image_id=?',[$photoMedia])->fetchColumn()==='draft','approved community photo is transferred as a private media draft');
ok(hash_file('sha256',Upload::absolute(Db::query($db,'SELECT original_path FROM images WHERE id=?',[$photoMedia])->fetchColumn()))===hash_file('sha256',$photo),'contribution photo original remains unchanged after selective transfer');



$backup=Namira\DataBackup::create($db,'community-restore-test');$restoreDsn=getenv('TEST_COMMUNITY_RESTORE_DSN')?:('sqlite:'.$root.'/restored-community.sqlite');
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!preg_match('/dbname=namira_test_community_restore(?:;|$)/',$restoreDsn))throw new RuntimeException('Isolated community restore database required.');
$restore=new PDO($restoreDsn,getenv('TEST_DB_USER')?:'',getenv('TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$restore->exec(file_get_contents($root.'/backups/'.$backup['name'].'.sql'));
ok(Namira\DataBackup::counts($restore)===Namira\DataBackup::counts($db),'full community SQL backup restores every table count');
$restoredAsset=Namira\Db::query($restore,'SELECT owner_id,original_path,sha256 FROM incoming_assets WHERE id=?',[$asset['id']])->fetch();$liveAsset=Namira\Db::query($db,'SELECT owner_id,original_path,sha256 FROM incoming_assets WHERE id=?',[$asset['id']])->fetch();ok($restoredAsset===$liveAsset,'restored private asset keeps owner, stable path and original hash');

echo "\n$tests community checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
}catch(Throwable $e){while(ob_get_level())ob_end_clean();fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

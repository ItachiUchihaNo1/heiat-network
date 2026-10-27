<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Archive,Protocol,PlatformSchema,CommunitySchema,MartyrTaxonomySchema,AdminPublishing,Artists,Media,Upload,Calls,Importer,HttpError};
$root=BASE_PATH.'/storage/direct-test-'.bin2hex(random_bytes(8));mkdir($root,0700,true);putenv('STORAGE_PATH='.$root);putenv('APP_ENV=testing');
$dsn=getenv('TEST_DIRECT_DB_DSN')?:'sqlite:'.$root.'/db.sqlite';if(!str_starts_with($dsn,'sqlite:')&&!preg_match('/dbname=namira_test_direct(?:;|$)/',$dsn))throw new RuntimeException('Isolated direct test database required.');
putenv('DB_DSN='.$dsn);putenv('DB_USER='.(getenv('TEST_DB_USER')?:''));putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));$db=Db::connect();$_SESSION=[];$tests=0;
function ok(bool $v,string $name):void{global $tests;if(!$v)throw new RuntimeException('FAIL '.$name);$tests++;echo "PASS $name\n";}
function denied(callable $f,int $status,string $name):void{try{$f();}catch(HttpError $e){ok($e->status===$status,$name);return;}throw new RuntimeException('FAIL expected rejection '.$name);}
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
try{
(require BASE_PATH.'/database/migrate.php')($db);
$admin=Db::insert($db,'users',['email'=>'direct-admin@example.test','password_hash'=>password_hash('fixture-admin-password',PASSWORD_DEFAULT),'role'=>'superadmin']);$actor=['id'=>$admin,'role'=>'superadmin'];$_SESSION['user_id']=$admin;
$contentId=Db::insert($db,'users',['email'=>'direct-content@example.test','password_hash'=>'fixture','role'=>'content']);$content=['id'=>$contentId,'role'=>'content'];
$memberId=Db::insert($db,'users',['email'=>'direct-member@example.test','password_hash'=>'fixture','role'=>'member']);$member=['id'=>$memberId,'role'=>'member'];
PlatformSchema::migrate($db);CommunitySchema::migrate($db);MartyrTaxonomySchema::migrate($db,false);
$p=new Protocol($db,$actor);$draft=$p->quick(['code'=>'000D0','first_name'=>'قدیمی','last_name'=>'خصوصی']);
$person=AdminPublishing::save($db,$actor,'martyr',fn()=>$p->quick(['code'=>'000D1','first_name'=>'شهید','last_name'=>'آزمون مستقیم']));
ok($p->get($person)['profile']['status']==='published'&&(new Archive($db))->find($person)!==false,'minimum quick registration publishes without review or completeness acknowledgement');
ok($p->get($draft)['profile']['status']==='draft'&&(new Archive($db))->find($draft)===false,'untouched legacy drafts remain private');
$before=$p->get($person);$rev=(int)$before['profile']['revision'];
AdminPublishing::save($db,$actor,'martyr',fn()=>$p->save($person,1,['revision'=>$rev,'form_complete'=>'1','data'=>['father_name'=>'پدر آزمایشی','mother_name'=>'مادر خصوصی','short_bio'=>'متن تازه']]));
$after=$p->get($person);$snapshot=Protocol::decode($after['profile']['public_snapshot']);
ok($after['sections'][1]['first_name']==='شهید'&&$after['sections'][1]['father_name']==='پدر آزمایشی','partial save preserves unrelated stored fields');
ok(str_contains($after['profile']['public_snapshot'],'متن تازه')&&!str_contains($after['profile']['public_snapshot'],'مادر خصوصی'),'admin edit immediately updates public snapshot while mother remains private');
denied(fn()=>AdminPublishing::martyr($db,$actor,$person,$rev),409,'stale publication revision cannot overwrite current record');
$rev=(int)$after['profile']['revision'];AdminPublishing::visibility($db,$actor,'martyr',$person,'archived',$rev);ok((new Archive($db))->find($person)===false,'visibility control hides without deleting');AdminPublishing::martyr($db,$actor,$person);ok((new Archive($db))->find($person)!==false,'direct restore needs no review sequence');
$registrar=new Protocol($db,$content);$contentPerson=AdminPublishing::save($db,$content,'martyr',fn()=>$registrar->quick(['code'=>'000D2','first_name'=>'ثبت','last_name'=>'مسئول محتوا']));ok($p->get($contentPerson)['profile']['status']==='published','authorized content editor publishes on save');
denied(fn()=>AdminPublishing::guard($db,['id'=>$memberId,'role'=>'superadmin'],'martyr'),403,'spoofed actor role cannot bypass real database permissions');
$artists=new Artists($db,$actor);$artist=AdminPublishing::save($db,$actor,'artist',fn()=>$artists->save(['display_name'=>'هنرمند مستقیم','data'=>['summary'=>'معرفی عمومی','private_email'=>'secret@example.test'],'public_fields'=>['summary']]));
$public=$artists->get($artist,true);ok($public['display_name']==='هنرمند مستقیم','artist publishes directly without acknowledgement');ok(!str_contains(json_encode($public),'secret@example.test'),'artist private contact is not implicitly published');
$original=$root.'/original.jpg';$g=imagecreatetruecolor(64,96);imagefill($g,0,0,imagecolorallocate($g,23,114,95));imagejpeg($g,$original);unset($g);
$image=AdminPublishing::upload($db,$actor,$original,'000D1-1.jpg',[]);$media=new Media($db,$actor);$m=$media->get($image);$fileBefore=Db::query($db,'SELECT original_path,sha256 FROM images WHERE id=?',[$image])->fetch();
ok($m['status']==='published'&&(new Archive($db))->image($image)!==false,'upload publishes directly with empty optional rights');
$type=PlatformSchema::termId($db,'media_type','portrait');$input=['revision'=>$m['revision'],'title'=>'عنوان مستقیم آزمون','type_id'=>$type,'martyrs'=>[$person],'creators'=>[['artist_id'=>$artist,'role'=>'طراح']],'download_allowed'=>'1'];
AdminPublishing::save($db,$actor,'media',function()use($media,$image,$input){$media->save($image,$input);return $image;});
$m=$media->get($image);ok(Db::query($db,'SELECT title FROM images WHERE id=?',[$image])->fetchColumn()==='عنوان مستقیم آزمون','media metadata edits immediately update public title');ok((int)Db::query($db,'SELECT COUNT(*) FROM media_public_artists WHERE image_id=?',[$image])->fetchColumn()===1,'creator relationship publishes in same transaction');
ok(hash_file('sha256',Upload::absolute($fileBefore['original_path']))===hash_file('sha256',$original),'original quality and bytes remain unchanged');
denied(fn()=>AdminPublishing::media($db,$actor,$image,(int)$input['revision']),409,'stale media publication is rejected');
$input['revision']=$m['revision'];$input['type_id']=999999;$input['title']='نباید ذخیره شود';denied(fn()=>AdminPublishing::save($db,$actor,'media',function()use($media,$image,$input){$media->save($image,$input);return $image;}),422,'invalid media metadata rolls back entire save');ok(Db::query($db,'SELECT title FROM images WHERE id=?',[$image])->fetchColumn()==='عنوان مستقیم آزمون','failed save retains original public metadata');
Importer::commit($db,[['code'=>'000DX','first_name'=>'اکسل','last_name'=>'مستقیم']],$actor);$imported=Db::query($db,'SELECT id,published FROM martyrs WHERE code=?',['000DX'])->fetch();ok((int)$imported['published']===1,'trusted admin Excel import publishes new records');
Importer::commit($db,[['code'=>'000DX','first_name'=>'نباید','last_name'=>'عوض شود']],$actor);ok(Db::query($db,'SELECT first_name FROM martyrs WHERE code=?',['000DX'])->fetchColumn()==='اکسل','duplicate Excel row does not overwrite existing data');
$calls=new Calls($db,$actor);$call=AdminPublishing::save($db,$actor,'call',fn()=>$calls->save(['title'=>'فراخوان مستقیم','description'=>'شرح آزمایشی','terms_text'=>'شرایط شرکت','max_entries'=>'5','max_file_mb'=>'10']));ok($calls->get($call)['status']==='published','call publishes directly on admin save');

$incomingFile=$root.'/incoming.png';$g=imagecreatetruecolor(55,80);imagefill($g,0,0,imagecolorallocate($g,180,60,90));imagepng($g,$incomingFile);unset($g);
$sub=new Namira\Submissions($db,$member);$adminSub=new Namira\Submissions($db,$actor);$entry=$sub->submit(['title'=>'اثر ارسالی مستقیم','artist_name'=>'هنرمند پیشنهادی','martyr_id'=>$person,'type_id'=>$type,'specialty_id'=>PlatformSchema::termId($db,'art_specialty','graphic'),'rights_text'=>'مجوز ارسال','consent'=>'1'],$incomingFile);
ok($sub->get($entry)['status']==='submitted','member upload stays private until admin action');
$publishInput=['revision'=>$adminSub->get($entry)['revision'],'martyr_id'=>$person,'artist_id'=>$artist,'allow_download'=>'1'];$promoted=$adminSub->publish($entry,$publishInput,null,true);
ok((new Archive($db))->image($promoted)!==false,'admin accepts incoming work directly from submitted state');ok(!(bool)Db::query($db,'SELECT download_allowed FROM media_publication WHERE image_id=?',[$promoted])->fetchColumn(),'direct acceptance respects submitter download consent');ok($adminSub->publish($entry,$publishInput,null,true)===$promoted,'direct incoming publication is idempotent');
$con=new Namira\Contributions($db,$member);$adminCon=new Namira\Contributions($db,$actor);$cid=$con->submit(['kind'=>'biography','martyr_id'=>$person,'text'=>'متن پیشنهادی و تلفن خصوصی','phone'=>'09121111111','consent'=>'1']);
ok($con->get($cid)['status']==='submitted','member contribution stays private until admin selection');$bundle=$p->get($person);$mergeInput=['revision'=>$adminCon->get($cid)['revision'],'request_key'=>bin2hex(random_bytes(16)),'martyr_id'=>$person,'person_revision'=>$bundle['profile']['revision'],'compared_person_id'=>$person,'destination'=>'short_bio','accepted_text'=>'متن منتخب عمومی'];$merged=$adminCon->merge($cid,$mergeInput,[],true);$snapshot=$p->get($person)['profile']['public_snapshot'];ok(str_contains($snapshot,'متن منتخب عمومی')&&!str_contains($snapshot,'09121111111'),'selected contribution publishes in one action without private contact');ok($adminCon->merge($cid,$mergeInput,[],true)===$merged,'direct contribution merge is idempotent');
$mediaRoleId=Db::insert($db,'users',['email'=>'limited@example.test','password_hash'=>'fixture','role'=>'media_manager']);$limited=['id'=>$mediaRoleId,'role'=>'media_manager'];$limitedMedia=new Media($db,$limited);$m=$media->get($image);$deniedInput=['revision'=>$m['revision'],'title'=>'نباید منتشر شود','type_id'=>$type,'martyrs'=>[$draft],'creators'=>[]];
denied(fn()=>AdminPublishing::save($db,$limited,'media',function()use($limitedMedia,$image,$deniedInput){$limitedMedia->save($image,$deniedInput);return $image;}),403,'media-only editor cannot publish a private martyr without module access');ok($media->get($image)['title']==='عنوان مستقیم آزمون'&&$p->get($draft)['profile']['status']==='draft','cross-module denial rolls back metadata and related profile');

// Render the exact partial-feedback case from the screenshot under strict warnings.
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$editing=$media->get($image);$old=['action'=>'media-transition','id'=>$image,'status'=>'published','revision'=>$editing['revision']];$user=$actor;$types=Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy='media_type'")->fetchAll();$html=(function()use($db,$editing,$old,$user,$types){ob_start();require BASE_PATH.'/views/admin-media.php';return ob_get_clean();})();
ok(str_contains($html,'value="عنوان مستقیم آزمون"')&&!str_contains($html,'Warning'),'partial failed action feedback preserves title and type without PHP warnings');ok(!str_contains($html,'publication_ack')&&!str_contains($html,'ارسال برای بازبینی'),'media editor has no review or approval controls');
denied(fn()=>AdminPublishing::visibility($db,$actor,'media',$image,'approved',(int)$editing['revision']),410,'removed admin review action cannot be used');
ok((int)Db::query($db,"SELECT COUNT(*) FROM protocol_reviews WHERE martyr_id=? AND to_status IN ('pending','approved')",[$person])->fetchColumn()===0,'direct saves create no intermediate review stages');
echo "\n$tests admin direct checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
}catch(Throwable $e){if($e instanceof Namira\ProtocolErrors)fwrite(STDERR,json_encode($e->errors,JSON_UNESCAPED_UNICODE)."\n");fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

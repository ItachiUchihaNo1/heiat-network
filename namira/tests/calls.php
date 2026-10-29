<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,CallSchema,CallCampaigns,CallForms,CallMartyrFields,AdminPanel,HttpError,ProtocolErrors,Protocol,DataBackup};
$dir=BASE_PATH.'/storage/calls-test-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);putenv('STORAGE_PATH='.$dir);putenv('APP_ENV=testing');
$dsn=getenv('TEST_CALLS_DB_DSN')?:'sqlite:'.$dir.'/db.sqlite';
if(!str_starts_with($dsn,'sqlite:'.$dir.'/')&&!preg_match('/dbname=namira_test_calls(?:;|$)/',$dsn))throw new RuntimeException('Isolated calls DB required');
putenv('DB_DSN='.$dsn);putenv('DB_USER='.(getenv('TEST_DB_USER')?:''));putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));$db=Db::connect();$_SESSION=[];$checks=0;
function check(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function reject(callable $fn,int $status,string $label):void{try{$fn();}catch(HttpError $e){check($e->status===$status,$label);return;}throw new RuntimeException('Expected rejection '.$label);}
function invalid(callable $fn,string $label):void{try{$fn();}catch(ProtocolErrors){check(true,$label);return;}throw new RuntimeException('Expected validation '.$label);}
function user(string $email,string $role='member'):array{global $db;$id=Db::insert($db,'users',['email'=>$email,'password_hash'=>password_hash('fixture-password-only',PASSWORD_DEFAULT),'role'=>$role]);$now=date(DATE_ATOM);Db::insert($db,'account_identities',['user_id'=>$id,'first_name'=>'کاربر','last_name'=>'آزمون','mobile'=>'0912'.str_pad((string)$id,7,'0',STR_PAD_LEFT),'mobile_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);Db::insert($db,'account_profiles',['user_id'=>$id,'display_name'=>'کاربر آزمون','phone'=>'','created_at'=>$now,'updated_at'=>$now]);return ['id'=>$id,'email'=>$email,'role'=>$role];}
try{
 (require BASE_PATH.'/database/migrate.php')($db);
 $aid=Db::insert($db,'users',['email'=>'calls-admin@example.test','password_hash'=>password_hash('fixture-admin-password',PASSWORD_DEFAULT),'role'=>'superadmin']);$admin=['id'=>$aid,'email'=>'calls-admin@example.test','role'=>'superadmin'];$_SESSION['user_id']=$aid;
 AdminPanel::boot($db,$admin);$before=DataBackup::counts($db);CallSchema::migrate($db);CallSchema::migrate($db);
 check(CallSchema::ready($db)&&count(glob($dir.'/backups/migration-012-*.json'))===1,'012 additive migration backed up and idempotent');DataBackup::verifyCounts($db,$before);
 $owner=user('calls-owner@example.test');$other=user('calls-other@example.test');
 $catalog=CallMartyrFields::catalog($db);check(isset($catalog['birth_date'],$catalog['first_name'],$catalog['education_degree'],$catalog['burial_place']),'martyr field catalog comes from canonical simple editor');
 $fields=CallForms::definitions([['key'=>'technique','label'=>'تکنیک','type'=>'select','required'=>true,'options'=>[['value'=>'ink','label'=>'مرکب'],['value'=>'digital','label'=>'دیجیتال']]]]);
 check(CallForms::validate($fields,['technique'=>'ink'],true)['technique']==='ink','custom select validates stable option value');
 invalid(fn()=>CallForms::validate($fields,['technique'=>'bad'],true),'unknown option rejected');
 $fields2=CallForms::definitions($fields);check($fields2[0]['options']===$fields[0]['options'],'form option keys survive builder reload');
 $call=CallCampaigns::save($db,$admin,['title'=>'فراخوان آزمون','description'=>'فراخوان هنری و خاطره','terms_text'=>'شرایط اختصاصی آزمون','start_at'=>date('Y-m-d\TH:i',time()-3600),'end_at'=>date('Y-m-d\TH:i',time()+86400),'mode'=>'competition','edit_policy'=>'until_deadline','accepting'=>1,'max_batch_works'=>['mode'=>'unlimited'],'max_user_works'=>['mode'=>'limited','value'=>8],'common_fields'=>[['key'=>'city_note','label'=>'توضیح مشترک','type'=>'text']],'martyr_fields'=>['first_name'=>['required'=>true],'last_name'=>['required'=>true],'birth_date'=>['required'=>false]]]);
 $type=CallCampaigns::saveContentType($db,$admin,['title'=>'تصویرسازی ویژه','mode'=>'files','active'=>1]);
 $textType=CallCampaigns::saveContentType($db,$admin,['title'=>'خاطره','mode'=>'text','text_required'=>1,'active'=>1]);
 $section=CallCampaigns::saveSection($db,$admin,$call,['title'=>'بخش تصویر','content_type_id'=>$type,'active'=>1,'min_files'=>1,'fields'=>$fields]);
 $textSection=CallCampaigns::saveSection($db,$admin,$call,['title'=>'خاطره همراه پیوست','content_type_id'=>$textType,'active'=>1,'min_files'=>0,'text_enabled'=>1]);
 $c=CallCampaigns::get($db,$call);CallCampaigns::state($db,$admin,$call,['status'=>'published','revision'=>$c['revision']]);$c=CallCampaigns::get($db,$call,true);
 check(CallCampaigns::accepting($c)&&count($c['sections'])===2,'published campaign with dynamic independent sections accepts in server window');
 check($c['config']['max_batch_works']===null&&$c['config']['max_user_works']===8,'unlimited and fixed per-user limits remain distinct');
 check(str_contains($c['rules_text'],'به معنی انتقال مالکیت')&&strlen($c['rules_version'])===64,'required rights and immutable rules version exposed');
 reject(fn()=>CallCampaigns::saveSection($db,$admin,$call,['title'=>'چرخه','content_type_id'=>$type,'parent_id'=>$section,'active'=>1],$section),422,'cyclic section hierarchy rejected');
 reject(fn()=>CallCampaigns::save($db,$other,['title'=>'غیرمجاز']),403,'member cannot manage campaign');
 $batch=Namira\CallEntries::start($db,$owner,$call,str_repeat('a',32));
 check(Namira\CallEntries::start($db,$owner,$call,str_repeat('a',32))===$batch,'repeated start returns same private batch');
 reject(fn()=>Namira\CallEntries::batch($db,$batch,$other,true),404,'another user cannot read batch');
 $b=Namira\CallEntries::common($db,$owner,$batch,['revision'=>0,'martyr'=>['first_name'=>'شهید','last_name'=>'آزمون'],'answers'=>['city_note'=>'مقدار مشترک']]);
 $w1=Namira\CallEntries::save($db,$owner,$batch,['section_id'=>$section,'title'=>'تصویر نخست','ai_used'=>'1','answers'=>['technique'=>'ink']]);
 $w2=Namira\CallEntries::save($db,$owner,$batch,['section_id'=>$textSection,'title'=>'خاطره مستقل','text'=>'متن خاطره فارسی','owner_mode'=>'other','owner_name'=>'صاحب اثر دیگر']);
 $w3=Namira\CallEntries::save($db,$owner,$batch,['section_id'=>$textSection,'owner_mode'=>'unknown']);
 check($w1['owner_mode']==='self'&&(int)$w1['owner_user_id']===$owner['id']&&$w2['owner_mode']==='other'&&$w2['owner_user_id']===null,'sender and per-work creator are independent');
 check((int)$w1['ai_used']===1&&(int)$w2['ai_used']===0,'AI declaration independent per work');
 $im=imagecreatetruecolor(45,65);$image=$dir.'/original.jpg';imagejpeg($im,$image);unset($im);$hash=hash_file('sha256',$image);
 $uploaded=Namira\CallEntries::upload($db,$owner,$batch,$w1['id'],'main',$image,'high-quality.JPG');
 check($uploaded['preview']&&hash_file('sha256',$image)===$hash,'private image upload preserves original and produces separate preview');
 $assetCount=(int)Db::query($db,'SELECT COUNT(*) FROM incoming_assets')->fetchColumn();$bad=$dir.'/bad.jpg';file_put_contents($bad,'<?php echo 1;');
 reject(fn()=>Namira\CallEntries::upload($db,$owner,$batch,$w1['id'],'main',$bad,'bad.jpg'),422,'invalid image rejected independently');
 check((int)Db::query($db,'SELECT COUNT(*) FROM incoming_assets')->fetchColumn()===$assetCount,'failed upload preserves successful files');
 reject(fn()=>Namira\CallFiles::privateFile($db,$uploaded['id'],$other),404,'private upload cannot be read by another participant');
 $final=Namira\CallEntries::finalize($db,$owner,$batch,['accept_rules'=>'1','rules_version'=>$c['rules_version']]);
 check($final['success']===2&&$final['failed']===1,'valid works finalize independently from an incomplete work');
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);$two=Namira\CallEntries::get($db,$w2['id'],$owner);
 check($one['tracking']!==$two['tracking']&&$one['tracking']&&$two['tracking'],'one batch produces unique independent work tracking codes');
 $countVersions=(int)Db::query($db,'SELECT COUNT(*) FROM call_work_versions')->fetchColumn();
 $again=Namira\CallEntries::finalize($db,$owner,$batch,['accept_rules'=>'1','rules_version'=>$c['rules_version'],'work_ids'=>[$w1['id'],$w2['id']]]);
 check($again['success']===2&&(int)Db::query($db,'SELECT COUNT(*) FROM call_work_versions')->fetchColumn()===$countVersions,'double finalization does not duplicate codes or versions');
 check((int)Db::query($db,'SELECT COUNT(*) FROM martyrs')->fetchColumn()===0&&(int)Db::query($db,'SELECT COUNT(*) FROM images')->fetchColumn()===0,'participant submissions never create public martyr or image');
 Namira\CallEntries::review($db,$admin,$w1['id'],['revision'=>$one['revision'],'review_state'=>'approved','note'=>'پذیرش دبیرخانه','judgement'=>['result'=>'برگزیده','award'=>'اثر منتخب','rank'=>'1']]);
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);
 check($one['review_state']==='approved'&&$one['publication_state']==='private'&&$one['judgement_data']['rank']==='1','review, award and publication are independent');
 $third=Namira\CallEntries::get($db,$w3['id'],$owner);$third=Namira\CallEntries::save($db,$owner,$batch,['revision'=>$third['revision'],'title'=>'اثر ناشناس','text'=>'متن اثر صاحب نامشخص'],$w3['id']);
 Namira\CallEntries::finalize($db,$owner,$batch,['accept_rules'=>'1','rules_version'=>$c['rules_version'],'work_ids'=>[$w3['id']]]);
 $third=Namira\CallEntries::get($db,$w3['id'],$owner);check($third['owner_mode']==='unknown'&&$third['artist_id']===null,'unknown creator never creates fictional artist');
 Db::query($db,'UPDATE art_calls SET end_at=? WHERE id=?',[date('Y-m-d H:i:s',time()-60),$call]);
 reject(fn()=>Namira\CallEntries::save($db,$owner,$batch,['revision'=>$one['revision'],'title'=>'پس از پایان'],$w1['id']),422,'server deadline prevents ordinary late revision');
 Namira\CallEntries::review($db,$admin,$w1['id'],['revision'=>$one['revision'],'review_state'=>'needs_changes','note'=>'اصلاح همین اثر پس از پایان']);
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);$edited=Namira\CallEntries::save($db,$owner,$batch,['revision'=>$one['revision'],'title'=>'اصلاح‌شده'],$w1['id']);
 $resend=Namira\CallEntries::finalize($db,$owner,$batch,['accept_rules'=>'1','rules_version'=>$c['rules_version'],'work_ids'=>[$w1['id']]]);
 check($resend['works'][$w1['id']]['tracking']===$one['tracking']&&(int)Db::query($db,'SELECT COUNT(*) FROM call_work_versions WHERE submission_id=?',[$w1['id']])->fetchColumn()===2,'explicit return permits correction after deadline and preserves tracking and old version');
 check(Namira\CallEntries::get($db,$w2['id'],$owner)['review_state']==='submitted','correcting one work leaves sibling review status intact');
 check((int)Db::query($db,'SELECT COUNT(*) FROM call_notifications WHERE user_id=?',[$owner['id']])->fetchColumn()>=4,'review and receipt notifications stored only for participant panel');

 $one=Namira\CallEntries::get($db,$w1['id'],$owner);
 reject(fn()=>Namira\CallPublishing::publish($db,$owner,$one['id'],[]),403,'participant cannot publish');
 $person=Namira\CallMartyrBridge::create($db,$admin,$one['id'],['identity_ack'=>'1','code'=>'0000999']);
 check(Db::query($db,'SELECT code FROM martyrs WHERE id=?',[$person])->fetchColumn()==='0000999'&&(int)Db::query($db,'SELECT published FROM martyrs WHERE id=?',[$person])->fetchColumn()===0,'reviewed creation preserves archive zeroes and creates private canonical martyr');
 $bundle=(new Namira\Protocol($db,$admin))->get($person);check($bundle['sections'][1]['first_name']==='شهید'&&$bundle['sections'][1]['last_name']==='آزمون','proposal lands in existing martyr identity fields');
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);Namira\CallEntries::review($db,$admin,$one['id'],['revision'=>$one['revision'],'review_state'=>'approved','note'=>'تأیید نسخه اصلاحی']);
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);
 $mediaType=(int)Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='media_type' ORDER BY id LIMIT 1")->fetchColumn();
 $publishInput=['revision'=>$one['revision'],'publication_ack'=>'1','martyr_id'=>$person,'publish_martyr'=>'1','media_type_id'=>$mediaType,'asset_ids'=>[$uploaded['id']]];
 $pub=Namira\CallPublishing::publish($db,$admin,$one['id'],$publishInput);
 check(count($pub['publications'])===1&&(int)Db::query($db,'SELECT COUNT(*) FROM images')->fetchColumn()===1,'explicit approved publication uses existing image bank');
 $again=Namira\CallPublishing::publish($db,$admin,$one['id'],$publishInput);check(!empty($again['already_published'])&&(int)Db::query($db,'SELECT COUNT(*) FROM images')->fetchColumn()===1,'repeating publication never creates duplicate public record');
 $two=Namira\CallEntries::get($db,$w2['id'],$owner);Namira\CallEntries::review($db,$admin,$two['id'],['revision'=>$two['revision'],'review_state'=>'approved','note'=>'پذیرش خاطره']);
 $two=Namira\CallEntries::get($db,$w2['id'],$owner);Namira\CallPublishing::publish($db,$admin,$two['id'],['revision'=>$two['revision'],'publication_ack'=>'1','martyr_id'=>$person,'publish_text'=>'1']);
 $public=Namira\CallPublishing::publicWorks($db,$person);$texts=array_filter($public,fn($p)=>$p['text']!=='');
 check(count($texts)===1&&array_values($texts)[0]['text']==='متن خاطره فارسی'&&(int)Db::query($db,'SELECT COUNT(*) FROM images')->fetchColumn()===1,'memory publishes as text linked to martyr, never as fake image');
 check(array_values($texts)[0]['data']['credit_name']==='صاحب اثر دیگر','public text credits actual creator instead of sender');
 $third=Namira\CallEntries::get($db,$w3['id'],$owner);Namira\CallEntries::review($db,$admin,$third['id'],['revision'=>$third['revision'],'review_state'=>'approved','note'=>'اثر نامشخص']);
 $third=Namira\CallEntries::get($db,$w3['id'],$owner);Namira\CallPublishing::publish($db,$admin,$third['id'],['revision'=>$third['revision'],'publication_ack'=>'1','martyr_id'=>$person,'publish_text'=>'1']);
 check((int)Db::query($db,'SELECT COUNT(*) FROM artists')->fetchColumn()===0,'publishing unknown owner never manufactures artist');
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);Namira\CallEntries::review($db,$admin,$one['id'],['revision'=>$one['revision'],'review_state'=>'needs_changes','note'=>'اصلاح عنوان منتشرشده']);
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);$draft=Namira\CallEntries::save($db,$owner,$batch,['revision'=>$one['revision'],'title'=>'عنوان عمومی بعدی'],$one['id']);
 $public=Namira\CallPublishing::publicWorks($db,$person);$view=array_values(array_filter($public,fn($p)=>(int)$p['submission_id']===$one['id']))[0];
 check($view['data']['title']==='اصلاح‌شده','user changes preserve old public snapshot until explicit reapproval');
 Namira\CallEntries::finalize($db,$owner,$batch,['accept_rules'=>'1','rules_version'=>$c['rules_version'],'work_ids'=>[$one['id']]]);
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);Namira\CallEntries::review($db,$admin,$one['id'],['revision'=>$one['revision'],'review_state'=>'approved','note'=>'پذیرش عنوان جدید']);
 $one=Namira\CallEntries::get($db,$w1['id'],$owner);Namira\CallPublishing::publish($db,$admin,$one['id'],['revision'=>$one['revision'],'publication_ack'=>'1','martyr_id'=>$person,'media_type_id'=>$mediaType,'asset_ids'=>[$uploaded['id']]]);
 check((int)Db::query($db,'SELECT COUNT(*) FROM images')->fetchColumn()===1,'approved metadata correction reuses own public image without duplication');
 check(Db::query($db,'SELECT title FROM images LIMIT 1')->fetchColumn()==='عنوان عمومی بعدی','approved version replaces public title only after manager action');
 $callNow=Namira\CallCampaigns::get($db,$call);$cfg=$callNow['config'];
 Namira\CallCampaigns::save($db,$admin,['revision'=>$callNow['revision'],'title'=>$callNow['title'],'description'=>$callNow['description'],'terms_text'=>$callNow['terms_text'],'accepting'=>1,'mode'=>'competition','edit_policy'=>'until_deadline','start_at'=>$callNow['start_at'],'end_at'=>date('Y-m-d\TH:i',time()+86400),'common_fields'=>[['key'=>'new_required','label'=>'فیلد تازه','type'=>'text','required'=>1]],'martyr_fields'=>$cfg['martyr_fields']],$call);
 $oldBatch=Namira\CallEntries::batch($db,$batch,$owner,true);
 check($oldBatch['schema']['config']['common_fields'][0]['key']==='city_note','form edits preserve previous batch schema and answers');
 check(count(glob($dir.'/backups/migration-012-*.json'))===1,'ordinary call edits never rerun migration');

 $third=Namira\CallEntries::get($db,$w3['id'],$owner);
 for($n=0;$n<25;$n++)Namira\CallEntries::upload($db,$owner,$batch,$third['id'],'main',$image,'repeated-'.$n.'.jpg');
 $afterFiles=Namira\CallEntries::get($db,$third['id'],$owner);check(count($afterFiles['files'])===25,'unlimited file count accepts repeated content as independent files without hash blocking');
 Namira\CallEntries::finalize($db,$owner,$batch,['accept_rules'=>'1','rules_version'=>Namira\CallCampaigns::get($db,$call)['rules_version'],'work_ids'=>[$third['id']]]);
 $export=Namira\CallExport::create($db,$admin,['call_id'=>$call],['anonymous'=>true,'include_files'=>true]);$steps=0;
 do{$state=Namira\CallExport::step($db,$admin,$export);$steps++;if($steps>100)throw new RuntimeException('Export failed to finish');}while($state['state']!=='ready');
 check($state['processed']===3&&$steps>1,'export resumes bounded steps and covers every selected work');
 $download=Namira\CallExport::download($db,$admin,$export,1,'zip');$zip=new ZipArchive();check($zip->open($download['path'])===true,'real grouped ZIP can be opened');
 $names=[];$anonymousMetadata='';$hasText=false;$hasOriginal=false;
 for($n=0;$n<$zip->numFiles;$n++){$name=$zip->getNameIndex($n);$names[]=$name;if(str_ends_with($name,'record.json'))$anonymousMetadata.=$zip->getFromIndex($n);if(str_ends_with($name,'work.txt')&&str_contains($zip->getFromIndex($n),'متن'))$hasText=true;if(str_ends_with($name,'.jpg'))$hasOriginal=true;}
 check(count($names)===count(array_unique($names))&&$hasText&&$hasOriginal&&in_array('index.xlsx',$names,true),'ZIP code folders contain originals, UTF8 text and linked workbook without duplicate entries');
 check(!str_contains($anonymousMetadata,'calls-owner@example.test')&&!str_contains($anonymousMetadata,'صاحب اثر دیگر')&&!str_contains($anonymousMetadata,'کاربر آزمون'),'blind output removes sender and creator metadata consistently');
 $xlsx=Namira\CallExport::download($db,$admin,$export,1,'xlsx');$workbook=new ZipArchive();$workbook->open($xlsx['path']);$xml=simplexml_load_string($workbook->getFromName('xl/worksheets/sheet1.xml'));$headers=array_map('strval',$xml->xpath('//*[local-name()="row" and @r="1"]//*[local-name()="t"]'));$rows=$xml->xpath('//*[local-name()="row"]');$sheet=[$headers];check(count($rows)===4&&in_array('کد رهگیری',$headers,true),'XLSX has one row per work and Persian headers');
 check(!in_array('نام ارسال‌کننده',$sheet[0],true)&&!in_array('نام صاحب اثر',$sheet[0],true),'blind workbook omits identity columns');
 $zip->close();$beforeBytes=filesize($download['path']);Namira\CallExport::step($db,$admin,$export);check(filesize($download['path'])===$beforeBytes,'completed export retry does not rebuild or duplicate files');
 reject(fn()=>Namira\CallExport::download($db,$owner,$export,1,'zip'),403,'participant cannot download management export');
 $selected=Namira\CallExport::create($db,$admin,['call_id'=>$call],['selected_ids'=>[$w2['id']],'include_files'=>false]);do{$selectedState=Namira\CallExport::step($db,$admin,$selected);}while($selectedState['state']!=='ready');
 check($selectedState['total']===1&&$selectedState['parts'][0]['zip']===null,'selected-only XLSX job exports exact selection without file archive');

 // Restore withdrawn publication without duplicating immutable version/slots.
 $current=Namira\CallEntries::get($db,$w1['id'],$owner);$pubCount=(int)Db::query($db,'SELECT COUNT(*) FROM call_publications WHERE submission_id=?',[$w1['id']])->fetchColumn();
 Namira\CallPublishing::unpublish($db,$admin,$w1['id'],['revision'=>$current['revision'],'note'=>'کنترل بازنشر']);$current=Namira\CallEntries::get($db,$w1['id'],$owner);
 Namira\CallPublishing::publish($db,$admin,$w1['id'],['revision'=>$current['revision'],'publication_ack'=>'1','martyr_id'=>$person,'media_type_id'=>$mediaType,'asset_ids'=>[$uploaded['id']]]);
 check((int)Db::query($db,'SELECT COUNT(*) FROM call_publications WHERE submission_id=?',[$w1['id']])->fetchColumn()===$pubCount,'withdrawn approved version republishes without duplicate slots');
 reject(fn()=>(new Namira\Submissions($db,$owner))->submit(['call_id'=>$call],null),422,'legacy submission endpoint cannot bypass new call workflow');
 // A fresh call for real browser journeys and scoped forms/limits.
 $fresh=CallCampaigns::save($db,$admin,['title'=>'فراخوان مسیر مرورگر','description'=>'شرح فراخوان آزمون مرورگر','terms_text'=>'قوانین آزمون','mode'=>'collection','edit_policy'=>'returned_only','accepting'=>1,'common_fields'=>[['key'=>'region_note','label'=>'یادداشت مشترک','type'=>'text']],'martyr_fields'=>['first_name'=>['required'=>true],'last_name'=>['required'=>true],'birth_place'=>['required'=>false]]]);
 $freshImage=CallCampaigns::saveSection($db,$admin,$fresh,['title'=>'پوستر','content_type_id'=>$type,'active'=>1,'min_files'=>1,'max_files'=>['mode'=>'limited','value'=>2],'max_batch_works'=>['mode'=>'limited','value'=>2],'fields'=>[['key'=>'technique','label'=>'تکنیک','type'=>'select','options'=>['digital'=>'دیجیتال','ink'=>'مرکب'],'required'=>true],['key'=>'shared_note','label'=>'یادداشت بخش','type'=>'text','scope'=>'common','required'=>true]]]);
 $freshText=CallCampaigns::saveSection($db,$admin,$fresh,['title'=>'خاطره','content_type_id'=>$textType,'active'=>1,'min_files'=>0]);$fc=CallCampaigns::get($db,$fresh);CallCampaigns::state($db,$admin,$fresh,['status'=>'published','revision'=>$fc['revision']]);$fc=CallCampaigns::get($db,$fresh,true);
 $fb=Namira\CallEntries::start($db,$owner,$fresh,str_repeat('b',32));$fbb=Namira\CallEntries::common($db,$owner,$fb,['revision'=>0,'martyr'=>['first_name'=>'آزمایشی','last_name'=>'محدودیت'],'section_answers'=>[$freshImage=>['shared_note'=>'پاسخ مشترک بخش']]]);
 $fw=Namira\CallEntries::save($db,$owner,$fb,['section_id'=>$freshImage,'title'=>'آزمون سقف','answers'=>['technique'=>'digital']]);Namira\CallEntries::upload($db,$owner,$fb,$fw['id'],'main',$image,'first.jpg');Namira\CallEntries::upload($db,$owner,$fb,$fw['id'],'main',$image,'second.jpg');
 reject(fn()=>Namira\CallEntries::upload($db,$owner,$fb,$fw['id'],'main',$image,'third.jpg'),422,'per-work file ceiling enforced independently');
 Namira\CallEntries::save($db,$owner,$fb,['section_id'=>$freshImage,'title'=>'دوم']);reject(fn()=>Namira\CallEntries::save($db,$owner,$fb,['section_id'=>$freshImage,'title'=>'سوم']),422,'per-section batch work ceiling enforced');
 Namira\CallEntries::finalize($db,$owner,$fb,['work_ids'=>[$fw['id']],'accept_rules'=>'1','rules_version'=>$fc['rules_version']]);$fw=Namira\CallEntries::get($db,$fw['id'],$owner);$fsnap=Namira\CallPublishing::version($db,$fw)['data'];check($fsnap['section_common']['shared_note']==='پاسخ مشترک بخش','section-common answers freeze with independent work snapshot');
 reject(fn()=>Namira\CallEntries::save($db,$owner,$fb,['revision'=>$fw['revision'],'title'=>'غیرمجاز'],$fw['id']),422,'returned-only policy rejects ordinary revision even before deadline');
 Namira\CallEntries::review($db,$admin,$fw['id'],['revision'=>$fw['revision'],'review_state'=>'needs_changes','note'=>'اصلاح اطلاعات مشترک']);
 Db::query($db,'UPDATE art_calls SET end_at=? WHERE id=?',[date('Y-m-d H:i:s',time()-3600),$fresh]);$fbb=Namira\CallEntries::batch($db,$fb,$owner,true);$fbb=Namira\CallEntries::common($db,$owner,$fb,['revision'=>$fbb['revision'],'answers'=>['region_note'=>'اصلاح پس از مهلت']]);check($fbb['data']['martyr']['first_name']==='آزمایشی','omitted canonical fields survive returned shared correction after deadline');
 $fcurrent=Namira\CallEntries::get($db,$fw['id'],$owner);$link=$fcurrent['files'][0]['id'];Namira\CallEntries::removeFile($db,$owner,$link);check(count(Namira\CallEntries::get($db,$fw['id'],$owner)['files'])===1,'returned file removal preserves immutable prior version');
 reject(fn()=>Namira\CallEntries::common($db,$owner,$fb,['revision'=>0]),409,'stale shared form revision rejects lost update');
 Db::query($db,'UPDATE art_calls SET end_at=NULL WHERE id=?',[$fresh]);
 Db::query($db,"UPDATE art_calls SET status='paused' WHERE id=?",[$fresh]);$_GET=['call_id'=>$fresh,'batch'=>$fb,'step'=>2];ob_start();Namira\CallHttp::page($db,'submit-art',$owner);$returnedPage=ob_get_clean();check(str_contains($returnedPage,'فقط اصلاحی که دبیرخانه مجاز کرده')&&!str_contains($returnedPage,'call-add-work'),'owner can reach explicitly returned work while public call is paused');Db::query($db,"UPDATE art_calls SET status='published' WHERE id=?",[$fresh]);$_GET=[];
 // Server format and aggregate limits, not browser-only validation.
 $textFile=$dir.'/safe.txt';file_put_contents($textFile,'متن UTF-8');$safePolicy=CallCampaigns::filePolicy(['formats'=>['txt','zip','pdf'],'max_file_mb'=>1,'min_files'=>0]);check(Namira\CallFiles::inspect($textFile,'safe.txt',$safePolicy)['mime']==='text/plain','UTF8 attachment validated as text on server');reject(fn()=>Namira\CallFiles::inspect($textFile,'fake.pdf',$safePolicy),422,'renamed text cannot pass PDF validation');$badZip=$dir.'/bad.zip';$z=new ZipArchive();$z->open($badZip,ZipArchive::CREATE);$z->addFromString('../escape.txt','bad');$z->close();reject(fn()=>Namira\CallFiles::inspect($badZip,'bad.zip',$safePolicy),422,'ZIP traversal rejected before private storage');$large=$dir.'/large.txt';file_put_contents($large,str_repeat('a',1048577));reject(fn()=>Namira\CallFiles::inspect($large,'large.txt',$safePolicy),422,'file byte ceiling enforced on server');
 // Canonical geographic proposal and complete backup/restore.
 $parent=null;$geo=[];foreach(['country','province','county','city'] as $taxonomy){$parent=Db::insert($db,'protocol_terms',['taxonomy'=>$taxonomy,'title'=>'جغرافیای آزمون '.$taxonomy,'slug'=>'calls-geo-'.bin2hex(random_bytes(4)),'parent_id'=>$parent,'aliases'=>'[]','metadata'=>'{}']);$geo[$taxonomy]=$parent;}
 $geoValue=['country_id'=>(string)$geo['country'],'province_or_state_id'=>(string)$geo['province'],'county_id'=>(string)$geo['county'],'city_or_village_id'=>(string)$geo['city'],'village_name'=>'روستای پیشنهادی'];
 $geoDefs=CallMartyrFields::selection($db,['birth_place'=>['required'=>true]]);$validGeo=CallMartyrFields::validate($db,$owner,$geoDefs,['birth_place'=>$geoValue],true);check((int)$validGeo['birth_place']['county_id']===$geo['county'],'canonical proposal geography validates exact parent chain');
 $badGeo=$geoValue;$badGeo['country_id']=$geo['province'];invalid(fn()=>CallMartyrFields::validate($db,$owner,$geoDefs,['birth_place'=>$badGeo],true),'canonical proposal rejects wrong geography taxonomy');
 reject(fn()=>Namira\CallPublishing::publish($db,$admin,$fw['id'],['revision'=>0]),409,'unapproved draft cannot publish through new endpoint');
 reject(fn()=>(new Namira\Submissions($db,$admin))->review($w1['id'],[]),422,'legacy review cannot bypass versioned secretariat');
 $backup=DataBackup::create($db,'calls-complete-restore-test');$restoreDsn=getenv('TEST_CALLS_RESTORE_DSN')?:('sqlite:'.$dir.'/restored.sqlite');if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!preg_match('/dbname=namira_test_calls_restore(?:;|$)/',$restoreDsn))throw new RuntimeException('Isolated calls restore DB required');$restored=new PDO($restoreDsn,getenv('TEST_DB_USER')?:'',getenv('TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$restored->exec(file_get_contents($dir.'/backups/'.$backup['name'].'.sql'));check(DataBackup::counts($restored)===DataBackup::counts($db),'full SQL backup restores calls, versions and existing data counts');check(Db::query($restored,'SELECT snapshot FROM call_work_versions ORDER BY id LIMIT 1')->fetchColumn()===Db::query($db,'SELECT snapshot FROM call_work_versions ORDER BY id LIMIT 1')->fetchColumn(),'restored immutable work snapshot remains byte identical');
 // Render real views with warnings promoted to exceptions.
 set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
 $renderBase=['db'=>$db,'actor'=>$admin,'old'=>[],'errors'=>[]];$_GET=[];
 foreach(['calls-list-v2'=>$renderBase+['result'=>CallCampaigns::listing($db,[],true)],'call-detail-v2'=>$renderBase+['call'=>$fc],'call-admin-list'=>$renderBase+['result'=>CallCampaigns::listing($db,[])],'call-admin-editor'=>$renderBase+['call'=>$fc,'section'=>null,'sectionId'=>0,'contentTypes'=>CallCampaigns::contentTypes($db),'martyrFields'=>CallMartyrFields::catalog($db)],'call-admin-works'=>$renderBase+['result'=>Namira\CallQueries::works($db,$admin,[]),'calls'=>CallCampaigns::listing($db,[])['items'],'sections'=>CallCampaigns::sections($db,$fresh)],'call-export'=>$renderBase+['job'=>Namira\CallExport::get($db,$admin,$export)]] as $name=>$vars){$html=Namira\CallUI::render($name,$vars);check(strlen($html)>100,'view '.$name.' renders without warnings');}
 $prepared=Namira\CallPublishing::prepare($db,$admin,$w1['id']);$html=Namira\CallUI::render('call-admin-review',$renderBase+$prepared+['person'=>$person,'compare'=>Namira\CallQueries::compare($db,$admin,$person),'terms'=>Db::query($db,"SELECT id,title FROM protocol_terms WHERE taxonomy='media_type'")->fetchAll()]);check(strlen($html)>100,'admin review and publication view renders without warnings');
 $batchRow=Namira\CallEntries::batch($db,$batch,$owner,true);$works=[];foreach([$w1,$w2,$w3] as $r)$works[]=Namira\CallEntries::get($db,$r['id'],$owner,true);
 foreach([1,2,3] as $step){$html=Namira\CallUI::render('call-submit',['db'=>$db,'actor'=>$owner,'call'=>CallCampaigns::get($db,$call),'batch'=>$batchRow,'works'=>$works,'step'=>$step,'old'=>[],'errors'=>[]]);check(strlen($html)>100,'participant step '.$step.' renders without warnings');}
 $data=Namira\CallHttp::accountData($db,$owner);$html=Namira\CallUI::render('calls-account-content',$data+['db'=>$db,'actor'=>$owner]);check(strlen($html)>100,'shared account call entries render without warnings');restore_error_handler();
 $browserImage=$dir.'/browser.jpg';file_put_contents($browserImage,file_get_contents($image).'browser-fixture');if(getenv('CALLS_UI_MANIFEST'))file_put_contents(getenv('CALLS_UI_MANIFEST'),Protocol::encode(['dsn'=>$dsn,'storage'=>$dir,'admin'=>$admin['id'],'owner'=>$owner['id'],'other'=>$other['id'],'call'=>$fresh,'section'=>$freshImage,'text_section'=>$freshText,'image'=>$browserImage,'work'=>$w1['id'],'file'=>$uploaded['id'],'martyr'=>$person,'media_type'=>$mediaType]));
 echo $checks.' complete calls checks passed on '.$db->getAttribute(PDO::ATTR_DRIVER_NAME).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

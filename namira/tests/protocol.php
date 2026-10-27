<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,Archive,Upload,Protocol,ProtocolSpec,ProtocolSchema,ProtocolDates,ProtocolValidator,ProtocolController,ProtocolErrors,HttpError};
$testRoot=BASE_PATH.'/storage/protocol-test-'.bin2hex(random_bytes(8));mkdir($testRoot,0700,true);putenv('APP_ENV=testing');putenv('STORAGE_PATH='.$testRoot);
$dsn=getenv('TEST_PROTOCOL_DB_DSN')?:'sqlite:'.$testRoot.'/protocol.sqlite';if(!str_starts_with($dsn,'sqlite:')&&!str_contains($dsn,'dbname=namira_test_protocol'))throw new RuntimeException('Isolated protocol database required.');
putenv('DB_DSN='.$dsn);putenv('DB_USER='.(getenv('TEST_DB_USER')?:''));putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));
$db=Db::connect();$_SESSION=[];$tests=0;
function check(bool $ok,string $label):void{global $tests;if(!$ok)throw new RuntimeException('FAIL '.$label);$tests++;echo "PASS $label\n";}
function rejected(callable $fn,string $label):void{try{$fn();}catch(HttpError|ProtocolErrors $e){check(true,$label);return;}throw new RuntimeException('FAIL expected rejection '.$label);}
function dateValue(int $year,?int $month=null,?int $day=null,string $calendar='jalali'):array{return ['calendar'=>$calendar,'year'=>(string)$year,'month'=>$month===null?'':(string)$month,'day'=>$day===null?'':(string)$day,'precision'=>$day!==null?'day':($month!==null?'month':'year')];}
function reviewForm(PDO $db,array $actor,int $id,?array $input=null,int $errorStatus=422):array {
    $previousGet=$_GET;$_GET=['edit'=>$id,'step'=>8];if($input!==null)$_SESSION['protocol_feedback']=['id'=>$id,'step'=>8,'panel'=>'','input'=>$input,'errors'=>['form'=>'خطای آزمایشی تصمیم'],'status'=>$errorStatus];
    ob_start();ProtocolController::show($db,$actor);$html=ob_get_clean();$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8">'.$html);libxml_clear_errors();$xp=new DOMXPath($doc);$form=$xp->query('//form[.//input[@name="action" and @value="protocol-transition"]]')->item(0);$pairs=[];$targets=[];
    if($form)foreach($xp->query('.//input|.//select|.//textarea',$form) as $node){$name=$node->getAttribute('name');if(!$name||$node->hasAttribute('disabled'))continue;if($node->tagName==='select'){$options=$xp->query('./option',$node);$value=null;foreach($options as $option){$targets[]=$option->getAttribute('value');if($option->hasAttribute('selected'))$value=$option->getAttribute('value');}$value??=$options->item(0)?->getAttribute('value')??'';}elseif($node->tagName==='textarea')$value=$node->textContent;else{if($node->getAttribute('type')==='checkbox'&&!$node->hasAttribute('checked'))continue;$value=$node->getAttribute('value');}$pairs[]=urlencode($name).'='.urlencode($value);}parse_str(implode('&',$pairs),$post);$_GET=$previousGet;return [$html,$post,$targets,$xp];
}
try{
(require BASE_PATH.'/database/migrate.php')($db);
$legacy=(new Archive($db))->save(['code'=>'0001254','first_name'=>'محمد','last_name'=>'رضایی','city'=>'قدیمی مبهم','published'=>'1']);
$img=imagecreatetruecolor(20,30);$path=$testRoot.'/original.jpg';imagejpeg($img,$path);imagedestroy($img);
$imageId=Upload::image($db,$path,'0001254-1.jpg');$imageBefore=Db::query($db,'SELECT * FROM images WHERE id=?',[$imageId])->fetch();
ProtocolSchema::migrate($db);ProtocolSchema::migrate($db);
check((int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn()===2,'additive migration is restartable');
check(Db::query($db,'SELECT * FROM images WHERE id=?',[$imageId])->fetch()===$imageBefore,'migration preserves existing image identifiers and paths');
$actor=['id'=>1,'role'=>'superadmin'];$p=new Protocol($db,$actor);$content=new Protocol($db,['id'=>2,'role'=>'content']);
rejected(fn()=>new Protocol($db,['id'=>3,'role'=>'shop']),'shop role cannot access protocol');
$legacyBundle=$p->get($legacy);
check(empty($legacyBundle['sections'][2])&&$legacyBundle['core']['city']==='قدیمی مبهم','ambiguous legacy city is retained without invented place mapping');
function saveStep(Protocol $p,int $id,int $step,array $data,array $records=[]):int{foreach(ProtocolSpec::fields($step) as $key=>$def)if($def['type']==='lines'&&is_array($data[$key]??null))$data[$key]=implode("\n",$data[$key]);return $p->save($id,$step,['data'=>$data,'records'=>$records,'revision'=>$id?$p->get($id)['profile']['revision']:0,'form_complete'=>'1']);}
function term(PDO $db,string $tax,string $title):int{return (int)Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND title=?',[$tax,$title])->fetchColumn();}
$id=saveStep($p,0,1,['display_name'=>'شهید گمنام آزمایشی','identity_status'=>'unknown','archive_code'=>'000002','children_count'=>'','mother_name'=>'نام خصوصی']);
check($p->get($id)['sections'][1]['children_count']===null,'empty children count remains null');
check($p->get($id)['core']['published']==0&&$p->get($id)['core']['code']==='000002','anonymous draft needs only display name and identity state; code preserves zeros');
$noCode=saveStep($p,0,1,['display_name'=>'بی‌کد','identity_status'=>'unknown']);
check($p->get($noCode)['profile']['archive_code']===null,'archive code is optional and separate from person id');
rejected(fn()=>saveStep($p,0,1,['identity_status'=>'unknown']),'draft without display name rejected');
rejected(fn()=>saveStep($p,0,1,['display_name'=>'تکراری','identity_status'=>'unknown','archive_code'=>'000002']),'duplicate archive code rejected');
rejected(fn()=>saveStep($p,$id,1,['display_name'=>'گمنام','identity_status'=>'unknown','children_count'=>'-1']),'negative children count rejected');
rejected(fn()=>$p->save($id,1,['data'=>['display_name'=>'الف']]),'truncated form rejected without partial write');
$rev=$p->get($id)['profile']['revision'];
saveStep($p,$id,3,['education_status'=>[term($db,'education_status','دانش‌آموز')]]);
saveStep($p,$id,4,[],['affiliation'=>[['organization'=>term($db,'organization','بسیج'),'relationship'=>'membership']]]);
check(count($p->get($id)['sections'][3]['education_status'])===1&&count($p->get($id)['records']['affiliation'])===1&&empty($p->get($id)['sections'][3]['occupations']),'student and Basij membership remain independent of occupation');
rejected(fn()=>saveStep($p,$id,3,['education_status'=>[term($db,'education_status','دانش‌آموز'),term($db,'education_status','نامشخص')]]),'unknown education cannot coexist with positive status');
rejected(fn()=>$p->save($id,1,['data'=>['display_name'=>'همزمان','identity_status'=>'unknown'],'revision'=>$rev,'form_complete'=>'1']),'stale revision cannot overwrite another section');
$source=$p->shared('source',['data'=>['title'=>'منبع معرفی آزمایشی','source_type'=>'archive']]);
$source2=$p->shared('source',['data'=>['title'=>'روایت متعارض','source_type'=>'family']]);
$event=$p->shared('event',['data'=>['title'=>'واقعه مشترک آزمون','event_types'=>[term($db,'event_type','ترور هدفمند')],'source_id'=>$source]]);
saveStep($p,$noCode,3,['occupations'=>[term($db,'occupation','دانشمند')],'expertise'=>[term($db,'expertise','علوم و فناوری هسته‌ای')]]);
saveStep($p,$noCode,5,['martyrdom_info_status'=>'documented'],['person_event'=>[['event_id'=>$event,'is_fatal'=>'1','source_id'=>$source]]]);
check(count($p->get($noCode)['sections'][3]['occupations'])===1&&empty($p->get($noCode)['records']['person_event'][0]['data']['mechanisms']),'scientist and nuclear expertise do not infer mechanism of terrorism');
saveStep($p,$id,5,['martyrdom_info_status'=>'unknown'],['person_event'=>[['event_id'=>$event,'source_id'=>$source]]]);
check((int)Db::query($db,'SELECT COUNT(DISTINCT martyr_id) FROM protocol_records WHERE event_id=?',[$event])->fetchColumn()===2,'one shared event is linked to two people');
$aff=$p->get($id)['records']['affiliation'][0]['id'];
$rank=ProtocolController::term($db,$actor,['taxonomy'=>'rank','title'=>'درجه مستند آزمایشی','slug'=>'test-rank','source_id'=>$source,'active'=>'1']);
$rank2=ProtocolController::term($db,$actor,['taxonomy'=>'rank','title'=>'درجه پس از شهادت آزمایشی','slug'=>'test-rank-post','source_id'=>$source,'active'=>'1']);
rejected(fn()=>ProtocolController::term($db,$actor,['taxonomy'=>'rank','title'=>'بدون منبع','slug'=>'bad-rank','active'=>'1']),'rank catalog requires an actual source');
saveStep($p,$id,4,['military_background'=>'1'],['military'=>[['affiliation_id'=>$aff,'rank_event'=>$rank,'rank_martyrdom'=>$rank,'rank_posthumous'=>$rank2,'rank_event_source'=>$source,'rank_martyrdom_source'=>$source,'rank_posthumous_source'=>$source,'position'=>'فرمانده','position_source'=>$source]]]);$mil=$p->get($id)['records']['military'][0]['data'];
check($mil['rank_event']===$rank&&$mil['rank_posthumous']===$rank2&&$mil['position']==='فرمانده','event-time rank and posthumous rank and command position are independent');
$country=term($db,'country','ایران');$province=ProtocolController::term($db,$actor,['taxonomy'=>'province','title'=>'استان آزمون','slug'=>'test-province','parent_id'=>$country,'active'=>'1']);
$county=ProtocolController::term($db,$actor,['taxonomy'=>'county','title'=>'شهرستان آزمون','slug'=>'test-county','parent_id'=>$province,'active'=>'1']);
$cities=[];foreach(['تولد','زندگی','شهادت','مزار'] as $i=>$label)$cities[]=ProtocolController::term($db,$actor,['taxonomy'=>'city','title'=>'شهر '.$label,'slug'=>'test-city-'.$i,'parent_id'=>$county,'active'=>'1']);
function place(array $cities,int $i):array{global $country,$province,$county;return ['country_id'=>$country,'province_or_state_id'=>$province,'county_id'=>$county,'city_or_village_id'=>$cities[$i],'address_text'=>'نشانی محرمانه'];}
saveStep($p,$id,2,['birth_date'=>dateValue(1340),'martyrdom_date'=>dateValue(1360,2,3),'birth_place'=>place($cities,0),'residence_at_incident'=>place($cities,1),'martyrdom_place'=>place($cities,2)],['burial'=>[['burial_type'=>'body','knowledge_status'=>'known','place'=>place($cities,3),'cemetery'=>'گلزار نمونه','is_current'=>'1','source_id'=>$source]]]);
$p->shared('event',['data'=>['title'=>'واقعه مشترک آزمون','event_types'=>[term($db,'event_type','ترور هدفمند')],'source_id'=>$source],'locations'=>[['label'=>'محل اول','place'=>place($cities,0),'source_id'=>$source],['label'=>'محل دوم','place'=>place($cities,1),'source_id'=>$source]]],$event);
$foundEvent=array_values(array_filter($p->events(),fn($ev)=>(int)$ev['id']===$event))[0];check(count($foundEvent['locations'])===2&&count($foundEvent['data']['event_types'])===1,'shared event retains multiple locations and normalized event types');
$b=$p->get($id);check($b['sections'][2]['birth_place']['city_or_village_id']===$cities[0]&&$b['records']['burial'][0]['data']['place']['city_or_village_id']===$cities[3],'four different cities keep distinct meanings');
check(ProtocolDates::age($b['sections'][2]['birth_date'],$b['sections'][2]['martyrdom_date'])==='19 تا 20 سال؛ بازه تقریبی در تقویم ثبت‌شده','year-only birth and exact death produce an age range');
check($b['sections'][2]['birth_date']['month']===null&&$b['sections'][2]['birth_date']['day']===null,'unknown date components are never defaulted');
rejected(fn()=>ProtocolDates::read(dateValue(1400,12,30)),'invalid Jalali non-leap date rejected');
check(ProtocolDates::read(dateValue(1399,12,30))['day']===30,'valid Jalali leap date accepted');
rejected(fn()=>ProtocolDates::read(dateValue(2023,2,29,'gregorian')),'invalid Gregorian date rejected');
rejected(fn()=>saveStep($p,$id,2,['birth_date'=>dateValue(1400,1,1),'martyrdom_date'=>dateValue(1390,1,1)]),'certain birth after death rejected');
rejected(fn()=>saveStep($p,$id,2,['birth_place'=>['latitude'=>'91']]),'out-of-range coordinates rejected');
$burial=$p->get($id)['records']['burial'][0];$burialData=$burial['data'];$burialData['id']=$burial['id'];$burialData['is_current']='';
saveStep($p,$id,2,$b['sections'][2],['burial'=>[$burialData,['burial_type'=>'body','knowledge_status'=>'known','cemetery'=>'مزار جدید','is_current'=>'1','source_id'=>$source,'burial_date'=>dateValue(1370,1,1)]]]);
check(count($p->get($id)['records']['burial'])===2,'burial transfer retains previous location and history');
saveStep($p,$noCode,2,[],['burial'=>[['knowledge_status'=>'known','burial_type'=>'body','cemetery'=>'مزار معلوم']]]);check($p->get($noCode)['sections'][2]['birth_date']['precision']==='unknown'&&count($p->get($noCode)['records']['burial'])===1,'anonymous identity and unknown birth can coexist with known burial');
rejected(fn()=>saveStep($p,$noCode,4,[],['military'=>[['affiliation_id'=>$aff]]]),'cross-person affiliation reference rejected');
saveStep($p,$id,7,[],['evidence'=>[['source_id'=>$source,'claim_key'=>'identity','review_status'=>'reviewed'],['source_id'=>$source,'claim_key'=>'martyrdom_date','review_status'=>'conflict','quote'=>'روایت اول'],['source_id'=>$source2,'claim_key'=>'martyrdom_date','review_status'=>'conflict','quote'=>'روایت دوم']]]);
check(count($p->get($id)['records']['evidence'])===3&&count($p->warnings($p->get($id)))>0,'conflicting sources coexist and raise review warning');
rejected(fn()=>saveStep($content,$noCode,7,[],['evidence'=>[['source_id'=>$source,'claim_key'=>'identity','review_status'=>'reviewed']]]),'registrar cannot approve evidence');
$identity=$p->get($id)['sections'][1];$identity['short_bio']='معرفی کوتاه مستند';$identity['biography']='<p onclick="bad()">متن <b>معتبر</b><script>alert(1)</script></p>';saveStep($p,$id,1,$identity);
check(!str_contains($p->get($id)['sections'][1]['biography'],'script')&&!str_contains($p->get($id)['sections'][1]['biography'],'onclick'),'rich biography is sanitized server-side');
function transition(Protocol $p,int $id,string $to,array $in=[]):void{$p->transition($id,$to,$in+['revision'=>$p->get($id)['profile']['revision']]);}
[$directHtml,$directPost,$directOptions]=reviewForm($db,$actor,$id);check($directOptions===[]&&$directPost['status']==='published'&&!isset($directPost['publication_ack'])&&!str_contains($directHtml,'ارسال برای بازبینی'),'admin publication form offers a direct action without review controls');
transition($content,$id,'pending');rejected(fn()=>transition($content,$id,'approved'),'legacy non-admin protocol service retains role restrictions');transition($p,$id,'approved');
rejected(fn()=>transition($p,$id,'published'),'explicit publisher acknowledgement required');
rejected(fn()=>transition($p,$id,'published',['publication_ack'=>'1']),'warnings require reason and explicit acknowledgement');
transition($p,$id,'published',['publication_ack'=>'1','incomplete_ack'=>'1','reason'=>'با حفظ هشدار تعارض، نسخه آزمون']);
[, $publishedPost,$publishedActions]=reviewForm($db,$actor,$id);check($publishedActions===[]&&$publishedPost['status']==='published','published admin form has no staged action selector');
$published=$p->get($id);$snapshot=Protocol::decode($published['profile']['public_snapshot']);
check(!str_contains($published['profile']['public_snapshot'],'نام خصوصی')&&!str_contains($published['profile']['public_snapshot'],'نشانی محرمانه'),'public snapshot excludes mother and exact addresses');
check((new Archive($db))->find($id)['display_name']==='شهید گمنام آزمایشی'&&empty(Db::query($db,'SELECT id FROM images WHERE martyr_id=?',[$id])->fetchColumn()),'profile is publishable without any image');
$identity=$published['sections'][1];$identity['display_name']='نام خصوصی جدید پیش‌نویس';$identity['short_bio']='تغییر منتشرنشده';saveStep($p,$id,1,$identity);
check((new Archive($db))->find($id)['display_name']==='شهید گمنام آزمایشی'&&(new Archive($db))->find($id)['summary']==='معرفی کوتاه مستند','draft changes never leak through core archive or public name');
check(Db::query($db,'SELECT * FROM images WHERE id=?',[$imageId])->fetch()===$imageBefore&&hash_file('sha256',Upload::absolute($imageBefore['original_path']))===hash_file('sha256',$path),'all protocol operations preserve existing image links and original bytes');
$beforeSections=$p->get($id)['sections'];saveStep($p,$id,6,['collections'=>[]]);check($p->get($id)['sections'][2]===$beforeSections[2],'saving another step preserves date/place sections');
check((int)Db::query($db,'SELECT COUNT(*) FROM protocol_person_terms')->fetchColumn()>0,'multi selections stored in normalized junction tables');
$collection=ProtocolController::term($db,$actor,['taxonomy'=>'collection','title'=>'مجموعه شرطی دانشمند','slug'=>'scientist-filter','collection_mode'=>'filter','filter_field'=>'occupations','filter_term_id'=>term($db,'occupation','دانشمند'),'active'=>'1']);
rejected(fn()=>saveStep($p,$noCode,6,['collections'=>[$collection]]),'filter-based collections cannot be manually asserted');
$derived=$p->publicSnapshot($p->get($noCode),[]);check(in_array($collection,$derived['sections'][6]['collections']??[],true),'filter-based collection membership derives from actual data');
$editorial=term($db,'collection','هسته‌ای');saveStep($p,$id,6,['collections'=>[$editorial]]);transition($p,$id,'pending');transition($p,$id,'approved');
rejected(fn()=>transition($p,$id,'published',['publication_ack'=>'1','incomplete_ack'=>'1','reason'=>'آزمون']),'editorial collection cannot publish without reviewed source');
saveStep($p,$id,6,['collections'=>[]]);$editIdentity=$p->get($id)['sections'][1];$editIdentity['archive_code']='000009';saveStep($p,$id,1,$editIdentity);check($p->get($id)['core']['code']==='000002','draft archive code change does not affect active public profile');
transition($p,$id,'pending');transition($p,$id,'approved');transition($p,$id,'published',['publication_ack'=>'1','incomplete_ack'=>'1','reason'=>'بررسی و اصلاح کد آرشیو']);check($p->get($id)['core']['code']==='000009','reviewed archive code change also updates upload matching code');
check((int)Db::query($db,'SELECT COUNT(*) FROM protocol_changes WHERE martyr_id=?',[$id])->fetchColumn()>0,'field history records actor and before/after data');
transition($p,$id,'archived',['reason'=>'بایگانی آزمایشی']);check((new Archive($db))->find($id)===false&&$p->get($id)['profile']['status']==='archived','archive removes public visibility without deleting person');
[, $restorePost,$archivedActions]=reviewForm($db,$actor,$id);check($archivedActions===[]&&$restorePost['status']==='published','archived admin record can be published directly');
ob_start();Namira\ProtocolController::show($db,$actor);$html=ob_get_clean();check(str_contains($html,'name="form_complete"')&&!str_contains($html,'type="file"'),'rendered registration form is independent from file upload');
foreach(range(1,8) as $stepNumber){$_GET=['edit'=>$id,'step'=>$stepNumber];ob_start();ProtocolController::show($db,$actor);$pageHtml=ob_get_clean();check(str_contains($pageHtml,'name="id" value="'.$id.'"')&&str_contains($pageHtml,$stepNumber===8?'protocol-transition':'protocol-save'),'editor section '.$stepNumber.' renders persisted identity and intended save action');}
Namira\Importer::commit($db,[['code'=>'003344','first_name'=>'ورودی','last_name'=>'اکسل','published'=>'1']]);$imported=Db::query($db,'SELECT id,published FROM martyrs WHERE code=?',['003344'])->fetch();check($imported['published']==0&&$p->get((int)$imported['id'])['profile']['status']==='draft','Excel imports cannot bypass review and publication');
echo "\n$tests protocol checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
}catch(Throwable $e){if($e instanceof ProtocolErrors)fwrite(STDERR,json_encode($e->errors,JSON_UNESCAPED_UNICODE)."\n");fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

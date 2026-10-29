<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallEntries {
 public static function batch(PDO $db,int $id,array $actor,bool $own=false,bool $lock=false):array {
  $b=Db::query($db,'SELECT * FROM call_batches WHERE id=?'.($lock?CallSchema::lock($db):''),[$id])->fetch();
  if(!$b||($own&&(int)$b['user_id']!==(int)$actor['id']))throw new HttpError(404,'ارسال شما پیدا نشد.');
  if((int)$b['user_id']!==(int)$actor['id'])CallSchema::permit($db,$actor,'submissions.view');
  $b['data']=Protocol::decode($b['payload']);$b['schema']=Protocol::decode($b['schema_snapshot']);return $b;
 }
 public static function start(PDO $db,array $actor,int $callId,string $token):int {
  CallSchema::requireReady($db);MemberServices::requireMember($db,$actor);
  if(!preg_match('/^[a-f0-9]{32,64}$/D',$token))throw new HttpError(422,'کلید ارسال معتبر نیست.');
  return Db::transaction($db,function()use($db,$actor,$callId,$token){
   $call=CallCampaigns::get($db,$callId,false,true);
   $old=Db::query($db,'SELECT id,user_id,call_id FROM call_batches WHERE token=?',[$token])->fetch();
   if($old){if((int)$old['user_id']!==(int)$actor['id']||(int)$old['call_id']!==$callId)throw new HttpError(409,'کلید ارسال تکراری است.');return (int)$old['id'];}
   if(!CallCampaigns::accepting($call))throw new HttpError(422,'فراخوان در زمان فعلی پذیرش ندارد.');
   $now=date('Y-m-d H:i:s');$schema=['call_revision'=>$call['revision'],'config'=>$call['config'],'martyr_definitions'=>$call['martyr_definitions'],'sections'=>$call['sections']];
   return Db::insert($db,'call_batches',['call_id'=>$callId,'user_id'=>$actor['id'],'token'=>$token,'payload'=>Protocol::encode(['martyr'=>[],'common'=>[],'section_common'=>[]]),'schema_snapshot'=>Protocol::encode($schema),'accepted_rules_text'=>'','created_at'=>$now,'updated_at'=>$now]);
  });
 }
 public static function common(PDO $db,array $actor,int $batchId,array $in):array {
  return Db::transaction($db,function()use($db,$actor,$batchId,$in){
   $b=self::batch($db,$batchId,$actor,true,true);$call=CallCampaigns::get($db,(int)$b['call_id']);
   $affected=self::commonEditable($db,$b,$call);
   if(!$affected&&($b['state']!=='draft'||!CallCampaigns::accepting($call)))throw new HttpError(422,'اطلاعات مشترک در این وضعیت قابل اصلاح نیست.');
   if((int)($in['revision']??-1)!==(int)$b['revision'])throw new HttpError(409,'نسخه اطلاعات مشترک تغییر کرده است.');
   $payload=$b['data'];
   $payload['martyr']=CallMartyrFields::validate($db,$actor,$b['schema']['martyr_definitions'],(array)($in['martyr']??[]),false,$payload['martyr']);
   $payload['common']=CallForms::validate($b['schema']['config']['common_fields'],(array)($in['answers']??[]),false,$payload['common']);
   self::validateAnswerFiles($db,$b['id'],null,$b['schema']['config']['common_fields'],$payload['common']);
   foreach($b['schema']['sections'] as $section){$defs=array_filter($section['config']['fields']??[],fn($f)=>$f['scope']==='common');$sid=(int)$section['id'];$payload['section_common'][$sid]=CallForms::validate($defs,(array)($in['section_answers'][$sid]??[]),false,$payload['section_common'][$sid]??[]);self::validateAnswerFiles($db,$b['id'],null,$defs,$payload['section_common'][$sid],'s'.$sid.'__');}
   Db::query($db,'UPDATE call_batches SET payload=?,revision=revision+1,updated_at=? WHERE id=?',[Protocol::encode($payload),date('Y-m-d H:i:s'),$batchId]);
   foreach($affected as $work){Db::query($db,"UPDATE call_work_details SET review_state='draft' WHERE submission_id=?",[$work]);Db::query($db,"UPDATE art_submissions SET status='draft',revision=revision+1 WHERE id=?",[$work]);}
   return self::batch($db,$batchId,$actor,true);
  });
 }
 public static function commonEditable(PDO $db,array $batch,array $call):array {
  $rows=Db::query($db,"SELECT submission_id,tracking,review_state,returned_at FROM call_work_details WHERE batch_id=? AND review_state<>'deleted'",[$batch['id']])->fetchAll();$ids=[];foreach($rows as $r)if((!$r['tracking']&&CallCampaigns::accepting($call))||($call['config']['edit_policy']==='until_deadline'&&CallCampaigns::accepting($call))||(!empty($r['returned_at'])&&in_array($r['review_state'],['needs_changes','draft'],true)))$ids[]=(int)$r['submission_id'];return $ids;
 }
 public static function get(PDO $db,int $id,array $actor,bool $own=false,bool $lock=false):array {
  $r=Db::query($db,'SELECT s.*,w.batch_id,w.section_id,w.tracking,w.review_state,w.publication_state,w.owner_mode,w.owner_user_id,w.owner_payload,w.ai_used,w.latest_version_id,w.published_version_id,w.judgement,w.submitted_at,w.returned_at,b.call_id AS campaign_id FROM art_submissions s JOIN call_work_details w ON w.submission_id=s.id JOIN call_batches b ON b.id=w.batch_id WHERE s.id=?'.($lock?CallSchema::lock($db):''),[$id])->fetch();
  if(!$r||($own&&(int)$r['user_id']!==(int)$actor['id']))throw new HttpError(404,'اثر شما پیدا نشد.');
  if((int)$r['user_id']!==(int)$actor['id'])CallSchema::permit($db,$actor,'submissions.view');
  $r['data']=Protocol::decode($r['payload']);$r['owner']=Protocol::decode($r['owner_payload']);$r['judgement_data']=Protocol::decode($r['judgement']);$r['batch']=self::batch($db,(int)$r['batch_id'],$actor,$own);
  $r['files']=Db::query($db,'SELECT l.*,a.mime,a.bytes,a.preview_path FROM call_asset_links l JOIN incoming_assets a ON a.id=l.asset_id WHERE l.submission_id=? AND l.active=1 ORDER BY l.sequence_no,l.id',[$id])->fetchAll();
  return $r;
 }
 public static function section(array $batch,int $sectionId):array {
  foreach($batch['schema']['sections'] as $s)if((int)$s['id']===$sectionId)return $s;throw new HttpError(422,'بخش در فرم این ارسال تعریف نشده است.');
 }
 public static function canEdit(array $call,?array $work):bool {
  if(!$work)return CallCampaigns::accepting($call);
  if($work['review_state']==='deleted')return false;
  if(!empty($work['returned_at'])&&in_array($work['review_state'],['needs_changes','draft'],true))return true;
  if(!CallCampaigns::accepting($call))return false;
  return !$work['tracking']||$work['review_state']==='draft'||$call['config']['edit_policy']==='until_deadline';
 }
 public static function save(PDO $db,array $actor,int $batchId,array $in,int $id=0):array {
  MemberServices::requireMember($db,$actor);
  return Db::transaction($db,function()use($db,$actor,$batchId,$in,$id){
   $hint=self::batch($db,$batchId,$actor,true);$call=CallCampaigns::get($db,(int)$hint['call_id'],false,true);$b=self::batch($db,$batchId,$actor,true,true);
   $old=$id?self::get($db,$id,$actor,true,true):null;
   if($old&&(int)$old['batch_id']!==$batchId)throw new HttpError(404,'اثر متعلق به این ارسال نیست.');
   if(!self::canEdit($call,$old))throw new HttpError(422,'این اثر فقط در مهلت یا پس از بازگرداندن دبیرخانه قابل اصلاح است.');
   if($old&&(int)($in['revision']??-1)!==(int)$old['revision'])throw new HttpError(409,'نسخه اثر تغییر کرده است.');
   $sectionId=(int)($in['section_id']??$old['section_id']??0);$section=self::section($b,$sectionId);
   if(!$old){if(!Db::query($db,'SELECT id FROM call_sections WHERE id=? AND call_id=? AND active=1',[$sectionId,$call['id']])->fetchColumn())throw new HttpError(422,'بخش انتخاب‌شده فعال نیست.');
    $batchCount=(int)Db::query($db,"SELECT COUNT(*) FROM call_work_details WHERE batch_id=? AND review_state<>'deleted'",[$batchId])->fetchColumn();
    $allCount=(int)Db::query($db,"SELECT COUNT(*) FROM call_work_details w JOIN call_batches b ON b.id=w.batch_id WHERE b.call_id=? AND b.user_id=? AND w.review_state<>'deleted'",[$call['id'],$actor['id']])->fetchColumn();
    if($call['config']['max_batch_works']!==null&&$batchCount>=$call['config']['max_batch_works'])throw new HttpError(422,'سقف آثار این نوبت ارسال تکمیل شده است.');
    if($call['config']['max_user_works']!==null&&$allCount>=$call['config']['max_user_works'])throw new HttpError(422,'سقف مجموع آثار شما در فراخوان تکمیل شده است.');
   }
   if(!$old||(int)$old['section_id']!==$sectionId){
    $live=CallCampaigns::sections($db,(int)$call['id'],true);if(!in_array($sectionId,array_map('intval',array_column($live,'id')),true))throw new HttpError(422,'این بخش یا شاخه بالادستی آن فعال نیست.');
    $sectionBatch=(int)Db::query($db,"SELECT COUNT(*) FROM call_work_details WHERE batch_id=? AND section_id=? AND review_state<>'deleted'",[$batchId,$sectionId])->fetchColumn();
    $sectionUser=(int)Db::query($db,"SELECT COUNT(*) FROM call_work_details w JOIN call_batches b ON b.id=w.batch_id WHERE b.call_id=? AND b.user_id=? AND w.section_id=? AND w.review_state<>'deleted'",[$call['id'],$actor['id'],$sectionId])->fetchColumn();
    if($section['config']['max_batch_works']!==null&&$sectionBatch>=$section['config']['max_batch_works'])throw new HttpError(422,'سقف آثار این بخش در نوبت ارسال تکمیل است.');
    if($section['config']['max_user_works']!==null&&$sectionUser>=$section['config']['max_user_works'])throw new HttpError(422,'سقف مجموع آثار شما در این بخش تکمیل است.');
   }
   $data=$old['data']??[];$same=$old&&(int)$old['section_id']===$sectionId;
   if($old&&!$same){$previousFields=array_filter($data['section_snapshot']['config']['fields']??[],fn($f)=>$f['scope']!=='common');$data['previous_sections'][(string)$old['section_id']]=['answers'=>CallForms::validate($previousFields,(array)($in['answers']??[]),false,$data['answers']??[]),'section'=>$data['section_snapshot']??[]];}
   $incomingAnswers=$old&&!$same?($data['previous_sections'][(string)$sectionId]['answers']??[]):(array)($in['answers']??[]);
   $mode=$in['owner_mode']??$old['owner_mode']??'self';if(!in_array($mode,['self','other','unknown'],true))throw new HttpError(422,'صاحب اثر را مشخص کنید.');
   $owner=[];$ownerId=null;
   if($mode==='self'){$identity=MobileAuth::identity($db,(int)$actor['id']);$name=trim(($identity['first_name']??'').' '.($identity['last_name']??''));$owner=['name'=>$name,'details'=>''];$ownerId=$actor['id'];}
   elseif($mode==='other'){$previous=$old&&$old['owner_mode']==='other'?$old['owner']:[];$owner=['name'=>array_key_exists('owner_name',$in)?Security::text($in,'owner_name',255,false):($previous['name']??''),'details'=>array_key_exists('owner_details',$in)?Security::text($in,'owner_details',3000,false):($previous['details']??'')];}
   else $owner=['name'=>'','details'=>''];
   $title=array_key_exists('title',$in)?Security::text($in,'title',255,false):($old['title']??'');
   foreach(['description','text'] as $key)if(array_key_exists($key,$in))$data[$key]=Security::text($in,$key,$key==='text'?200000:20000,false);
   $data['description']??='';$data['text']??='';
   $data['answers']=CallForms::validate(array_filter($section['config']['fields']??[],fn($f)=>$f['scope']!=='common'),$incomingAnswers,false,$same?($data['answers']??[]):[]);
   $data['section_snapshot']=$section;
   if(isset($in['ai_used'])&&!is_scalar($in['ai_used']))throw new HttpError(422,'وضعیت هوش مصنوعی معتبر نیست.');$ai=array_key_exists('ai_used',$in)?in_array($in['ai_used'],[1,'1',true,'on'],true):!empty($old['ai_used']);
   $now=date('Y-m-d H:i:s');
   if($old){Db::query($db,"UPDATE art_submissions SET title=?,payload=?,status='draft',revision=revision+1,updated_at=? WHERE id=?",[$title,Protocol::encode($data),$now,$id]);Db::query($db,"UPDATE call_work_details SET section_id=?,review_state='draft',owner_mode=?,owner_user_id=?,owner_payload=?,ai_used=? WHERE submission_id=?",[$sectionId,$mode,$ownerId,Protocol::encode($owner),$ai?1:0,$id]);}
   else{$id=Db::insert($db,'art_submissions',['call_id'=>$call['id'],'user_id'=>$actor['id'],'title'=>$title,'payload'=>Protocol::encode($data),'original_path'=>'','mime'=>'','sha256'=>'','status'=>'draft','review_note'=>'','created_at'=>$now,'updated_at'=>$now]);Db::insert($db,'call_work_details',['submission_id'=>$id,'batch_id'=>$batchId,'section_id'=>$sectionId,'owner_mode'=>$mode,'owner_user_id'=>$ownerId,'owner_payload'=>Protocol::encode($owner),'ai_used'=>$ai?1:0,'judgement'=>'{}']);}
   self::validateAnswerFiles($db,$batchId,$id,array_filter($section['config']['fields']??[],fn($f)=>$f['scope']!=='common'),$data['answers']);
   return self::get($db,$id,$actor,true);
  });
 }
 public static function validateAnswerFiles(PDO $db,int $batch,?int $work,array $fields,array $answers,string $prefix=''):void {
  foreach($fields as $f)if($f['type']==='file'&&!empty($f['active']))foreach($answers[$f['key']]??[] as $asset){
   $ok=Db::query($db,'SELECT id FROM call_asset_links WHERE batch_id=? AND '.($work?'submission_id=?':'submission_id IS NULL').' AND field_key=? AND asset_id=? AND active=1',$work?[$batch,$work,$prefix.$f['key'],$asset]:[$batch,$prefix.$f['key'],$asset])->fetchColumn();
   if(!$ok)throw new HttpError(422,'فایل پاسخ متعلق به همین اثر یا ارسال نیست.');
  }
 }
 public static function upload(PDO $db,array $actor,int $batchId,?int $workId,string $field,string $source,string $filename,?string $preview=null):array {
  MemberServices::requireMember($db,$actor);$created=null;
  try{return Db::transaction($db,function()use($db,$actor,$batchId,$workId,$field,$source,$filename,$preview,&$created){
   $b=self::batch($db,$batchId,$actor,true,true);$call=CallCampaigns::get($db,(int)$b['call_id']);$work=$workId?self::get($db,$workId,$actor,true,true):null;
   if($work&&(int)$work['batch_id']!==$batchId)throw new HttpError(404,'اثر پیدا نشد.');
   $commonAllowed=!$work&&($b['state']==='draft'?CallCampaigns::accepting($call):(bool)self::commonEditable($db,$b,$call));
   if($work?!self::canEdit($call,$work):!$commonAllowed)throw new HttpError(422,'در این وضعیت بارگذاری مجاز نیست.');
   $section=$work?self::section($b,(int)$work['section_id']):null;$policy=$section['config']??$b['schema']['config'];$fields=$work?array_filter($section['config']['fields']??[],fn($f)=>$f['scope']!=='common'):$b['schema']['config']['common_fields'];$fieldMatch=$field;
   if(!$work&&preg_match('/^s([0-9]+)__(.+)$/D',$field,$match)){$section=self::section($b,(int)$match[1]);$policy=$section['config'];$fields=array_filter($section['config']['fields']??[],fn($f)=>$f['scope']==='common');$fieldMatch=$match[2];}
   if($field!=='main'){$valid=false;foreach($fields as $f)if($f['key']===$fieldMatch&&$f['type']==='file'&&!empty($f['active']))$valid=true;if(!$valid)throw new HttpError(422,'فیلد فایل متعلق به این فرم نیست.');}elseif(!$work)throw new HttpError(422,'فایل اصلی باید متعلق به یک اثر باشد.');
   $count=$work?(int)Db::query($db,'SELECT COUNT(*) FROM call_asset_links WHERE submission_id=? AND active=1',[$workId])->fetchColumn():0;
   if($work&&$policy['max_files']!==null&&$count>=$policy['max_files'])throw new HttpError(422,'سقف تعداد فایل‌های این اثر تکمیل شده است.');
   $total=(int)Db::query($db,'SELECT COALESCE(SUM(a.bytes),0) FROM call_asset_links l JOIN incoming_assets a ON a.id=l.asset_id WHERE l.batch_id=? AND l.active=1',[$batchId])->fetchColumn();$caps=array_filter([$b['schema']['config']['max_total_mb'],$policy['max_total_mb']??null],fn($v)=>$v!==null);$cap=$caps?min($caps):null;
   if($cap!==null&&$total+filesize($source)>$cap*1048576)throw new HttpError(422,'سقف مجموع حجم فایل‌های این ارسال تکمیل شده است.');
   $created=CallFiles::store($db,$actor,$source,$filename,$policy,$preview);$now=date('Y-m-d H:i:s');$sequence=(int)Db::query($db,'SELECT COALESCE(MAX(sequence_no),0)+1 FROM call_asset_links WHERE batch_id=?',[$batchId])->fetchColumn();
   $link=Db::insert($db,'call_asset_links',['batch_id'=>$batchId,'submission_id'=>$workId,'asset_id'=>$created['id'],'field_key'=>$field,'filename'=>$created['filename'],'sequence_no'=>$sequence,'created_at'=>$now]);
   if($field!=='main'){if($work){$d=$work['data'];$d['answers'][$field]=array_values(array_unique([...($d['answers'][$field]??[]),$created['id']]));Db::query($db,'UPDATE art_submissions SET payload=? WHERE id=?',[Protocol::encode($d),$workId]);}
    else{$d=$b['data'];if(preg_match('/^s([0-9]+)__(.+)$/D',$field,$m))$d['section_common'][$m[1]][$m[2]]=array_values(array_unique([...($d['section_common'][$m[1]][$m[2]]??[]),$created['id']]));else $d['common'][$field]=array_values(array_unique([...($d['common'][$field]??[]),$created['id']]));Db::query($db,'UPDATE call_batches SET payload=?,revision=revision+1 WHERE id=?',[Protocol::encode($d),$batchId]);foreach(self::commonEditable($db,$b,$call) as $changed){Db::query($db,"UPDATE call_work_details SET review_state='draft' WHERE submission_id=?",[$changed]);Db::query($db,"UPDATE art_submissions SET status='draft',revision=revision+1 WHERE id=?",[$changed]);}}}
   if($work){Db::insert($db,'submission_assets',['submission_id'=>$workId,'asset_id'=>$created['id'],'sequence_no'=>$sequence,'is_current'=>1]);Db::query($db,"UPDATE art_submissions SET status='draft',revision=revision+1,updated_at=? WHERE id=?",[$now,$workId]);Db::query($db,"UPDATE call_work_details SET review_state='draft' WHERE submission_id=?",[$workId]);}
   return ['id'=>$created['id'],'link_id'=>$link,'filename'=>$created['filename'],'bytes'=>$created['bytes'],'preview'=>!empty($created['preview_path']),'revision'=>$work?(int)$work['revision']+1:(int)$b['revision']+($field!=='main'?1:0)];
  });}catch(\Throwable $e){if($created)CallFiles::removeCreated($created);throw $e;}
 }
 public static function removeFile(PDO $db,array $actor,int $linkId):array {
  return Db::transaction($db,function()use($db,$actor,$linkId){
   $link=Db::query($db,'SELECT * FROM call_asset_links WHERE id=?'.CallSchema::lock($db),[$linkId])->fetch();if(!$link)throw new HttpError(404,'فایل پیدا نشد.');
   $b=self::batch($db,(int)$link['batch_id'],$actor,true,true);$call=CallCampaigns::get($db,(int)$b['call_id']);$w=$link['submission_id']?self::get($db,(int)$link['submission_id'],$actor,true,true):null;
   if($w?!self::canEdit($call,$w):!($b['state']==='draft'?CallCampaigns::accepting($call):(bool)self::commonEditable($db,$b,$call)))throw new HttpError(422,'حذف از نسخه جاری در این وضعیت مجاز نیست.');
   Db::query($db,'UPDATE call_asset_links SET active=0 WHERE id=?',[$linkId]);
   if($w){$d=$w['data'];if($link['field_key']!=='main')$d['answers'][$link['field_key']]=array_values(array_filter($d['answers'][$link['field_key']]??[],fn($x)=>(int)$x!==(int)$link['asset_id']));Db::query($db,"UPDATE art_submissions SET payload=?,status='draft',revision=revision+1 WHERE id=?",[Protocol::encode($d),$w['id']]);Db::query($db,"UPDATE call_work_details SET review_state='draft' WHERE submission_id=?",[$w['id']]);return ['work_id'=>$w['id'],'revision'=>(int)$w['revision']+1];}
   $d=$b['data'];if(preg_match('/^s([0-9]+)__(.+)$/D',$link['field_key'],$m))$d['section_common'][$m[1]][$m[2]]=array_values(array_filter($d['section_common'][$m[1]][$m[2]]??[],fn($x)=>(int)$x!==(int)$link['asset_id']));else $d['common'][$link['field_key']]=array_values(array_filter($d['common'][$link['field_key']]??[],fn($x)=>(int)$x!==(int)$link['asset_id']));Db::query($db,'UPDATE call_batches SET payload=?,revision=revision+1 WHERE id=?',[Protocol::encode($d),$b['id']]);foreach(self::commonEditable($db,$b,$call) as $changed){Db::query($db,"UPDATE call_work_details SET review_state='draft' WHERE submission_id=?",[$changed]);Db::query($db,"UPDATE art_submissions SET status='draft',revision=revision+1 WHERE id=?",[$changed]);}return ['batch_id'=>$b['id'],'revision'=>(int)$b['revision']+1];
  });
 }
 private static function snapshot(PDO $db,array $actor,array $batch,array $work):array {
  $section=self::section($batch,(int)$work['section_id']);$cfg=$section['config'];$data=$work['data'];$errors=[];
  if(trim($work['title'])==='')$errors['title']='عنوان اثر الزامی است.';
  if($work['owner_mode']==='other'&&trim($work['owner']['name']??'')==='')$errors['owner_name']='نام صاحب اثر را وارد کنید.';
  $isText=($section['content']['mode']??'files')==='text'||!empty($cfg['text_enabled']);
  if(($section['content']['mode']??'files')==='text'&&!empty($section['content']['text_required'])&&trim($data['text']??'')==='')$errors['text']='متن اثر الزامی است.';
  if(!$isText&&trim($data['text']??'')!=='')$errors['text']='این بخش دریافت متن مستقل ندارد.';
  $files=$work['files'];if(count($files)<$cfg['min_files'])$errors['files']='حداقل فایل‌های لازم برای این اثر بارگذاری نشده است.';
  if($cfg['max_files']!==null&&count($files)>$cfg['max_files'])$errors['files']='تعداد فایل‌ها از سقف این اثر بیشتر است.';
  if(!$files&&trim($data['text']??'')==='')$errors['content']='متن یا فایل اثر لازم است.';
  if($errors)throw new ProtocolErrors($errors);
  $workFields=array_filter($cfg['fields']??[],fn($f)=>$f['scope']!=='common');$sharedFields=array_filter($cfg['fields']??[],fn($f)=>$f['scope']==='common');
  $answers=CallForms::validate($workFields,$data['answers']??[],true);self::validateAnswerFiles($db,(int)$batch['id'],(int)$work['id'],$workFields,$answers);
  $sectionCommon=CallForms::validate($sharedFields,$batch['data']['section_common'][$section['id']]??[],true);self::validateAnswerFiles($db,(int)$batch['id'],null,$sharedFields,$sectionCommon,'s'.$section['id'].'__');
  return ['title'=>$work['title'],'description'=>$data['description'],'text'=>$data['text'],'section'=>$section,'answers'=>$answers,'owner_mode'=>$work['owner_mode'],'owner_user_id'=>$work['owner_user_id'],'owner'=>$work['owner'],'ai_used'=>(bool)$work['ai_used'],'files'=>array_map(fn($f)=>['asset_id'=>(int)$f['asset_id'],'field_key'=>$f['field_key'],'filename'=>$f['filename'],'sequence_no'=>$f['sequence_no']],$files),'martyr'=>$batch['data']['martyr'],'martyr_definitions'=>$batch['schema']['martyr_definitions'],'common'=>$batch['data']['common'],'common_fields'=>$batch['schema']['config']['common_fields'],'section_common'=>$sectionCommon,'section_common_fields'=>array_values($sharedFields)];
 }
 public static function finalize(PDO $db,array $actor,int $batchId,array $in):array {
  MemberServices::requireMember($db,$actor);
  return Db::transaction($db,function()use($db,$actor,$batchId,$in){
   $hint=self::batch($db,$batchId,$actor,true);$call=CallCampaigns::get($db,(int)$hint['call_id'],false,true);$b=self::batch($db,$batchId,$actor,true,true);
   if(($in['accept_rules']??'')!=='1')throw new HttpError(422,'پذیرش قوانین فراخوان برای ثبت نهایی لازم است.');
   if(!hash_equals($call['rules_version'],(string)($in['rules_version']??'')))throw new HttpError(409,'قوانین به‌روز شده‌اند؛ متن جدید را بررسی و تأیید کنید.');
   $martyr=CallMartyrFields::validate($db,$actor,$b['schema']['martyr_definitions'],$b['data']['martyr'],true);
   $common=CallForms::validate($b['schema']['config']['common_fields'],$b['data']['common'],true);
   self::validateAnswerFiles($db,$batchId,null,$b['schema']['config']['common_fields'],$common);
   $ids=$in['work_ids']??Db::query($db,"SELECT submission_id FROM call_work_details WHERE batch_id=? AND review_state<>'deleted' ORDER BY submission_id",[$batchId])->fetchAll(PDO::FETCH_COLUMN);
   if(!is_array($ids)||!$ids||array_filter($ids,fn($v)=>!is_scalar($v)||!ctype_digit((string)$v)))throw new HttpError(422,'دست‌کم یک اثر اضافه کنید.');$result=[];$success=0;$now=date('Y-m-d H:i:s');
   foreach(array_unique(array_map('intval',$ids)) as $id){
    try{
     $w=self::get($db,$id,$actor,true,true);if((int)$w['batch_id']!==$batchId)throw new HttpError(404,'اثر متعلق به این ارسال نیست.');
     if($w['tracking']&&$w['review_state']!=='draft'){$result[$id]=['ok'=>true,'tracking'=>$w['tracking'],'already_submitted'=>true];$success++;continue;}
     if(!self::canEdit($call,$w))throw new HttpError(422,'مهلت ثبت این اثر تمام شده است.');
     $snap=self::snapshot($db,$actor,$b,$w);$snap['rules_version']=$call['rules_version'];$snap['rules_text']=$call['rules_text'];$snap['accepted_at']=$now;
     $version=(int)Db::query($db,'SELECT COALESCE(MAX(version),0)+1 FROM call_work_versions WHERE submission_id=?',[$id])->fetchColumn();
     $vid=Db::insert($db,'call_work_versions',['submission_id'=>$id,'version'=>$version,'snapshot'=>Protocol::encode($snap),'created_by'=>$actor['id'],'created_at'=>$now]);
     $tracking=$w['tracking']?:'NM-'.$call['id'].'-'.strtoupper(bin2hex(random_bytes(8)));
     Db::query($db,"UPDATE call_work_details SET tracking=?,review_state='submitted',latest_version_id=?,submitted_at=?,returned_at=NULL WHERE submission_id=?",[$tracking,$vid,$now,$id]);
     Db::query($db,"UPDATE art_submissions SET status='submitted',review_note='',revision=revision+1,updated_at=? WHERE id=?",[$now,$id]);
     CommunityHistory::add($db,'art_submission',$id,$actor,$w['review_state'],'submitted',Protocol::encode(['version_id'=>$vid,'tracking'=>$tracking]));
     self::notify($db,(int)$actor['id'],$id,'submitted-'.$vid,'اثر با کد '.$tracking.' دریافت شد.');
     $result[$id]=['ok'=>true,'tracking'=>$tracking,'version_id'=>$vid];$success++;
    }catch(ProtocolErrors $e){$result[$id]=['ok'=>false,'errors'=>$e->errors];}catch(HttpError $e){$result[$id]=['ok'=>false,'errors'=>['form'=>$e->getMessage()]];}
   }
   if($success){Db::query($db,"UPDATE call_batches SET state='submitted',accepted_rules_version=?,accepted_rules_text=?,accepted_at=?,revision=revision+1,updated_at=? WHERE id=?",[$call['rules_version'],$call['rules_text'],$now,$now,$batchId]);}
   return ['works'=>$result,'success'=>$success,'failed'=>count($result)-$success];
  });
 }
 public static function notify(PDO $db,int $user,int $work,string $event,string $message):void {
  $key='work-'.$work.'-'.$event;if(Db::query($db,'SELECT id FROM call_notifications WHERE event_key=?',[$key])->fetchColumn())return;
  Db::insert($db,'call_notifications',['user_id'=>$user,'submission_id'=>$work,'event_key'=>$key,'message'=>$message,'created_at'=>date('Y-m-d H:i:s')]);
 }
 public static function review(PDO $db,array $actor,int $id,array $in):void {
  CallSchema::permit($db,$actor,'submissions.review');
  Db::transaction($db,function()use($db,$actor,$id,$in){
   $w=self::get($db,$id,$actor,false,true);
   if(!$w['tracking']||!$w['latest_version_id']||$w['review_state']==='draft')throw new HttpError(422,'اثر باید ابتدا ثبت نهایی شود.');
   if((int)($in['revision']??-1)!==(int)$w['revision'])throw new HttpError(409,'اثر تغییر کرده است؛ صفحه را دوباره باز کنید.');
   $state=$in['review_state']??$w['review_state'];if(!in_array($state,['submitted','reviewing','needs_changes','approved','rejected'],true))throw new HttpError(422,'وضعیت بررسی معتبر نیست.');
   $note=Security::text($in,'note',10000,false);if($state==='needs_changes'&&$note==='')throw new HttpError(422,'توضیح اصلاح الزامی است.');
   $judgement=$w['judgement_data'];if(array_key_exists('judgement',$in)){$j=(array)$in['judgement'];$judgement=['result'=>Security::text($j,'result',2000,false),'award'=>Security::text($j,'award',1000,false),'rank'=>Security::text($j,'rank',100,false)];}
   $now=date('Y-m-d H:i:s');Db::query($db,'UPDATE call_work_details SET review_state=?,judgement=?,returned_at=? WHERE submission_id=?',[$state,Protocol::encode($judgement),$state==='needs_changes'?$now:null,$id]);
   Db::query($db,'UPDATE art_submissions SET status=?,review_note=?,reviewed_by=?,revision=revision+1,updated_at=? WHERE id=?',[$state,$note,$actor['id'],$now,$id]);
   CommunityHistory::add($db,'art_submission',$id,$actor,$w['review_state'],$state,Protocol::encode(['note'=>$note,'judgement'=>$judgement,'version_id'=>$w['latest_version_id']]));
   self::notify($db,(int)$w['user_id'],$id,'review-'.((int)$w['revision']+1),'وضعیت اثر '.$w['tracking'].' به «'.self::statusLabel($state).'» تغییر کرد.'.($note!==''?' '.$note:''));
  });
 }
 public static function deleteDraft(PDO $db,array $actor,int $id):void {Db::transaction($db,function()use($db,$actor,$id){$w=self::get($db,$id,$actor,true,true);if($w['tracking']||$w['publication_state']!=='private')throw new HttpError(422,'فقط اثر پیش‌نویس بدون کد رهگیری از فهرست حذف می‌شود.');Db::query($db,"UPDATE call_work_details SET review_state='deleted' WHERE submission_id=?",[$id]);Db::query($db,'UPDATE call_asset_links SET active=0 WHERE submission_id=?',[$id]);});}
 public static function statusLabel(string $state):string{return ['draft'=>'پیش‌نویس','submitted'=>'دریافت شد','reviewing'=>'در حال بررسی','needs_changes'=>'نیازمند اصلاح','approved'=>'پذیرفته شد','rejected'=>'پذیرفته نشد','deleted'=>'حذف از پیش‌نویس'][$state]??$state;}
}

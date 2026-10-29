<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallCampaigns {
 public const RIGHTS="هدف این فراخوان، انتشار آثار در نامیراست. ارسال‌کننده اجازه نمایش و دانلود رایگان فایل باکیفیت را با حفظ انتساب به صاحب اثر می‌دهد. اگر اثر متعلق به دیگری است، داشتن اجازه لازم برای ارسال و انتشار را تأیید می‌کند. پذیرش قوانین به معنی انتقال مالکیت اثر به نامیرا نیست. انتخاب صاحب اثر نامشخص، صرفاً وضعیت اطلاعات صاحب اثر را ثبت می‌کند و جایگزین پذیرش قوانین نیست.";
 public static function defaults():array {return ['workflow'=>2,'mode'=>'collection','accepting'=>true,'edit_policy'=>'until_deadline','formats'=>['jpg','jpeg','png','webp'],'max_file_mb'=>min(20,(int)Env::get('MAX_UPLOAD_MB','20')),'max_total_mb'=>null,'min_files'=>1,'max_files'=>null,'max_batch_works'=>null,'max_user_works'=>null,'common_fields'=>[],'martyr_fields'=>['first_name'=>['required'=>true],'last_name'=>['required'=>true]]];}
 public static function limit(mixed $in):?int {
  if($in===null||$in==='')return null;
  if(is_array($in)){if(($in['mode']??'')==='unlimited')return null;if(($in['mode']??'')!=='limited')throw new HttpError(422,'حالت تعداد مشخص یا بدون محدودیت را انتخاب کنید.');$in=$in['value']??null;}
  return CallForms::integer($in,1,2147483647);
 }
 public static function filePolicy(array $in,array $base=[]):array {
  $d=array_replace(self::defaults(),$base);$formats=$in['formats']??$d['formats'];if(!is_array($formats)||!$formats||array_diff($formats,['jpg','jpeg','png','webp','pdf','zip','psd','txt','docx']))throw new HttpError(422,'فرمت‌های مجاز فایل را درست انتخاب کنید.');
  $d['formats']=array_values(array_unique($formats));$d['max_file_mb']=CallForms::integer($in['max_file_mb']??$d['max_file_mb'],1,max(1,(int)Env::get('MAX_UPLOAD_MB','20')));
  foreach(['max_total_mb','max_files','max_batch_works','max_user_works'] as $key)if(array_key_exists($key,$in))$d[$key]=self::limit($in[$key]);
  $d['min_files']=CallForms::integer($in['min_files']??$d['min_files'],0,1000000);
  if($d['max_files']!==null&&$d['min_files']>$d['max_files'])throw new HttpError(422,'حداقل فایل از حداکثر بیشتر است.');return $d;
 }
 public static function date(mixed $value):?string {
  if($value===null||$value==='')return null;if(!is_string($value))throw new HttpError(422,'تاریخ و ساعت معتبر وارد کنید.');
  $value=str_replace('T',' ',$value);$value=substr($value,0,16);$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value);
  if(!$date||$date->format('Y-m-d H:i')!==$value)throw new HttpError(422,'تاریخ میلادی و ساعت تهران را بررسی کنید.');return $date->format('Y-m-d H:i:s');
 }
 public static function get(PDO $db,int $id,bool $public=false,bool $lock=false):array {
  $r=Db::query($db,'SELECT * FROM art_calls WHERE id=?'.($public?" AND status='published'":'').($lock?CallSchema::lock($db):''),[$id])->fetch();if(!$r)throw new HttpError(404,'فراخوان پیدا نشد.');
  $r['config']=array_replace(self::defaults(),Protocol::decode($r['criteria']));
  $r['rules_text']=self::RIGHTS."\n\n".$r['terms_text'];$r['rules_version']=hash('sha256',$r['rules_text']);
  $r['martyr_definitions']=CallMartyrFields::selection($db,$r['config']['martyr_fields']);$r['sections']=self::sections($db,$id,$public);
  return $r;
 }
 public static function listing(PDO $db,array $in=[],bool $public=false):array {
  $where=$public?"c.status='published'":'1=1';$args=[];$q=Security::text($in,'q',150,false);
  if($q!==''){$where.=' AND c.title LIKE ?';$args[]='%'.$q.'%';}
  if(!$public&&!empty($in['status'])){$where.=' AND c.status=?';$args[]=Security::text($in,'status',30);}
  return AdminQueries::paginate($db,'SELECT c.*','FROM art_calls c',$where,$args,['new'=>'c.id DESC','deadline'=>'c.end_at,c.id DESC'],$in,12);
 }
 public static function save(PDO $db,array $actor,array $in,int $id=0,?string $poster=null):int {
  CallSchema::requireReady($db);CallSchema::permit($db,$actor,'calls.edit');$old=$id?self::get($db,$id):null;
  $title=Security::text($in,'title',255);$description=Security::text($in,'description',40000,false);$rules=Security::text($in,'terms_text',40000,false);
  $start=self::date($in['start_at']??null);$end=self::date($in['end_at']??null);if($start&&$end&&$start>$end)throw new HttpError(422,'پایان فراخوان پیش از شروع است.');
  $config=self::filePolicy($in,$old['config']??[]);$config['workflow']=2;$config['mode']=$in['mode']??'collection';$config['edit_policy']=$in['edit_policy']??'until_deadline';$config['accepting']=!empty($in['accepting']);
  if(!in_array($config['mode'],['collection','competition'],true)||!in_array($config['edit_policy'],['until_deadline','returned_only'],true))throw new HttpError(422,'روش برگزاری یا اصلاح معتبر نیست.');
  $config['common_fields']=CallForms::definitions($in['common_fields']??($old['config']['common_fields']??[]));
  foreach($config['common_fields'] as &$f)$f['scope']='common';unset($f);
  $selection=$in['martyr_fields']??($old['config']['martyr_fields']??self::defaults()['martyr_fields']);if(!is_array($selection))throw new HttpError(422,'فیلدهای شهید معتبر نیستند.');CallMartyrFields::selection($db,$selection);$config['martyr_fields']=$selection;
  $slug=Security::text($in,'slug',150,false)?:($old['slug']??'call-'.bin2hex(random_bytes(8)));if(!preg_match('/^[a-z0-9][a-z0-9-]{0,149}$/D',$slug))throw new HttpError(422,'نامک معتبر وارد کنید.');
  if(Db::query($db,'SELECT id FROM art_calls WHERE slug=? AND id<>?',[$slug,$id])->fetchColumn())throw new HttpError(409,'نامک تکراری است.');
  $asset=null;
  try{return Db::transaction($db,function()use($db,$actor,$in,$id,$old,$title,$description,$rules,$start,$end,$config,$slug,$poster,&$asset){
   $now=date('Y-m-d H:i:s');$posterId=$old['poster_asset_id']??null;if($poster){$asset=Assets::put($db,$poster,(int)$actor['id']);$posterId=$asset['id'];}if(!empty($in['remove_poster']))$posterId=null;
   if($id){$n=Db::query($db,'UPDATE art_calls SET title=?,description=?,terms_text=?,start_at=?,end_at=?,criteria=?,slug=?,poster_asset_id=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?',[$title,$description,$rules,$start,$end,Protocol::encode($config),$slug,$posterId,$now,$id,(int)($in['revision']??-1)])->rowCount();if(!$n)throw new HttpError(409,'فراخوان در پنجره دیگری تغییر کرده است.');}
   else $id=Db::insert($db,'art_calls',['title'=>$title,'slug'=>$slug,'description'=>$description,'terms_text'=>$rules,'status'=>'draft','start_at'=>$start,'end_at'=>$end,'max_entries'=>$config['max_user_works']??2147483647,'poster_asset_id'=>$posterId,'criteria'=>Protocol::encode($config),'created_by'=>$actor['id'],'created_at'=>$now,'updated_at'=>$now]);
   CommunityHistory::add($db,'art_call',$id,$actor,$old['status']??'','saved',Protocol::encode(['revision'=>($old['revision']??-1)+1,'rules_version'=>hash('sha256',self::RIGHTS."\n\n".$rules)]));return $id;
  });}catch(\Throwable $e){if($asset)IncomingAssets::discardPaths([$asset['path']]);throw $e;}
 }
 public static function state(PDO $db,array $actor,int $id,array $in):void {
  CallSchema::permit($db,$actor,'calls.publish');$state=Security::text($in,'status',30);if(!in_array($state,['draft','published','paused','archived'],true))throw new HttpError(422,'وضعیت فراخوان معتبر نیست.');
  Db::transaction($db,function()use($db,$actor,$id,$in,$state){$old=self::get($db,$id,false,true);if($state==='published'&&!array_filter($old['sections'],fn($s)=>$s['active']))throw new HttpError(422,'ابتدا دست‌کم یک بخش فعال تعریف کنید.');
   if((int)($in['revision']??-1)!==(int)$old['revision'])throw new HttpError(409,'نسخه فراخوان تغییر کرده است.');
   Db::query($db,'UPDATE art_calls SET status=?,revision=revision+1,updated_at=? WHERE id=?',[$state,date('Y-m-d H:i:s'),$id]);CommunityHistory::add($db,'art_call',$id,$actor,$old['status'],$state,'تغییر صریح وضعیت فراخوان');
  });
 }
 public static function accepting(array $call):bool { $now=date('Y-m-d H:i:s');return $call['status']==='published'&&!empty($call['config']['accepting'])&&(!$call['start_at']||$call['start_at']<=$now)&&(!$call['end_at']||$call['end_at']>=$now);}
 public static function sections(PDO $db,int $call,bool $active=false):array {
  $rows=Db::query($db,'SELECT s.*,t.title AS content_type_title,t.metadata AS content_metadata FROM call_sections s LEFT JOIN protocol_terms t ON t.id=s.content_type_id WHERE s.call_id=?'.($active?' AND s.active=1':'').' ORDER BY s.sort_order,s.id',[$call])->fetchAll();
  foreach($rows as &$r){$r['config']=Protocol::decode($r['config']);$r['content']=Protocol::decode($r['content_metadata']??'{}');}unset($r);if($active){$all=Db::query($db,'SELECT id,parent_id,active FROM call_sections WHERE call_id=?',[$call])->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);$rows=array_values(array_filter($rows,function($r)use($all){$p=$r['parent_id'];$seen=[];while($p){if(isset($seen[$p])||empty($all[$p]['active']))return false;$seen[$p]=true;$p=$all[$p]['parent_id'];}return true;}));}return $rows;
 }
 public static function contentTypes(PDO $db):array {return Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy='call_content_type' ORDER BY title")->fetchAll();}
 public static function saveContentType(PDO $db,array $actor,array $in,int $id=0):int {
  CallSchema::permit($db,$actor,'calls.edit');$title=Security::text($in,'title',150);$mode=Security::text($in,'mode',20);if(!in_array($mode,['files','text'],true))throw new HttpError(422,'دریافت متن یا فایل را انتخاب کنید.');$slug=Security::text($in,'slug',100,false)?:'call-type-'.bin2hex(random_bytes(6));
  if(!preg_match('/^[a-z0-9_-]+$/D',$slug))throw new HttpError(422,'کلید پایدار معتبر وارد کنید.');
  if(Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='call_content_type' AND slug=? AND id<>?",[$slug,$id])->fetchColumn())throw new HttpError(422,'کلید نوع محتوا تکراری است.');$metadata=['mode'=>$mode,'text_required'=>!empty($in['text_required'])];
  if($id){if(!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='call_content_type'",[$id])->fetchColumn())throw new HttpError(404,'نوع محتوا پیدا نشد.');Db::query($db,'UPDATE protocol_terms SET title=?,slug=?,metadata=?,active=? WHERE id=?',[$title,$slug,Protocol::encode($metadata),!empty($in['active'])?1:0,$id]);}
  else $id=Db::insert($db,'protocol_terms',['taxonomy'=>'call_content_type','title'=>$title,'slug'=>$slug,'parent_id'=>null,'aliases'=>'[]','metadata'=>Protocol::encode($metadata),'active'=>1]);
  Db::audit($db,'call.content-type',(string)$id);return $id;
 }
 public static function saveSection(PDO $db,array $actor,int $callId,array $in,int $id=0):int {
  CallSchema::permit($db,$actor,'calls.edit');$call=self::get($db,$callId);$old=$id?Db::query($db,'SELECT * FROM call_sections WHERE id=? AND call_id=?',[$id,$callId])->fetch():null;if($id&&!$old)throw new HttpError(404,'بخش پیدا نشد.');
  $parent=(int)($in['parent_id']??0);$ancestors=[];$p=$parent;
  while($p){if($p===$id||isset($ancestors[$p]))throw new HttpError(422,'سلسله‌مراتب بخش دوری است.');$ancestors[$p]=true;$a=Db::query($db,'SELECT id,parent_id FROM call_sections WHERE id=? AND call_id=?',[$p,$callId])->fetch();if(!$a)throw new HttpError(422,'والد بخش متعلق به این فراخوان نیست.');$p=(int)$a['parent_id'];}
  $type=(int)($in['content_type_id']??0);if(!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='call_content_type' AND active=1",[$type])->fetchColumn())throw new HttpError(422,'نوع محتوای فعال انتخاب کنید.');
  $cfg=self::filePolicy($in,$call['config']);$cfg['fields']=CallForms::definitions($in['fields']??($old?Protocol::decode($old['config'])['fields']??[]:[]));
  $cfg['text_enabled']=!empty($in['text_enabled']);
  $data=['call_id'=>$callId,'parent_id'=>$parent?:null,'content_type_id'=>$type,'title'=>Security::text($in,'title',150),'sort_order'=>CallForms::integer($in['sort_order']??0,0,100000),'active'=>!empty($in['active'])?1:0,'config'=>Protocol::encode($cfg)];
  return Db::transaction($db,function()use($db,$actor,$callId,$id,$data){if($id)Db::query($db,'UPDATE call_sections SET parent_id=?,content_type_id=?,title=?,sort_order=?,active=?,config=? WHERE id=?',[$data['parent_id'],$data['content_type_id'],$data['title'],$data['sort_order'],$data['active'],$data['config'],$id]);else $id=Db::insert($db,'call_sections',$data);Db::query($db,'UPDATE art_calls SET revision=revision+1,updated_at=? WHERE id=?',[date('Y-m-d H:i:s'),$callId]);Db::audit($db,'call.section',(string)$id);return $id;});
 }
}

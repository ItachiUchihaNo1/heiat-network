<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallExport {
 private const PART_BYTES=104857600;
 private static function permit(PDO $db,array $actor):void {CallSchema::permit($db,$actor,'submissions.view');CallSchema::permit($db,$actor,'reports.archive');}
 private static function folder(array $job):string {return Env::storage().'/call-exports/'.$job['token'];}
 public static function get(PDO $db,array $actor,int $id):array {
  self::permit($db,$actor);$r=Db::query($db,'SELECT * FROM call_export_jobs WHERE id=? AND user_id=?',[$id,$actor['id']])->fetch();if(!$r)throw new HttpError(404,'خروجی شما پیدا نشد.');$r['data']=Protocol::decode($r['payload']);return $r;
 }
 public static function create(PDO $db,array $actor,array $filters,array $options):int {
  self::permit($db,$actor);[$from,$where,$args]=CallQueries::filter($db,$filters);
  if(!empty($options['selected_ids'])){$ids=$options['selected_ids'];if(!is_array($ids)||count($ids)>10000)throw new HttpError(422,'انتخاب آثار معتبر نیست.');$ids=array_values(array_unique(array_map(fn($v)=>CallForms::integer($v,1,2147483647),$ids)));$where.=' AND s.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=[...$args,...$ids];}
  $token=bin2hex(random_bytes(24));$folder=Env::storage().'/call-exports/'.$token;
  if(!mkdir($folder.'/rows',0700,true))throw new HttpError(503,'فضای ساخت خروجی در دسترس نیست.');file_put_contents(dirname($folder).'/.htaccess',"Require all denied\n");
  $stream=fopen($folder.'/selection.ndjson','wb');$total=0;
  try{$query=Db::query($db,'SELECT s.id,w.latest_version_id '.$from.' WHERE '.$where.' ORDER BY s.id',$args);while($r=$query->fetch()){if(!$r['latest_version_id'])continue;fwrite($stream,Protocol::encode(['id'=>(int)$r['id'],'version'=>(int)$r['latest_version_id']])."\n");$total++;}}finally{fclose($stream);}
  if(!$total)throw new HttpError(422,'اثری برای خروجی انتخاب نشده است.');
  $payload=['anonymous'=>!empty($options['anonymous']),'include_files'=>!empty($options['include_files']),'offset'=>0,'current'=>null,'part_no'=>1,'part_ids'=>[],'part_headers'=>[],'part_bytes'=>0,'part_meta_bytes'=>0,'parts'=>[],'selected_at'=>date(DATE_ATOM)];
  $now=date('Y-m-d H:i:s');$id=Db::insert($db,'call_export_jobs',['user_id'=>$actor['id'],'token'=>$token,'state'=>'building','payload'=>Protocol::encode($payload),'total'=>$total,'error_text'=>'','created_at'=>$now,'updated_at'=>$now]);Db::audit($db,'call.export.create',(string)$id);return $id;
 }
 private static function column(array &$row,string $label,string $value):void {
  $length=mb_strlen($value);if($length<=30000){$row[$label]=$value;return;}
  for($offset=0,$part=1;$offset<$length;$offset+=30000,$part++)$row[$label.' (بخش '.$part.')']=mb_substr($value,$offset,30000);
 }
 private static function record(PDO $db,array $actor,array $item,bool $anonymous,string $folder):array {
  $w=CallEntries::get($db,$item['id'],$actor);$version=Db::query($db,'SELECT * FROM call_work_versions WHERE id=? AND submission_id=?',[$item['version'],$item['id']])->fetch();if(!$version)throw new HttpError(409,'نسخه انتخاب‌شده برای خروجی موجود نیست.');
  $s=Protocol::decode($version['snapshot']);$call=Db::query($db,'SELECT title FROM art_calls WHERE id=?',[$w['call_id']])->fetchColumn();
  $row=['کد رهگیری'=>$w['tracking'],'فراخوان'=>$call,'عنوان اثر'=>$s['title'],'بخش'=>$s['section']['title'],'نوع محتوا'=>$s['section']['content_type_title'],'استفاده از هوش مصنوعی'=>$s['ai_used']?'بله':'خیر','وضعیت بررسی'=>CallEntries::statusLabel($w['review_state']),'وضعیت انتشار'=>$w['publication_state'],'نتیجه داوری'=>$w['judgement_data']['result']??'','عنوان برگزیدگی'=>$w['judgement_data']['award']??'','رتبه'=>$w['judgement_data']['rank']??'','زمان ثبت نهایی'=>$w['submitted_at'],'نسخه اثر'=>(string)$version['version'],'نسخه قوانین'=>$s['rules_version'],'زمان پذیرش قوانین'=>$s['accepted_at'],'نوع صاحب اثر'=>$s['owner_mode']];
  self::column($row,'توضیحات اثر',$s['description']);self::column($row,'متن اثر',$s['text']);
  foreach($s['martyr_definitions'] as $key=>$def)self::column($row,'شهید ـ '.$def['label'].' ['.$key.']',CallMartyrFields::value($db,$def,$s['martyr'][$key]??null));
  foreach(['common_fields'=>['common','مشترک ـ '],'work_fields'=>['answers','اثر ـ ']] as $kind=>[$answerKey,$prefix]){
   $defs=$kind==='common_fields'?$s['common_fields']:($s['section']['config']['fields']??[]);
   foreach($defs as $f){$answer=CallForms::answerLabels([$f],$s[$answerKey]??[]);self::column($row,$prefix.$f['label'].' ['.$f['key'].']',(string)($answer[$f['label']]??''));}
  }
  if(!$anonymous){
   $sender=Db::query($db,'SELECT u.email,i.first_name,i.last_name,i.mobile,p.display_name FROM users u LEFT JOIN account_identities i ON i.user_id=u.id LEFT JOIN account_profiles p ON p.user_id=u.id WHERE u.id=?',[$w['user_id']])->fetch();
   $row['نام ارسال‌کننده']=trim(($sender['first_name']??'').' '.($sender['last_name']??''))?:($sender['display_name']??'');
   $row['موبایل ارسال‌کننده']=$sender['mobile']??'';$row['ایمیل ارسال‌کننده']=$sender['email'];$row['نام صاحب اثر']=$s['owner']['name']??'';$row['توضیحات صاحب اثر']=$s['owner']['details']??'';
  }
  foreach(CallForms::answerLabels($s['section_common_fields']??[],$s['section_common']??[]) as $label=>$value)self::column($row,'مشترک بخش ـ '.$label,(string)$value);
  $assetIds=array_column($s['files'],'asset_id');foreach($s['section_common_fields']??[] as $f)if($f['type']==='file')$assetIds=[...$assetIds,...($s['section_common'][$f['key']]??[])];foreach($s['common_fields'] as $f)if($f['type']==='file')$assetIds=[...$assetIds,...($s['common'][$f['key']]??[])];$files=[];$links=[];$names=[];$n=1;
  foreach(array_unique(array_map('intval',$assetIds)) as $id){
   $a=CallFiles::privateFile($db,$id,$actor);$absolute=Upload::absolute($a['original_path']);if(!is_file($absolute))throw new HttpError(409,'فایل اثر '.$w['tracking'].' در مخزن موجود نیست؛ خروجی ناقص ساخته نشد.');
   $ext=strtolower(pathinfo($a['filename'],PATHINFO_EXTENSION));$name=$w['tracking'].'/'.str_pad((string)$n++,3,'0',STR_PAD_LEFT).'.'.$ext;
   $files[]=['source'=>$absolute,'name'=>$name,'bytes'=>(int)$a['bytes']];$names[]=$name;$links[]=rtrim(Env::get('APP_URL'),'/').url('call-file',['id'=>$id]);
  }
  $row['فایل‌های مرتبط در ZIP']=implode("\n",$names);$row['لینک خصوصی فایل‌ها']=implode("\n",$links);
  if($s['text']!==''){$path=$folder.'/rows/'.$item['id'].'-text.txt';file_put_contents($path,$s['text']);$files[]=['source'=>$path,'name'=>$w['tracking'].'/work.txt','bytes'=>filesize($path)];}
  $record=$folder.'/rows/'.$item['id'].'-record.json';file_put_contents($record,Protocol::encode($row));$files[]=['source'=>$record,'name'=>$w['tracking'].'/record.json','bytes'=>filesize($record)];
  return ['id'=>$item['id'],'version'=>$item['version'],'row'=>$row,'row_path'=>$record,'files'=>$files,'file_cursor'=>0,'tracking'=>$w['tracking']];
 }
 private static function zipPath(string $folder,int $part):string {return $folder.'/calls-part-'.str_pad((string)$part,3,'0',STR_PAD_LEFT).'.zip';}
 private static function addFile(string $zipPath,string $source,string $name):void {
  $temp=$zipPath.'.'.bin2hex(random_bytes(6)).'.tmp';if(is_file($zipPath)&&!copy($zipPath,$temp))throw new HttpError(503,'نسخه امن خروجی ساخته نشد.');
  $zip=new \ZipArchive();try{if($zip->open($temp,\ZipArchive::CREATE)!==true)throw new HttpError(503,'ساخت ZIP ممکن نیست.');if(!$zip->addFile($source,$name))throw new HttpError(503,'افزودن فایل به خروجی انجام نشد.');$zip->setCompressionName($name,\ZipArchive::CM_STORE);if(!$zip->close())throw new HttpError(503,'تکمیل فایل ZIP انجام نشد.');if(!rename($temp,$zipPath))throw new HttpError(503,'ثبت فایل ZIP انجام نشد.');}finally{if(is_file($temp))unlink($temp);}
 }
 private static function finishPart(array &$p,string $folder):void {
  if(!$p['part_ids'])return;$rows=[];foreach($p['part_ids'] as $id){$row=Protocol::decode((string)file_get_contents($folder.'/rows/'.$id.'-record.json'));$out=[];foreach($p['part_headers'] as $key)$out[$key]=$row[$key]??'';$rows[]=$out;}
  $base='calls-part-'.str_pad((string)$p['part_no'],3,'0',STR_PAD_LEFT);$xlsx=$folder.'/'.$base.'.xlsx';Xlsx::write(['آثار'=>$rows],$xlsx);
  if($p['include_files']){
   self::addFile(self::zipPath($folder,$p['part_no']),$xlsx,'index.xlsx');
   $readme="خروجی فراخوان نامیرا\nهر پوشه با کد رهگیری مستقل نام‌گذاری شده است. برای فایل‌های یک اثر به ردیف همان کد در index.xlsx مراجعه کنید. خروجی‌های بزرگ چندبسته‌ای هستند.\n".($p['anonymous']?"مشخصات ارسال‌کننده و صاحب اثر از فراداده حذف شده‌اند؛ خود محتوای اثر و امضاهای داخل آن تغییر نکرده‌اند.\n":"خروجی دارای اطلاعات خصوصی است؛ فقط برای رسیدگی مجاز استفاده کنید.\n");
   $txt=$folder.'/README.txt';file_put_contents($txt,$readme);self::addFile(self::zipPath($folder,$p['part_no']),$txt,'README.txt');
  }
  $p['parts'][]=['number'=>$p['part_no'],'xlsx'=>$base.'.xlsx','zip'=>$p['include_files']?$base.'.zip':null,'codes'=>count($p['part_ids'])];
  $p['part_no']++;$p['part_ids']=[];$p['part_headers']=[];$p['part_bytes']=0;$p['part_meta_bytes']=0;
 }
 public static function step(PDO $db,array $actor,int $id):array {
  $initial=self::get($db,$actor,$id);$folder=self::folder($initial);$gate=fopen($folder.'/.build.lock','c');if(!$gate||!flock($gate,LOCK_EX|LOCK_NB))throw new HttpError(409,'ساخت همین خروجی در حال اجراست.');
  try{return Db::transaction($db,function()use($db,$actor,$id,$folder){
   $job=Db::query($db,'SELECT * FROM call_export_jobs WHERE id=? AND user_id=?'.CallSchema::lock($db),[$id,$actor['id']])->fetch();if(!$job)throw new HttpError(404,'خروجی پیدا نشد.');
   if($job['state']==='ready')return self::status(self::get($db,$actor,$id));
   $p=Protocol::decode($job['payload']);$cursor=(int)$job['cursor_no'];$start=microtime(true);$ops=0;$bytes=0;$done=false;
   while($ops<20&&microtime(true)-$start<4){
    if(!$p['current']){
     $stream=fopen($folder.'/selection.ndjson','rb');fseek($stream,(int)$p['offset']);$line=fgets($stream);$next=ftell($stream);fclose($stream);
     if($line===false){self::finishPart($p,$folder);$done=true;break;}
     $record=self::record($db,$actor,Protocol::decode($line),$p['anonymous'],$folder);$record['next_offset']=$next;
     $headers=array_values(array_unique([...$p['part_headers'],...array_keys($record['row'])]));$meta=filesize($record['row_path']);
     if($p['part_ids']&&(count($p['part_ids'])>=200||$p['part_meta_bytes']+$meta>2097152||count($headers)>15000)){self::finishPart($p,$folder);break;}
     $p['current']=$record;
    }
    $record=&$p['current'];$workId=(int)$record['id'];
    if($p['include_files']&&$record['file_cursor']<count($record['files'])&&$p['part_bytes']&&$p['part_bytes']+$record['files'][$record['file_cursor']]['bytes']>self::PART_BYTES){self::finishPart($p,$folder);break;}
    if(!in_array($workId,$p['part_ids'],true)){$p['part_ids'][]=$workId;$p['part_headers']=array_values(array_unique([...$p['part_headers'],...array_keys($record['row'])]));$p['part_meta_bytes']+=filesize($record['row_path']);}
    if($p['include_files']&&$record['file_cursor']<count($record['files'])){
     $file=$record['files'][$record['file_cursor']];
     if($p['part_bytes']&&$p['part_bytes']+$file['bytes']>self::PART_BYTES){self::finishPart($p,$folder);break;}
     if($bytes&&$bytes+$file['bytes']>26214400)break;
     $free=disk_free_space($folder);if($free!==false&&$free<$file['bytes']*2+10485760)throw new HttpError(503,'فضای آزاد برای خروجی کافی نیست؛ فایل ناقص تحویل داده نشد.');
     $used=0;foreach(glob($folder.'/*') as $fileOnDisk)if(is_file($fileOnDisk))$used+=filesize($fileOnDisk);
     if($used+$file['bytes']>max(100,(int)Env::get('CALL_EXPORT_QUOTA_MB','1024'))*1048576)throw new HttpError(413,'حجم خروجی از فضای تخصیص‌یافته بیشتر است؛ فیلتر کوچک‌تر یا ظرفیت بیشتر انتخاب کنید.');
     self::addFile(self::zipPath($folder,$p['part_no']),$file['source'],$file['name']);$p['part_bytes']+=$file['bytes'];$bytes+=$file['bytes'];$record['file_cursor']++;$ops++;
    }else{$p['offset']=$record['next_offset'];$p['current']=null;$cursor++;$ops++;}
    unset($record);
   }
   Db::query($db,'UPDATE call_export_jobs SET payload=?,cursor_no=?,state=?,error_text=?,revision=revision+1,updated_at=? WHERE id=?',[Protocol::encode($p),$cursor,$done?'ready':'building','',date('Y-m-d H:i:s'),$id]);
   return self::status(self::get($db,$actor,$id));
  });}catch(\Throwable $e){Db::query($db,"UPDATE call_export_jobs SET state='error',error_text=?,updated_at=? WHERE id=? AND user_id=?",[$e instanceof HttpError?$e->getMessage():'ساخت خروجی متوقف شد؛ می‌توانید تلاش مجدد کنید.',date('Y-m-d H:i:s'),$id,$actor['id']]);throw $e;}finally{flock($gate,LOCK_UN);fclose($gate);}
 }
 public static function status(array $job):array {
  return ['id'=>$job['id'],'state'=>$job['state'],'processed'=>(int)$job['cursor_no'],'total'=>(int)$job['total'],'parts'=>$job['data']['parts'],'anonymous'=>$job['data']['anonymous'],'error'=>$job['error_text']];
 }
 public static function download(PDO $db,array $actor,int $id,int $part,string $kind):array {
  $job=self::get($db,$actor,$id);if($job['state']!=='ready'||!in_array($kind,['xlsx','zip'],true))throw new HttpError(404,'خروجی آماده پیدا نشد.');
  foreach($job['data']['parts'] as $p)if((int)$p['number']===$part&&!empty($p[$kind])){$path=self::folder($job).'/'.$p[$kind];if(!is_file($path))break;Db::audit($db,'call.export.download',(string)$id);return ['path'=>$path,'filename'=>$p[$kind],'mime'=>$kind==='zip'?'application/zip':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];}
  throw new HttpError(404,'بسته خروجی پیدا نشد.');
 }
}

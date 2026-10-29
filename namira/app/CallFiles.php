<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallFiles {
 public static function inspect(string $source,string $filename,array $policy):array {
  $name=basename(str_replace('\\','/',$filename));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$size=filesize($source);
  if(!$size||$size>($policy['max_file_mb']??20)*1048576||mb_strlen($name)>255||!in_array($ext,$policy['formats']??[],true))throw new HttpError(422,'نام، حجم یا فرمت فایل با شرایط این بخش سازگار نیست.');
  $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);$meta=null;
  if(in_array($ext,['jpg','jpeg','png','webp'],true)){
   $meta=Upload::validate($source);$expected=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];if($meta['mime']!==$expected[$ext])throw new HttpError(422,'پسوند تصویر با نوع واقعی آن سازگار نیست.');$mime=$meta['mime'];
  }elseif($ext==='pdf'){
   if($mime!=='application/pdf'||file_get_contents($source,false,null,0,5)!=='%PDF-')throw new HttpError(422,'PDF معتبر نیست.');
  }elseif(in_array($ext,['zip','docx'],true)){
   $zip=new \ZipArchive();if($zip->open($source)!==true)throw new HttpError(422,'فایل فشرده معتبر نیست.');try{
    if($zip->numFiles>1000)throw new HttpError(422,'اجزای فایل فشرده بیش از ظرفیت مجاز است.');$total=0;
    for($i=0;$i<$zip->numFiles;$i++){$part=$zip->statIndex($i);$nameIn=str_replace('\\','/',$part['name']);$total+=$part['size'];
     if($total>200*1048576||preg_match('~(^/|(^|/)\.\.(/|$)|^[a-z]:)~i',$nameIn)||preg_match('/\.(php\d?|phtml|phar|exe|dll|com|js|html|svg|bat|cmd|sh|vbs|ps1)$/i',$nameIn)||str_contains(strtolower($nameIn),'vbaproject.bin'))throw new HttpError(422,'محتوای فایل فشرده مجاز نیست.');
    }
    if($ext==='docx'&&($zip->locateName('[Content_Types].xml')===false||$zip->locateName('word/document.xml')===false))throw new HttpError(422,'سند Word معتبر نیست.');
   }finally{$zip->close();}$mime=$ext==='docx'?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'application/zip';
  }elseif($ext==='txt'){
   $text=file_get_contents($source);if(!mb_check_encoding($text,'UTF-8')||str_contains($text,"\0"))throw new HttpError(422,'متن باید UTF-8 معتبر باشد.');$mime='text/plain';
  }elseif($ext==='psd'){
   $head=file_get_contents($source,false,null,0,26);
   if(strlen($head)!==26||substr($head,0,4)!=='8BPS'||substr($head,4,2)!=="\0\1"||substr($head,6,6)!==str_repeat("\0",6))throw new HttpError(422,'PSD معتبر نیست.');
   $h=unpack('nchannels/Nheight/Nwidth/ndepth/nmode',substr($head,12));if(min($h['width'],$h['height'])<1||max($h['width'],$h['height'])>30000||$h['channels']<1||$h['channels']>56||!in_array($h['depth'],[1,8,16,32],true))throw new HttpError(422,'مشخصات PSD معتبر نیست.');
   $stream=fopen($source,'rb');try{fseek($stream,26);for($i=0;$i<3;$i++){$l=fread($stream,4);if(strlen($l)!==4)throw new HttpError(422,'PSD ناقص است.');$length=unpack('N',$l)[1];if(ftell($stream)+$length>$size-2)throw new HttpError(422,'PSD ناقص است.');fseek($stream,$length,SEEK_CUR);}if(strlen(fread($stream,2))!==2||ftell($stream)>=$size)throw new HttpError(422,'داده تصویر PSD ناقص است.');}finally{fclose($stream);}$mime='image/vnd.adobe.photoshop';
  }else throw new HttpError(422,'این فرمت فایل پشتیبانی نمی‌شود.');
  return ['filename'=>$name,'extension'=>$ext,'mime'=>$mime,'bytes'=>$size,'meta'=>$meta];
 }
 public static function store(PDO $db,array $actor,string $source,string $name,array $policy,?string $companion=null):array {
  $file=self::inspect($source,$name,$policy);
  if($file['meta'])return IncomingAssets::save($db,(int)$actor['id'],$source,$companion,$policy['max_file_mb']*1048576)+['filename'=>$file['filename'],'extension'=>$file['extension']];
  $saved=null;
  try{return Db::transaction($db,function()use($db,$actor,$source,$file,&$saved){
   CommunitySchema::requireReady($db);Db::query($db,"SELECT setting_key FROM app_settings WHERE setting_key='incoming_storage_gate'".CallSchema::lock($db))->fetchColumn();
   $used=IncomingAssets::usage($db,(int)$actor['id']);$total=(int)Db::query($db,'SELECT COALESCE(SUM(stored_bytes),0) FROM incoming_assets')->fetchColumn();
   if($used['used']+$file['bytes']>$used['limit'])throw new HttpError(413,'فضای دریافت حساب تکمیل شده است.');
   if($total+$file['bytes']>max(20,(int)Env::get('INCOMING_TOTAL_QUOTA_MB','2048'))*1048576)throw new HttpError(503,'ظرفیت دریافت فایل سامانه تکمیل شده است.');
   $folder=Env::storage().'/incoming-originals';if(!is_dir($folder)&&!mkdir($folder,0700,true))throw new HttpError(503,'ذخیره فایل ممکن نیست.');
   $saved='incoming-originals/'.bin2hex(random_bytes(24)).'.'.$file['extension'];if(!copy($source,Env::storage().'/'.$saved))throw new HttpError(503,'ذخیره فایل انجام نشد.');chmod(Env::storage().'/'.$saved,0600);
   $data=['owner_id'=>$actor['id'],'original_path'=>$saved,'preview_path'=>'','mime'=>$file['mime'],'sha256'=>hash_file('sha256',$source),'width'=>0,'height'=>0,'bytes'=>$file['bytes'],'stored_bytes'=>$file['bytes'],'created_at'=>date('Y-m-d H:i:s')];
   $id=Db::insert($db,'incoming_assets',$data);return ['id'=>$id]+$data+$file;
  });}catch(\Throwable $e){if($saved&&is_file(Env::storage().'/'.$saved))unlink(Env::storage().'/'.$saved);throw $e;}
 }
 public static function removeCreated(array $asset):void {foreach(['original_path','preview_path'] as $key)if(!empty($asset[$key])&&is_file(Env::storage().'/'.$asset[$key]))unlink(Env::storage().'/'.$asset[$key]);}
 public static function privateFile(PDO $db,int $assetId,array|false $actor):array {
  if(!$actor)throw new HttpError(404,'فایل پیدا نشد.');
  $file=Db::query($db,'SELECT a.*,l.filename,b.user_id FROM incoming_assets a JOIN call_asset_links l ON l.asset_id=a.id JOIN call_batches b ON b.id=l.batch_id WHERE a.id=?',[$assetId])->fetch();
  if(!$file||((int)$file['user_id']!==(int)$actor['id']&&!Permissions::allows($db,$actor,'submissions.view')))throw new HttpError(404,'فایل پیدا نشد.');return $file;
 }
 public static function publicFile(PDO $db,int $publication):array {
  $r=Db::query($db,"SELECT a.*,p.payload FROM call_publications p JOIN incoming_assets a ON a.id=p.asset_id JOIN call_work_details w ON w.submission_id=p.submission_id JOIN martyrs m ON m.id=p.martyr_id WHERE p.id=? AND p.visible=1 AND w.published_version_id=p.version_id AND w.publication_state='published' AND m.published=1",[$publication])->fetch();
  if(!$r)throw new HttpError(404,'فایل منتشرشده پیدا نشد.');return $r;
 }
}

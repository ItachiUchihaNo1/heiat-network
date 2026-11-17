<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Original public media only; temporary ZIP streams from disk, not PHP memory. */
final class PersonDownload {
 public static function available(PDO $db,int $person):bool {
  $scope=PlatformSchema::ready($db)?' JOIN media_public_martyrs r ON r.image_id=i.id JOIN media_publication p ON p.image_id=i.id WHERE r.martyr_id=? AND p.download_allowed=1':' WHERE i.martyr_id=?';
  return (bool)Db::query($db,'SELECT 1 FROM images i'.$scope.' AND '.MartyrDirectory::imageCondition('i').' LIMIT 1',[$person])->fetchColumn();
 }
 public static function images(PDO $db,int $person):array {
  if(!(new Archive($db))->find($person,true))throw new HttpError(404,'شهید پیدا نشد.');
  $valid=MartyrDirectory::imageCondition('i');
  $scope=PlatformSchema::ready($db)?' JOIN media_public_martyrs r ON r.image_id=i.id JOIN media_publication p ON p.image_id=i.id WHERE r.martyr_id=? AND p.download_allowed=1':' WHERE i.martyr_id=?';
  return Db::query($db,'SELECT DISTINCT i.id,i.martyr_id,i.original_path,i.mime FROM images i'.$scope.' AND '.$valid.' ORDER BY i.id',[$person])->fetchAll();
 }
 public static function build(PDO $db,int $person):array {
  if(!class_exists(\ZipArchive::class))throw new HttpError(503,'امکان ساخت فایل فشرده روی سرور فعال نیست.');
  $images=self::images($db,$person);if(!$images)throw new HttpError(404,'تصویر منتشرشده با اجازه دانلود برای این شهید وجود ندارد.');
  $dir=Env::storage().'/downloads';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new HttpError(503,'فضای دانلود آماده نیست.');
  $file=tempnam($dir,'person-');if($file===false)throw new HttpError(503,'ساخت فایل دانلود انجام نشد.');chmod($file,0600);$zip=new \ZipArchive();$opened=false;
  try{
   if($zip->open($file,\ZipArchive::OVERWRITE)!==true)throw new HttpError(503,'ساخت فایل فشرده انجام نشد.');$opened=true;$ids=[];$name='';
   foreach($images as $image){$absolute=Upload::absolute($image['original_path']);$read=@fopen($absolute,'rb');if(!$read)throw new HttpError(503,'یکی از فایل‌های اصلی در دسترس نیست؛ دانلود کامل انجام نشد.');fclose($read);$image['martyr_id']=$person;$base=ImageCode::downloadName($image);$dot=strrpos($base,'.');$entry=substr($base,0,$dot).'-'.ImageCode::format((int)$image['id']).substr($base,$dot);$name=substr($base,0,$dot).'.zip';if(!$zip->addFile($absolute,$entry)||!$zip->setCompressionName($entry,\ZipArchive::CM_STORE))throw new HttpError(503,'افزودن تصویر به دانلود انجام نشد.');$ids[]=(int)$image['id'];}
   $closed=$zip->close();$opened=false;if(!$closed)throw new HttpError(503,'تکمیل فایل فشرده انجام نشد.');return ['path'=>$file,'name'=>$name,'ids'=>$ids];
  }catch(\Throwable $e){if($opened){try{@$zip->close();}catch(\Throwable){}}if(is_file($file))unlink($file);throw $e;}
 }
 public static function send(PDO $db,int $person):void {
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new HttpError(405,'فقط دریافت مجاز است.');
  Security::limit($db,'person-download',6,60);$bundle=self::build($db,$person);
  $previousAbort=ignore_user_abort(true);try{foreach(array_chunk($bundle['ids'],500) as $ids)Db::query($db,'UPDATE images SET downloads=downloads+1 WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);Web::sendFile($bundle['path'],'application/zip','attachment',$bundle['name']);}finally{if(is_file($bundle['path']))unlink($bundle['path']);ignore_user_abort((bool)$previousAbort);}
 }
}

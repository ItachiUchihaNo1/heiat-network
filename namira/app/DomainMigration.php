<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** A single-use, key-authorized copy into an empty, separately provisioned database. */
final class DomainMigration {
 public static function copy(string $root,PDO $target,array $config):array {
  $metaPath=$root.'/.migration-source.json';$meta=is_file($metaPath)?json_decode(file_get_contents($metaPath),true,16,JSON_THROW_ON_ERROR):null;
  if(!is_array($meta)||empty($meta['root']))throw new HttpError(503,'مبدأ انتقال خصوصی تنظیم نشده است.');
  $sourceRoot=realpath($meta['root']);$home=realpath(dirname($root));if(!$sourceRoot||!$home||!str_starts_with(str_replace('\\','/',$sourceRoot).'/',str_replace('\\','/',$home).'/')||$sourceRoot===realpath($root))throw new HttpError(503,'مبدأ انتقال باید پوشه خصوصی مستقل در همین حساب باشد.');
  $sourceConfigPath=$sourceRoot.'/config.local.json';if(!is_file($sourceConfigPath))throw new HttpError(503,'تنظیمات JSON خصوصی مبدأ موجود نیست؛ انتقال متوقف شد.');
  $old=json_decode(file_get_contents($sourceConfigPath),true,16,JSON_THROW_ON_ERROR);if(!is_array($old)||empty($old['DB_DSN'])||$old['DB_DSN']===($config['DB_DSN']??''))throw new HttpError(422,'دیتابیس مقصد باید مستقل از مبدأ باشد.');
  if(DataBackup::tables($target))throw new HttpError(422,'انتقال فقط به دیتابیس کاملاً خالی مجاز است؛ مقصد موجود تغییر نکرد.');
  $source=new PDO($old['DB_DSN'],$old['DB_USER']??'',$old['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  if($source->getAttribute(PDO::ATTR_DRIVER_NAME)!==$target->getAttribute(PDO::ATTR_DRIVER_NAME))throw new HttpError(422,'نوع دیتابیس مبدأ و مقصد یکسان نیست.');
  $sourceStorage=realpath($old['STORAGE_PATH']??($sourceRoot.'/storage'));$destStorage=realpath(Env::storage());
  if(!$sourceStorage||!$destStorage||!str_starts_with(str_replace('\\','/',$sourceStorage).'/',str_replace('\\','/',$home).'/')||str_starts_with(str_replace('\\','/',$destStorage).'/',str_replace('\\','/',$sourceStorage).'/'))throw new HttpError(503,'پوشه فایل‌های مبدأ یا مقصد معتبر نیست.');
  $backup=DataBackup::create($source,'before-naamiraa-domain-copy');$sql=Env::storage().'/backups/'.$backup['name'].'.sql';if(filesize($sql)>67108864)throw new HttpError(413,'پشتیبان از ظرفیت نصب مرورگری بیشتر است؛ برای انتقال مدیریت‌شده اقدام کنید.');
  @set_time_limit(240);$target->exec(file_get_contents($sql));$expected=$backup['tables'];$actual=DataBackup::counts($target);ksort($expected);ksort($actual);if($actual!==$expected)throw new HttpError(503,'شمارش رکوردهای نسخه کپی با پشتیبان برابر نیست.');$files=0;$bytes=0;
  $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceStorage,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
  foreach($iterator as $file){if($file->isLink())throw new HttpError(503,'پیوند نمادین در مخزن مبدأ نیاز به بررسی دارد.');$relative=substr($file->getPathname(),strlen($sourceStorage)+1);$to=$destStorage.DIRECTORY_SEPARATOR.$relative;
   if($file->isDir()){if(!is_dir($to)&&!mkdir($to,0700,true))throw new HttpError(503,'ساخت پوشه مقصد ممکن نیست.');continue;}
   if(is_file($to)&&hash_file('sha256',$to)!==hash_file('sha256',$file->getPathname()))throw new HttpError(409,'فایل متفاوتی در مخزن مقصد وجود دارد؛ بازنویسی نشد.');
   if(!is_file($to)&&!copy($file->getPathname(),$to))throw new HttpError(503,'انتقال فایل کامل نشد.');chmod($to,0600);$files++;$bytes+=$file->getSize();
  }
  $actor=Db::query($target,"SELECT id,email,role FROM users WHERE role='superadmin' AND active=1 ORDER BY id LIMIT 1")->fetch();if(!$actor)throw new HttpError(422,'مدیر اصلی معتبر در مبدأ پیدا نشد.');AdminPanel::boot($target,$actor);
  $merged=array_replace($old,$config,['PUBLIC_APP_URL'=>DomainRouting::origin('PUBLIC_APP_URL'),'ADMIN_APP_URL'=>DomainRouting::origin('ADMIN_APP_URL'),'STORAGE_PATH'=>$destStorage,'PAYMENT_DRIVER'=>'disabled']);
  $json=Protocol::encode($merged);$handle=fopen($root.'/config.local.json','x');if(!$handle)throw new HttpError(503,'ساخت تنظیمات مقصد انجام نشد.');try{chmod($root.'/config.local.json',0600);if(fwrite($handle,$json)!==strlen($json))throw new HttpError(503,'تنظیمات کامل ذخیره نشد.');fflush($handle);}finally{fclose($handle);}
  $report=['source_tables'=>DataBackup::counts($source),'target_tables'=>DataBackup::counts($target),'copied_files'=>$files,'copied_bytes'=>$bytes,'source_modified'=>false,'backup'=>$backup['name'],'completed_at'=>date(DATE_ATOM)];file_put_contents($root.'/domain-migration-report.json',Protocol::encode($report),LOCK_EX);chmod($root.'/domain-migration-report.json',0600);
  file_put_contents($root.'/.setup.lock',date(DATE_ATOM),LOCK_EX);chmod($root.'/.setup.lock',0600);unlink($root.'/.setup.key');unset($_SESSION['install_grant'],$_SESSION['install_grant_at']);return $report;
 }
}

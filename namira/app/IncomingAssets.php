<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class IncomingAssets {
    public static function save(PDO $db,int $owner,string $source,?string $companion=null,int $maxBytes=20971520):array {
        return Db::transaction($db,fn()=>self::persist($db,$owner,$source,$companion,$maxBytes));
    }
    public static function usage(PDO $db,int $owner):array {
        return ['used'=>(int)Db::query($db,'SELECT COALESCE(SUM(stored_bytes),0) FROM incoming_assets WHERE owner_id=?',[$owner])->fetchColumn(),'limit'=>max(20,(int)Env::get('INCOMING_USER_QUOTA_MB','500'))*1048576];
    }
    private static function persist(PDO $db,int $owner,string $source,?string $companion,int $maxBytes):array {
        CommunitySchema::requireReady($db);
        if(!Db::query($db,'SELECT id FROM users WHERE id=? AND active=1',[$owner])->fetchColumn())throw new HttpError(403,'حساب فعال لازم است.');
        $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        if(!Db::query($db,"SELECT setting_key FROM app_settings WHERE setting_key='incoming_storage_gate'".$lock)->fetchColumn())throw new HttpError(503,'سازوکار محدودیت فضای دریافت فعال نشده است.');
        $usage=self::usage($db,$owner);$total=(int)$db->query('SELECT COALESCE(SUM(stored_bytes),0) FROM incoming_assets')->fetchColumn();$global=max(20,(int)Env::get('INCOMING_TOTAL_QUOTA_MB','2048'))*1048576;
        $meta=Upload::validate($source);if($meta['bytes']>$maxBytes)throw new HttpError(422,'حجم اصل فایل از محدودیت این فراخوان بیشتر است.');
        if($usage['used']+$meta['bytes']>$usage['limit'])throw new HttpError(413,'فضای دریافت فایل برای حساب شما تکمیل شده است؛ برای افزایش ظرفیت با مدیریت هماهنگ کنید.');
        if($total+$meta['bytes']>$global)throw new HttpError(503,'ظرفیت دریافت فایل سامانه تکمیل شده است؛ مدیریت باید فضای دریافت را بررسی کند.');
        $companionMeta=$companion?Upload::companion($meta,$companion):null;$decode=$source;$decodeMeta=$meta;
        try{Upload::assertDecodeMemory($meta);}catch(HttpError $e){if(!$companionMeta)throw $e;$decode=$companion;$decodeMeta=$companionMeta;Upload::assertDecodeMemory($decodeMeta);}
        $files=[];try{
            $saved=Upload::storePrivate($source,'incoming-originals');$files[]=$saved['path'];
            $image=match($decodeMeta['mime']){'image/jpeg'=>@imagecreatefromjpeg($decode),'image/png'=>@imagecreatefrompng($decode),'image/webp'=>@imagecreatefromwebp($decode)};
            if(!$image)throw new HttpError(422,'پیش‌نمایش تصویر قابل پردازش نیست.');
            $scale=min(1,1600/max($decodeMeta['width'],$decodeMeta['height']));$width=max(1,(int)round($decodeMeta['width']*$scale));$height=max(1,(int)round($decodeMeta['height']*$scale));
            $small=imagecreatetruecolor($width,$height);imagealphablending($small,false);imagesavealpha($small,true);imagecopyresampled($small,$image,0,0,0,0,$width,$height,$decodeMeta['width'],$decodeMeta['height']);imagedestroy($image);
            $dir=Env::storage().'/incoming-previews';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new HttpError(503,'ذخیره پیش‌نمایش ممکن نیست.');
            $preview='incoming-previews/'.bin2hex(random_bytes(24)).'.webp';$files[]=$preview;if(!imagewebp($small,Env::storage().'/'.$preview,82))throw new HttpError(503,'ذخیره پیش‌نمایش ممکن نیست.');imagedestroy($small);chmod(Env::storage().'/'.$preview,0600);$stored=$meta['bytes']+filesize(Env::storage().'/'.$preview);
            if($usage['used']+$stored>$usage['limit'])throw new HttpError(413,'فضای دریافت حساب برای اصل و پیش‌نمایش این فایل کافی نیست.');
            if($total+$stored>$global)throw new HttpError(503,'فضای دریافت سامانه برای اصل و پیش‌نمایش این فایل کافی نیست.');
            $id=Db::insert($db,'incoming_assets',['owner_id'=>$owner,'original_path'=>$saved['path'],'preview_path'=>$preview,'mime'=>$meta['mime'],'sha256'=>$meta['sha256'],'width'=>$meta['width'],'height'=>$meta['height'],'bytes'=>$meta['bytes'],'stored_bytes'=>$stored,'created_at'=>date('Y-m-d H:i:s')]);
            return ['id'=>$id,'owner_id'=>$owner,'original_path'=>$saved['path'],'preview_path'=>$preview,'stored_bytes'=>$stored]+$meta;
        }catch(\Throwable $e){self::discardPaths($files);throw $e;}
    }
    public static function discardPaths(array $paths):void {
        foreach($paths as $path){try{$file=Upload::absolute($path);unlink($file);}catch(HttpError){}}
    }
    public static function discard(array $assets):void {foreach($assets as $a)self::discardPaths([$a['original_path'],$a['preview_path']]);}
    public static function file(PDO $db,int $id,array $actor):array {
        $asset=Db::query($db,'SELECT * FROM incoming_assets WHERE id=?',[$id])->fetch();if(!$asset)throw new HttpError(404,'فایل پیدا نشد.');
        if((int)$asset['owner_id']===(int)$actor['id'])return $asset;
        if(Permissions::allows($db,$actor,'submissions.view')&&Db::query($db,'SELECT submission_id FROM submission_assets WHERE asset_id=?',[$id])->fetchColumn())return $asset;
        if(Permissions::allows($db,$actor,'contributions.view')&&Db::query($db,'SELECT c.contribution_id FROM contribution_file_assets m JOIN contribution_files c ON c.id=m.file_id WHERE m.asset_id=?',[$id])->fetchColumn())return $asset;
        throw new HttpError(404,'فایل پیدا نشد.');
    }
    public static function verify(array $asset):array {
        $path=Upload::absolute($asset['original_path']);$meta=Upload::validate($path);
        if(!hash_equals($asset['sha256'],$meta['sha256']))throw new HttpError(409,'اصل فایل با نسخه ثبت‌شده یکسان نیست؛ یکپارچگی فایل را بررسی کنید.');
        return $meta+['absolute_path'=>$path];
    }
    public static function promote(PDO $db,array $asset,int $person,string $title,int $type,array &$createdPaths,?string $verifiedPreview=null):int {
        $meta=self::verify($asset);$original=$meta['absolute_path'];
        // A public uploader's companion is only a private review aid. Publication uses
        // the actual original or a new companion generated by the authenticated reviewer.
        if($verifiedPreview)Upload::companion($meta,$verifiedPreview);else try{Upload::assertDecodeMemory($meta);}catch(HttpError){throw new HttpError(422,'برای انتشار این اصل بزرگ، از فرم بررسی استفاده کنید تا پیش‌نمایش تأییدشده از اصل فایل دوباره ساخته شود.');}
        $old=Db::query($db,'SELECT id FROM images WHERE sha256=?',[$asset['sha256']])->fetchColumn();
        $id=Upload::attach($db,$person,Upload::absolute($asset['original_path']),$title,['title'=>$title,'media_type_id'=>$type,'license_text'=>''],$verifiedPreview);
        if(!$old){$row=Db::query($db,'SELECT original_path,preview_path,thumb_path FROM images WHERE id=?',[$id])->fetch();foreach($row as $path)$createdPaths[]=$path;}
        return $id;
    }
}

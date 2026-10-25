<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Upload {
    public static function validate(string $path): array {
        $size=filesize($path);
        if(!$size||$size>(int)Env::get('MAX_UPLOAD_MB','20')*1048576) throw new HttpError(422,'حجم فایل '.fa(round((float)$size/1048576,1)).' مگابایت است؛ حد مجاز هر فایل '.fa(Env::get('MAX_UPLOAD_MB','20')).' مگابایت است.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path); $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $info=@getimagesize($path);
        if(!isset($map[$mime]))throw new HttpError(422,'نوع واقعی فایل «'.$mime.'» است؛ فقط JPEG، PNG و WebP پذیرفته می‌شوند. تغییر پسوند فایل، فرمت آن را عوض نمی‌کند.');
        if(!$info||($info['mime']??'')!==$mime||$info[0]<1||$info[1]<1)throw new HttpError(422,'ساختار تصویر معتبر نیست یا فایل ناقص است؛ نسخه اصلی تصویر را انتخاب کنید.');
        $pixels=$info[0]*$info[1];$maxPixels=max(1,(int)Env::get('MAX_IMAGE_MEGAPIXELS','200'))*1000000;
        if($info[0]>40000||$info[1]>40000||$pixels>$maxPixels)throw new HttpError(422,'ابعاد فایل '.fa($info[0]).' × '.fa($info[1]).' پیکسل ('.fa(round($pixels/1000000,1)).' مگاپیکسل) است؛ حد ایمنی فعلی '.fa($maxPixels/1000000).' مگاپیکسل و هر ضلع حداکثر ۴۰۰۰۰ پیکسل است.');
        // Reject obvious script payloads even if an image decoder accepts a polyglot.
        $stream=fopen($path,'rb'); $head=fread($stream,min((int)$size,1048576)); fclose($stream);
        if(preg_match('/<\?(?:php|=)|<script\b/i',$head)) throw new HttpError(422,'محتوای فایل مجاز نیست.');
        return ['mime'=>$mime,'extension'=>$map[$mime],'width'=>$info[0],'height'=>$info[1],'bytes'=>$size,'sha256'=>hash_file('sha256',$path)];
    }
    public static function receive(array $file): string {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')) throw new HttpError(422,'بارگذاری انجام نشد؛ فایل و محدودیت هاست را بررسی کنید.');
        return $file['tmp_name'];
    }
    public static function storePrivate(string $source,string $prefix='private'): array {
        $meta=self::validate($source); $dir=Env::storage().'/'.$prefix;
        if(!is_dir($dir)) mkdir($dir,0700,true);
        $relative=$prefix.'/'.bin2hex(random_bytes(24)).'.'.$meta['extension'];
        if(!copy($source,Env::storage().'/'.$relative)){if(is_file(Env::storage().'/'.$relative))unlink(Env::storage().'/'.$relative);throw new \RuntimeException('Cannot store upload');}
        chmod(Env::storage().'/'.$relative,0600); return $meta+['path'=>$relative];
    }
    public static function image(PDO $db,string $source,string $filename,array $in=[],?string $clientPreview=null,?int $personId=null,?int $sequence=null): int {
        if($personId===null){
            if(!preg_match('/^([A-Za-z0-9_]+)-([1-9][0-9]{0,5})\.(jpg|jpeg|png|webp)$/iD',basename($filename),$match))throw new HttpError(422,'نام فایل باید مانند 001254-1.jpg باشد.');
            $m=Db::query($db,'SELECT id FROM martyrs WHERE code=?',[$match[1]])->fetchColumn();
        }else{
            $m=Db::query($db,'SELECT id FROM martyrs WHERE id=?',[$personId])->fetchColumn();
            if(!$sequence||$sequence<1||$sequence>999999)throw new HttpError(422,'شماره رسانه معتبر نیست.');
            $match=[0=>basename($filename),2=>$sequence];
        }
        if(!$m)throw new HttpError(422,'کد شهید در بانک اطلاعاتی وجود ندارد.');
        $meta=self::validate($source);
        $same=Db::query($db,'SELECT id FROM images WHERE sha256=?',[$meta['sha256']])->fetchColumn();
        if($same){if(PlatformSchema::ready($db)&&!Db::query($db,'SELECT image_id FROM media_martyrs WHERE image_id=? AND martyr_id=?',[$same,$m])->fetchColumn())throw new HttpError(409,'این فایل قبلاً برای پرونده دیگری ثبت شده است؛ از مدیریت رسانه، ارتباط شهید را با بررسی اضافه کنید.');return (int)$same;}
        if(Db::query($db,'SELECT id FROM images WHERE martyr_id=? AND sequence_no=?',[$m,(int)$match[2]])->fetch()) throw new HttpError(409,'این شماره تصویر قبلاً ثبت شده است.');
        $decodeSource=$source;$decodeMeta=$meta;
        if($clientPreview!==null){
            $decodeMeta=self::companion($meta,$clientPreview);
            $decodeSource=$clientPreview;
        }
        self::assertDecodeMemory($decodeMeta);
        $saved=self::storePrivate($source,'originals'); $files=[$saved['path']];
        try {
            $img=match($decodeMeta['mime']){'image/jpeg'=>@imagecreatefromjpeg($decodeSource),'image/png'=>@imagecreatefrompng($decodeSource),'image/webp'=>@imagecreatefromwebp($decodeSource)};
            if(!$img) throw new HttpError(422,'تصویر قابل پردازش نیست.');
            $paths=[];
            foreach(['preview'=>1200,'thumb'=>550] as $kind=>$width){
                $scale=min(1,$width/max($decodeMeta['width'],$decodeMeta['height']));$w=max(1,(int)round($decodeMeta['width']*$scale));$h=max(1,(int)round($decodeMeta['height']*$scale));
                $canvas=imagecreatetruecolor($w,$h); imagealphablending($canvas,false); imagesavealpha($canvas,true);
                imagecopyresampled($canvas,$img,0,0,0,0,$w,$h,$decodeMeta['width'],$decodeMeta['height']);
                $relative='previews/'.bin2hex(random_bytes(24)).'.webp'; $dir=Env::storage().'/previews'; if(!is_dir($dir)) mkdir($dir,0700,true);
                $files[]=$relative;
                if(!imagewebp($canvas,Env::storage().'/'.$relative,80)) throw new \RuntimeException('Preview generation failed');
                imagedestroy($canvas); $paths[$kind]=$relative;
            }
            imagedestroy($img);
            return Db::transaction($db,function()use($db,$m,$match,$meta,$saved,$paths,$in){
                $kind=Security::text($in,'kind',20,false)?:'design'; if(!in_array($kind,['design','photo','document'],true)) throw new HttpError(422,'نوع تصویر نامعتبر است.');
                $id=Db::insert($db,'images',['martyr_id'=>(int)$m,'sequence_no'=>(int)$match[2],'title'=>Security::text($in,'title',255,false)?:basename($match[0]),'designer'=>Security::text($in,'designer',150,false),'license_text'=>Security::text($in,'license_text',2000,false),'kind'=>$kind,'original_path'=>$saved['path'],'preview_path'=>$paths['preview'],'thumb_path'=>$paths['thumb'],'sha256'=>$meta['sha256'],'mime'=>$meta['mime'],'width'=>$meta['width'],'height'=>$meta['height'],'bytes'=>$meta['bytes'],'created_at'=>date('Y-m-d H:i:s')]);
                Media::initialize($db,$id,(int)$m,$kind,(int)($in['media_type_id']??0));Db::audit($db,'image.upload',(string)$id); return $id;
            });
        } catch(\Throwable $e){ foreach($files as $f) if(is_file(Env::storage().'/'.$f)) unlink(Env::storage().'/'.$f); throw $e; }
    }
    public static function companion(array $meta,string $path):array {
        $preview=self::validate($path);
        if($preview['bytes']>8388608||max($preview['width'],$preview['height'])>1600)throw new HttpError(422,'پیش‌نمایش همراه باید حداکثر ۱۶۰۰ پیکسل و ۸ مگابایت باشد.');
        $matches=function(int $width,int $height)use($preview):bool{$scale=min($preview['width']/$width,$preview['height']/$height);return abs($preview['width']-max(1,round($width*$scale)))<=2&&abs($preview['height']-max(1,round($height*$scale)))<=2;};
        if(!$matches($meta['width'],$meta['height'])&&!($meta['mime']==='image/jpeg'&&$matches($meta['height'],$meta['width'])))throw new HttpError(422,'نسبت ابعاد پیش‌نمایش با فایل اصلی سازگار نیست؛ دوباره فایل را از فرم انتخاب کنید.');
        return $preview;
    }
    public static function attach(PDO $db,int $person,string $source,string $filename,array $in=[],?string $companion=null):int {
        return Db::transaction($db,function()use($db,$person,$source,$filename,$in,$companion){
            $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            if(!Db::query($db,'SELECT id FROM martyrs WHERE id=?'.$lock,[$person])->fetchColumn())throw new HttpError(404,'پرونده شهید پیدا نشد.');
            $next=(int)Db::query($db,'SELECT COALESCE(MAX(sequence_no),0)+1 FROM images WHERE martyr_id=?',[$person])->fetchColumn();
            return self::image($db,$source,$filename,$in,$companion,$person,$next);
        });
    }
    public static function assertDecodeMemory(array $meta):void {
        $raw=trim((string)ini_get('memory_limit'));$limit=-1;
        if($raw!=='-1'){$unit=strtolower(substr($raw,-1));$limit=(int)$raw*match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};}
        $budget=$limit>0?$limit:536870912;$required=$meta['width']*$meta['height']*8+16777216;
        if(memory_get_usage(true)+$required>$budget)throw new HttpError(422,'ابعاد تصویر برای پردازش مستقیم با حافظه فعلی هاست بزرگ است. صفحه را تازه کنید و از فرم مرورگر ارسال کنید تا پیش‌نمایش سبک همراه اصل فایل ارسال شود؛ کیفیت اصل فایل تغییر نمی‌کند.');
    }
    public static function absolute(string $relative): string {
        $base=realpath(Env::storage()); $file=realpath($base.'/'.$relative);
        if(!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)) throw new HttpError(404,'فایل پیدا نشد.'); return $file;
    }
}

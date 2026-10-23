<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Upload {
    public static function validate(string $path): array {
        $size=filesize($path);
        if(!$size||$size>(int)Env::get('MAX_UPLOAD_MB','20')*1048576) throw new HttpError(422,'حجم فایل مجاز نیست.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path); $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $info=@getimagesize($path);
        if(!isset($map[$mime])||!$info||$info[0]>12000||$info[1]>12000||$info[0]*$info[1]>24000000) throw new HttpError(422,'فقط JPEG، PNG یا WebP معتبر تا ۲۴ مگاپیکسل مجاز است.');
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
        if(!copy($source,Env::storage().'/'.$relative)) throw new \RuntimeException('Cannot store upload');
        chmod(Env::storage().'/'.$relative,0600); return $meta+['path'=>$relative];
    }
    public static function image(PDO $db,string $source,string $filename,array $in=[]): int {
        if(!preg_match('/^([A-Za-z0-9_]+)-([1-9][0-9]{0,5})\.(jpg|jpeg|png|webp)$/iD',basename($filename),$match)) throw new HttpError(422,'نام فایل باید مانند 001254-1.jpg باشد.');
        $m=Db::query($db,'SELECT id FROM martyrs WHERE code=?',[$match[1]])->fetchColumn();
        if(!$m) throw new HttpError(422,'کد شهید در بانک اطلاعاتی وجود ندارد.');
        $meta=self::validate($source);
        $same=Db::query($db,'SELECT id FROM images WHERE sha256=?',[$meta['sha256']])->fetchColumn();
        if($same) return (int)$same;
        if(Db::query($db,'SELECT id FROM images WHERE martyr_id=? AND sequence_no=?',[$m,(int)$match[2]])->fetch()) throw new HttpError(409,'این شماره تصویر قبلاً ثبت شده است.');
        $saved=self::storePrivate($source,'originals'); $files=[$saved['path']];
        try {
            $img=match($meta['mime']){'image/jpeg'=>@imagecreatefromjpeg($source),'image/png'=>@imagecreatefrompng($source),'image/webp'=>@imagecreatefromwebp($source)};
            if(!$img) throw new HttpError(422,'تصویر قابل پردازش نیست.');
            $paths=[];
            foreach(['preview'=>1200,'thumb'=>550] as $kind=>$width){
                $w=min($width,$meta['width']); $h=max(1,(int)round($w*$meta['height']/$meta['width']));
                $canvas=imagecreatetruecolor($w,$h); imagealphablending($canvas,false); imagesavealpha($canvas,true);
                imagecopyresampled($canvas,$img,0,0,0,0,$w,$h,$meta['width'],$meta['height']);
                $relative='previews/'.bin2hex(random_bytes(24)).'.webp'; $dir=Env::storage().'/previews'; if(!is_dir($dir)) mkdir($dir,0700,true);
                if(!imagewebp($canvas,Env::storage().'/'.$relative,80)) throw new \RuntimeException('Preview generation failed');
                imagedestroy($canvas); $paths[$kind]=$relative; $files[]=$relative;
            }
            imagedestroy($img);
            return Db::transaction($db,function()use($db,$m,$match,$meta,$saved,$paths,$in){
                $kind=Security::text($in,'kind',20,false)?:'design'; if(!in_array($kind,['design','photo','document'],true)) throw new HttpError(422,'نوع تصویر نامعتبر است.');
                $id=Db::insert($db,'images',['martyr_id'=>(int)$m,'sequence_no'=>(int)$match[2],'title'=>Security::text($in,'title',255,false)?:basename($match[0]),'designer'=>Security::text($in,'designer',150,false),'license_text'=>Security::text($in,'license_text',2000,false),'kind'=>$kind,'original_path'=>$saved['path'],'preview_path'=>$paths['preview'],'thumb_path'=>$paths['thumb'],'sha256'=>$meta['sha256'],'mime'=>$meta['mime'],'width'=>$meta['width'],'height'=>$meta['height'],'bytes'=>$meta['bytes'],'created_at'=>date('Y-m-d H:i:s')]);
                Db::audit($db,'image.upload',(string)$id); return $id;
            });
        } catch(\Throwable $e){ foreach($files as $f) if(is_file(Env::storage().'/'.$f)) unlink(Env::storage().'/'.$f); throw $e; }
    }
    public static function absolute(string $relative): string {
        $base=realpath(Env::storage()); $file=realpath($base.'/'.$relative);
        if(!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)) throw new HttpError(404,'فایل پیدا نشد.'); return $file;
    }
}

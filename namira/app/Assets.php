<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Assets {
    public static function put(PDO $db,string $source,int $actor,int $edge=800):array {
        $meta=Upload::validate($source);if($meta['width']*$meta['height']>4000000)throw new HttpError(422,'برای تصویر پروفایل، پیش‌نمایش کم‌حجم حداکثر ۴ مگاپیکسلی ارسال کنید.');Upload::assertDecodeMemory($meta);
        $image=match($meta['mime']){'image/jpeg'=>@imagecreatefromjpeg($source),'image/png'=>@imagecreatefrompng($source),'image/webp'=>@imagecreatefromwebp($source)};
        if(!$image)throw new HttpError(422,'تصویر پروفایل قابل خواندن نیست.');
        $ratio=min(1,$edge/max($meta['width'],$meta['height']));$width=max(1,(int)round($meta['width']*$ratio));$height=max(1,(int)round($meta['height']*$ratio));$canvas=imagecreatetruecolor($width,$height);imagealphablending($canvas,false);imagesavealpha($canvas,true);imagecopyresampled($canvas,$image,0,0,0,0,$width,$height,$meta['width'],$meta['height']);unset($image);
        $dir=Env::storage().'/assets';if(!is_dir($dir))mkdir($dir,0700,true);$path='assets/'.bin2hex(random_bytes(24)).'.webp';$absolute=Env::storage().'/'.$path;
        try{if(!imagewebp($canvas,$absolute,84))throw new HttpError(503,'ذخیره تصویر نمایشی انجام نشد.');unset($canvas);chmod($absolute,0600);
            $id=Db::insert($db,'file_assets',['path'=>$path,'mime'=>'image/webp','bytes'=>filesize($absolute),'width'=>$width,'height'=>$height,'sha256'=>hash_file('sha256',$absolute),'created_by'=>$actor,'created_at'=>date('Y-m-d H:i:s')]);return ['id'=>$id,'path'=>$path];
        }catch(\Throwable $e){if(is_file($absolute))unlink($absolute);throw $e;}
    }
}

<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Archive,Upload,HttpError};
$root=BASE_PATH.'/storage/large-test-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
putenv('APP_ENV=testing');putenv('STORAGE_PATH='.$root);putenv('DB_DSN=sqlite:'.$root.'/large.sqlite');putenv('DB_USER=');putenv('DB_PASSWORD=');
$db=Db::connect();(require BASE_PATH.'/database/migrate.php')($db);$a=new Archive($db);$id=$a->save(['code'=>'009001','first_name'=>'آزمون','last_name'=>'تصویر بزرگ','published'=>'1']);$checks=0;
function ok(bool $yes,string $label):void{global $checks;if(!$yes)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function denied(callable $call,string $part,string $label):void{try{$call();}catch(HttpError $e){ok($e->status===422&&str_contains($e->getMessage(),$part),$label);return;}throw new RuntimeException('FAIL expected rejection '.$label);}
try{
    // The 48 MP original exceeds the previous 24 MP limit. Generate only in this disposable fixture.
    ini_set('memory_limit','512M');
    $original=$root.'/48mp.jpg';$large=imagecreatetruecolor(8000,6000);imagefill($large,0,0,imagecolorallocate($large,28,101,88));imagejpeg($large,$original,95);unset($large);
    $companion=$root.'/companion.webp';$small=imagecreatetruecolor(1600,1200);imagefill($small,0,0,imagecolorallocate($small,28,101,88));imagewebp($small,$companion,84);unset($small);
    $savedLimit=ini_get('memory_limit');ini_set('memory_limit','128M');
    $meta=Upload::validate($original);ok($meta['width']===8000&&$meta['height']===6000,'48 MP original passes header validation');
    $uploaded=Upload::image($db,$original,'009001-1.jpg',[],$companion);$stored=$a->image($uploaded);
    ok(hash_file('sha256',Upload::absolute($stored['original_path']))===hash_file('sha256',$original),'48 MP original bytes are preserved under 128 MB PHP limit');
    $info=getimagesize(Upload::absolute($stored['preview_path']));ok(max($info[0],$info[1])===1200&&$info['mime']==='image/webp','server only decodes bounded companion into WebP preview');
    $info=getimagesize(Upload::absolute($stored['thumb_path']));ok(max($info[0],$info[1])===550,'thumbnail is bounded on both dimensions');
    ok(filesize(Upload::absolute($stored['preview_path']))<filesize($original),'preview file is lighter than high-quality original');
    denied(fn()=>Upload::assertDecodeMemory($meta),'پیش‌نمایش','missing companion is rejected before out-of-memory decoding');
    $bad=$root.'/wrong-ratio.png';$im=imagecreatetruecolor(100,100);imagepng($im,$bad);unset($im);
    // Different original hash ensures the idempotency branch cannot skip companion validation.
    $original2=$root.'/48mp-copy.jpg';file_put_contents($original2,file_get_contents($original)."\n");
    denied(fn()=>Upload::image($db,$original2,'009001-2.jpg',[],$bad),'نسبت','mismatched companion aspect ratio rejected');
    $wide=$root.'/wide.png';$im=imagecreatetruecolor(2000,1500);imagepng($im,$wide);unset($im);
    denied(fn()=>Upload::image($db,$original2,'009001-2.jpg',[],$wide),'۱۶۰۰','oversized companion rejected before decode');
    $disguised=$root.'/fake.jpg';file_put_contents($disguised,'This is not a JPEG');
    denied(fn()=>Upload::validate($disguised),'نوع واقعی','wrong actual format receives a precise diagnostic');
    putenv('MAX_IMAGE_MEGAPIXELS=24');denied(fn()=>Upload::validate($original),'۸۰۰۰','dimension rejection reports actual dimensions');putenv('MAX_IMAGE_MEGAPIXELS=200');
    putenv('MAX_UPLOAD_MB=0');denied(fn()=>Upload::validate($original),'مگابایت','byte-size rejection is distinct from dimensions');putenv('MAX_UPLOAD_MB=20');
    ok((int)Db::query($db,'SELECT COUNT(*) FROM images')->fetchColumn()===1,'failed companions create no image records');
    ok(count(glob($root.'/originals/*'))===1,'failed companions leave no extra original files');
    ini_set('memory_limit',(string)$savedLimit);
    echo "\n$checks large-image checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

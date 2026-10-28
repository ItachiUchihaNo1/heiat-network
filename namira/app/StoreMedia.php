<?php
 declare(strict_types=1);
 namespace Namira;
 use PDO;
 final class StoreMedia {
 public static function put(PDO $db,array $actor,int $product,string $source,string $alt,int $sort=0):int {
 if(!Permissions::allows($db,$actor,'shop.manage'))throw new HttpError(403,'مجوز مدیریت فروشگاه لازم است.');
 if(!Db::query($db,'SELECT id FROM products WHERE id=?',[$product])->fetchColumn())throw new HttpError(404,'محصول پیدا نشد.');
 $meta=Upload::validate($source);Upload::assertDecodeMemory($meta);$saved=Upload::storePrivate($source,'store-catalog');$preview='store-catalog/'.bin2hex(random_bytes(24)).'.webp';
 try{$im=match($meta['mime']){'image/jpeg'=>@imagecreatefromjpeg($source),'image/png'=>@imagecreatefrompng($source),'image/webp'=>@imagecreatefromwebp($source)};if(!$im)throw new HttpError(422,'تصویر محصول قابل خواندن نیست.');$ratio=min(1,1200/max($meta['width'],$meta['height']));$w=max(1,(int)round($meta['width']*$ratio));$h=max(1,(int)round($meta['height']*$ratio));$out=imagecreatetruecolor($w,$h);imagealphablending($out,false);imagesavealpha($out,true);imagecopyresampled($out,$im,0,0,0,0,$w,$h,$meta['width'],$meta['height']);if(!imagewebp($out,Env::storage().'/'.$preview,82))throw new HttpError(503,'ساخت پیش‌نمایش انجام نشد.');unset($im,$out);chmod(Env::storage().'/'.$preview,0600);
 return Db::transaction($db,function()use($db,$actor,$product,$alt,$sort,$saved,$preview){$id=Db::insert($db,'store_media',['product_id'=>$product,'path'=>$saved['path'],'preview_path'=>$preview,'mime'=>$saved['mime'],'bytes'=>$saved['bytes'],'sha256'=>$saved['sha256'],'alt'=>mb_substr($alt,0,255),'sort_order'=>$sort,'created_at'=>date(DATE_ATOM)]);Db::audit($db,'store.media.create',(string)$id);return $id;});
 }catch(\Throwable $e){foreach([$saved['path'],$preview] as $f)if(is_file(Env::storage().'/'.$f))unlink(Env::storage().'/'.$f);throw $e;}
 }
 public static function get(PDO $db,int $id,array|false $actor):array {
 $row=Db::query($db,'SELECT m.*,p.published,COALESCE(d.archived,0) AS archived FROM store_media m JOIN products p ON p.id=m.product_id LEFT JOIN store_product_details d ON d.product_id=p.id WHERE m.id=?',[$id])->fetch();
 if(!$row||((!$row['published']||$row['archived'])&&!Permissions::allows($db,$actor,'shop.manage')))throw new HttpError(404,'تصویر محصول پیدا نشد.');return $row;
 }
 }

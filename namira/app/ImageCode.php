<?php
declare(strict_types=1);
namespace Namira;
/** The database allocates IDs atomically; formatting never renumbers existing media. */
final class ImageCode {
 public static function downloadName(array $image):string {
  $origin=Env::get('PUBLIC_APP_URL')?:Env::get('APP_URL','https://naamiraa.ir');$host=strtolower((string)parse_url($origin,PHP_URL_HOST));if(!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D',$host))$host='naamiraa.ir';
  $id=(int)($image['martyr_id']??0);if($id<1)throw new \InvalidArgumentException('Invalid martyr ID');$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$image['mime']??'']??null;if(!$ext)throw new \InvalidArgumentException('Unsupported original image');return $host.'-'.$id.'.'.$ext;
 }
 public static function format(int $id):string {if($id<1)throw new \InvalidArgumentException('Invalid image ID');return str_pad((string)$id,6,'0',STR_PAD_LEFT);}
}

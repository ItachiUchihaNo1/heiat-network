<?php
declare(strict_types=1);
namespace Namira;
/** Locate container boundaries; binary pixels and embedded metadata are not source code. */
final class ImagePayload {
 private static function invalid():never {throw new HttpError(422,'ساختار فایل تصویر کامل نیست یا بخش‌هایی از آن ناقص است.');}
 private static function read($stream,int $bytes):string {$data=fread($stream,$bytes);if($data===false||strlen($data)!==$bytes)self::invalid();return $data;}
 private static function skip($stream,int $bytes,int $size):void {if($bytes<0||$bytes>$size-ftell($stream)||fseek($stream,$bytes,SEEK_CUR)!==0)self::invalid();}
 /** Stop at an actual JPEG marker, respecting stuffed FF bytes and restart markers. */
 private static function scan($stream,int $size):void {
  while(ftell($stream)<$size){$start=ftell($stream);$chunk=fread($stream,65536);if($chunk===false||$chunk==='')self::invalid();$length=strlen($chunk);$offset=0;
   while(($at=strpos($chunk,"\xff",$offset))!==false){
    if($at+1===$length){if($start+$length===$size)self::invalid();fseek($stream,$start+$at);continue 2;}
    $code=ord($chunk[$at+1]);if($code===0||$code===1||($code>=0xd0&&$code<=0xd7)){$offset=$at+2;continue;}if($code===0xff){$offset=$at+1;continue;}
    if(fseek($stream,$start+$at)!==0)self::invalid();return;
   }
  }self::invalid();
 }
 private static function jpeg($stream,int $size):int {
  if(self::read($stream,2)!=="\xff\xd8")self::invalid();$scan=false;
  while(ftell($stream)<$size){if($scan)self::scan($stream,$size);if(self::read($stream,1)!=="\xff")self::invalid();do{$code=ord(self::read($stream,1));}while($code===0xff);
   if($code===0xd9)return ftell($stream);if($code===0||$code===0xd8)self::invalid();if($code===1||($code>=0xd0&&$code<=0xd7))continue;
   $length=unpack('n',self::read($stream,2))[1];if($length<2)self::invalid();self::skip($stream,$length-2,$size);$scan=$code===0xda||($scan&&$code===0xdc);
  }self::invalid();
 }
 private static function png($stream,int $size):int {
  if(self::read($stream,8)!=="\x89PNG\r\n\x1a\n")self::invalid();
  while(ftell($stream)<$size){$length=unpack('N',self::read($stream,4))[1];$type=self::read($stream,4);self::skip($stream,$length,$size);self::read($stream,4);if($type==='IEND'){if($length!==0)self::invalid();return ftell($stream);}}
  self::invalid();
 }
 private static function webp($stream,int $size):int {
  if(self::read($stream,4)!=='RIFF')self::invalid();$end=8+unpack('V',self::read($stream,4))[1];if(self::read($stream,4)!=='WEBP'||$end<12||$end>$size)self::invalid();return $end;
 }
 public static function check(string $path,string $mime,int $size):void {
  $stream=fopen($path,'rb');if(!$stream)throw new HttpError(503,'خواندن فایل تصویر انجام نشد.');
  try{$end=match($mime){'image/jpeg'=>self::jpeg($stream,$size),'image/png'=>self::png($stream,$size),'image/webp'=>self::webp($stream,$size),default=>throw new \LogicException('Unsupported image container')};
   if(fseek($stream,$end)!==0)self::invalid();$carry='';while(!feof($stream)){$chunk=fread($stream,65536);if($chunk===false)self::invalid();if($chunk==='')break;$text=$carry.$chunk;if(preg_match('/<\?(?:php|=)|<script\b/i',$text))throw new HttpError(422,'محتوای اضافه پس از پایان تصویر مجاز نیست.');$carry=substr($text,-16);}
  }finally{fclose($stream);}
 }
}

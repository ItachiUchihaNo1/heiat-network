<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class HomeShowcaseWeb {
 public static function json(array $value,int $status=200):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP);exit;}
 public static function lookup(PDO $db):never {
  Permissions::require($db,'settings.manage');$type=Security::text($_GET,'type',30);if(!isset(HomeShowcase::TYPES[$type])||in_array($type,['banner','request'],true))throw new HttpError(422,'نوع محتوا معتبر نیست.');
  $q=Security::text($_GET,'q',150,false);$rows=HomeShowcase::content($db,$type,[],24,$q);self::json(['ok'=>true,'items'=>array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>self::label($type,$r)],$rows)]);
 }
 public static function label(string $type,array $row):string {return $type==='images'?PublicPresentation::imageTitle($row):($row['display_name']??$row['title']??trim(($row['first_name']??'').' '.($row['last_name']??'')));}
 public static function show(PDO $db,array $actor):void {
  Permissions::require($db,'settings.manage');$state=HomeShowcase::state($db);$labels=[];
  foreach($state['draft'] as $b)if($b['ids'])foreach(HomeShowcase::content($db,$b['type'],$b['ids'],24) as $item)$labels[$b['id']][$item['id']]=self::label($b['type'],$item);
  view('admin-home-showcase',compact('state','labels','db','actor'));
 }
 public static function save(PDO $db,array $actor):never {
  try{$uploads=[];foreach($_FILES as $key=>$file){if(!preg_match('/^banner_[A-Za-z0-9_-]+_(desktop|mobile)$/D',$key))continue;if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;$uploads[$key]=Upload::receive($file);}
   $state=HomeShowcase::save($db,$actor,$_POST,$uploads);self::json(['ok'=>true,'version'=>$state['version'],'published_at'=>$state['published_at'],'message'=>($_POST['intent']??'')==='publish'?'ویترین ذخیره و منتشر شد.':'پیش‌نویس ذخیره شد؛ صفحه عمومی تغییر نکرد.','draft'=>$state['draft']]);
  }catch(HttpError $e){self::json(['ok'=>false,'message'=>$e->getMessage()],$e->status);}
 }
}

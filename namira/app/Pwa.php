<?php
declare(strict_types=1);
namespace Namira;
final class Pwa {
 public static function manifest():array {return ['id'=>'/','name'=>'نامیرا، سامانه جامع تصاویر شهدا','short_name'=>'نامیرا','description'=>'تصاویر شهدا و آثار هنرمندان نامیرا','lang'=>'fa','dir'=>'rtl','start_url'=>'/','scope'=>'/','display'=>'standalone','background_color'=>'#ffffff','theme_color'=>'#a62a3b','icons'=>[['src'=>'/assets/icons/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],['src'=>'/assets/icons/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],['src'=>'/assets/icons/icon-maskable-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable']]];}
 /** Public metadata and offline document do not start a session or access the database. */
 public static function serve():bool {
  $page=$_GET['page']??'';$favicon=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)==='/favicon.ico';
  if(!$favicon&&!in_array($page,['pwa-manifest','pwa-worker','pwa-offline'],true))return false;
  header('X-Content-Type-Options: nosniff');
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){http_response_code(405);header('Allow: GET, HEAD');return true;}
  if($favicon){header('Location: /assets/icons/icon-96.png',true,302);header('Cache-Control: public, max-age=86400');return true;}
  if(DomainRouting::surface()==='admin'){http_response_code(404);return true;}
  if($page==='pwa-worker'){header('Content-Type: application/javascript; charset=utf-8');header('Service-Worker-Allowed: /');header('Cache-Control: no-cache, max-age=0, must-revalidate');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile(BASE_PATH.'/public/assets/pwa-worker.js');return true;}
  if($page==='pwa-manifest'){header('Content-Type: application/manifest+json; charset=utf-8');header('Cache-Control: public, max-age=3600');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo json_encode(self::manifest(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return true;}
  header('Content-Type: text/html; charset=utf-8');header('Cache-Control: public, max-age=3600');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'");if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')view('pwa-offline');return true;
 }
}

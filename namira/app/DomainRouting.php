<?php
declare(strict_types=1);
namespace Namira;
/** Redirects use configured origins only; each entry point fixes its own surface. */
final class DomainRouting {
 public static function origin(string $key):string {
  $v=rtrim(Env::get($key),'/');if($v==='')return '';$p=parse_url($v);
  if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true)||!preg_match('/^[a-z0-9.-]+$/Di',$p['host']))throw new HttpError(503,'نشانی امن دامنه‌های استقرار را بررسی کنید.');
  return 'https://'.strtolower($p['host']);
 }
 public static function enabled():bool {
  $p=self::origin('PUBLIC_APP_URL');$a=self::origin('ADMIN_APP_URL');if(($p==='')!==($a==='')||($p!==''&&$p===$a))throw new HttpError(503,'دامنه سایت و پنل باید جداگانه و با هم تنظیم شوند.');return $p!=='';
 }
 public static function surface():string {return defined('NAMIRA_SURFACE')&&NAMIRA_SURFACE==='admin'?'admin':'public';}
 public static function adminPage(string $p):bool {return in_array($p,['admin','setup','home-preview','home-lookup','home-banner-preview','admin-lookup','catalog-geography','export','store-export','call-export-download','call-lookup','call-compare'],true);}
 public static function sharedPage(string $p):bool {return in_array($p,['login','store-order','order','media','asset','community-file','call-file','store-file','request-file','store-category-media','store-media'],true);}
 public static function publicAction(string $a):bool {
  return in_array($a,['login','logout','account-password','account-register','account-profile','claim-create','submission-save','contribution-save','owned-artist-save','owned-artist-submit','mobile-request','mobile-verify','mobile-password','mobile-complete','member-profile','member-address','request-message','request','cart','checkout','test-pay','return','call2-start','call2-common','call2-work','call2-upload','call2-remove-file','call2-delete-work','call2-finalize','call2-notifications-read','commerce-add','commerce-quote','commerce-quantity','commerce-checkout','commerce-pay','commerce-retry','commerce-cancel','commerce-feedback','commerce-message','commerce-return','commerce-return-file','commerce-notification-read'],true);
 }
 public static function link(string $p,array $params=[]):string {
  $path='/index.php?'.http_build_query(['page'=>$p]+$params);if(!self::enabled())return $path;
  $target=self::adminPage($p)?'admin':(self::sharedPage($p)?self::surface():'public');return $target===self::surface()?$path:self::origin($target==='admin'?'ADMIN_APP_URL':'PUBLIC_APP_URL').$path;
 }
 public static function absolute(string $p,array $params=[]):string {
  $link=self::link($p,$params);if(str_starts_with($link,'https://'))return $link;$origin=self::enabled()?self::origin(self::surface()==='admin'?'ADMIN_APP_URL':'PUBLIC_APP_URL'):rtrim(Env::get('APP_URL'),'/');return $origin.$link;
 }
 public static function destination(string $p,string $method,array $query=[],string $action=''):?string {
  if(!self::enabled())return null;
  if($method==='POST'){if(self::surface()==='public'&&!self::publicAction($action))throw new HttpError(403,'عملیات مدیریتی فقط از دامنه پنل مدیریت مجاز است.');return null;}
  $target=self::adminPage($p)?'admin':(self::sharedPage($p)?self::surface():'public');if($target===self::surface())return null;unset($query['page']);return self::link($p,$query);
 }
 public static function enforce(string $p):void {
  if(!self::enabled())return;
  if(!defined('NAMIRA_SURFACE')||!in_array(NAMIRA_SURFACE,['public','admin'],true))throw new HttpError(503,'ورودی مستقل سایت یا پنل تنظیم نشده است.');
  $expected=parse_url(self::origin(self::surface()==='admin'?'ADMIN_APP_URL':'PUBLIC_APP_URL'),PHP_URL_HOST);
  if(strtolower($_SERVER['HTTP_HOST']??'')!==$expected)throw new HttpError(421,'این نام میزبان برای این ورودی تنظیم نشده است.');
  $action=$_POST['action']??'';if(!is_string($action))throw new HttpError(422,'عملیات معتبر نیست.');$dest=self::destination($p,$_SERVER['REQUEST_METHOD']??'GET',$_GET,$action);if($dest!==null){header('Location: '.$dest,true,302);exit;}if(self::surface()==='admin')header('X-Robots-Tag: noindex, nofollow');
 }
}

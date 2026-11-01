<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CleanUrls {
 private static ?PDO $db=null;
 private static array $titles=[];
 public const SIMPLE=['home'=>'/','gallery'=>'/images','martyrs'=>'/martyrs','artists'=>'/artists','calls'=>'/calls','shop'=>'/shop','login'=>'/login','member-login'=>'/login','register'=>'/login','request'=>'/request','request-track'=>'/request-track','about'=>'/about','cart'=>'/cart','account'=>'/account','store-account'=>'/account/store','order'=>'/order','submit-art'=>'/submit-art','contribute'=>'/contribute','store-sitemap'=>'/sitemap.xml'];
 public const DETAILS=['profile'=>'martyrs','artist'=>'artists','call'=>'calls','product'=>'products','image'=>'images'];
 public static function enabled():bool{return !is_file(BASE_PATH.'/.clean-urls-pending');}
 public static function bind(PDO $db):void {self::$db=$db;self::$titles=[];}
 public static function slug(string $title):string {
  $title=Security::normalize(strip_tags($title));$s=preg_replace('/[^\p{L}\p{N}]+/u','-',mb_strtolower($title));return mb_substr(trim($s,'-'),0,100)?:'namira';
 }
 private static function title(string $page,int $id):?string {
  $key=$page.':'.$id;if(array_key_exists($key,self::$titles))return self::$titles[$key];if(!self::$db)return null;$db=self::$db;$title=null;
  try{
   if($page==='profile'){$m=(new Archive($db))->find($id);if($m)$title=($m['display_name']??'')?:trim($m['first_name'].' '.$m['last_name']);}
   elseif($page==='artist'){$a=(new Artists($db,[]))->get($id,true);if($a)$title=$a['display_name'];}
   elseif($page==='call'){$c=CallCampaigns::get($db,$id,true);$title=$c['title'];}
   elseif($page==='product'){$p=(new StoreCatalog($db))->get($id);$title=$p['title'];}
   elseif($page==='image'){$i=(new Archive($db))->image($id);if($i)$title=PublicPresentation::imageTitle($i);}
  }catch(HttpError $e){if($e->status!==404)throw $e;}
  return self::$titles[$key]=$title;
 }
 public static function publicExists(string $page,int $id):bool{return self::title($page,$id)!==null;}
 public static function path(string $page,array $params=[]):?string {
  if(!self::enabled())return null;unset($params['page']);$path=self::SIMPLE[$page]??null;
  if($page==='register')$params=['mode'=>'register']+$params;
  if(isset(self::DETAILS[$page])){
   $raw=$params['id']??0;if(!is_scalar($raw)||!ctype_digit((string)$raw)||(int)$raw<1)return null;
   $id=(int)$raw;$title=self::title($page,$id);$path='/'.self::DETAILS[$page].'/'.$id.'/'.rawurlencode(self::slug($title??($params['slug']??'namira')));unset($params['id'],$params['slug']);
  }elseif($page==='store-order'){$id=$params['id']??0;if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)return null;$path='/orders/'.(int)$id;unset($params['id']);}
  elseif($page==='store-info'){$section=$params['section']??'sales_terms';if(!is_string($section)||!preg_match('/^[a-z_]+$/D',$section))return null;$path='/help/'.rawurlencode(str_replace('_','-',$section));unset($params['section']);}
  if($path===null)return null;$query=http_build_query($params,'','&',PHP_QUERY_RFC3986);return $path.($query!==''?'?'.$query:'');
 }
 public static function resolve(string $uri,array $query):array {
  $path=parse_url($uri,PHP_URL_PATH);if(!is_string($path))throw new HttpError(404,'صفحه پیدا نشد.');
  if($path==='/index.php'||$path===''||($path==='/'&&isset($query['page'])))return $query;
  $decoded=rawurldecode($path);if(preg_match('/[\x00-\x1f\x7f\\\\]/',$decoded))throw new HttpError(404,'صفحه پیدا نشد.');
  $route=null;$params=[];$trimmed=rtrim($decoded,'/')?:'/';
  foreach(self::SIMPLE as $key=>$value)if($trimmed===$value){$route=$key;break;}
  if($trimmed==='/login')$route='login';
  if($route===null&&preg_match('~^/(martyrs|artists|calls|products|images)/([0-9]+)(?:/([^/]+))?$~uD',$trimmed,$m)){$route=array_search($m[1],self::DETAILS,true);if((int)$m[2]<1)throw new HttpError(404,'صفحه پیدا نشد.');$params['id']=(int)$m[2];}
  if($route===null&&preg_match('~^/orders/([1-9][0-9]*)$~D',$trimmed,$m)){$route='store-order';$params['id']=(int)$m[1];}
  if($route===null&&preg_match('~^/help/([a-z_-]+)$~D',$trimmed,$m)){$route='store-info';$params['section']=str_replace('-','_',$m[1]);}
  if($route===null)throw new HttpError(404,'صفحه پیدا نشد.');return array_replace($query,['page'=>$route],$params);
 }
 public static function canonical(string $page,array $query,string $uri,string $method):?string {
  if(!self::enabled())return null;if(!in_array($method,['GET','HEAD'],true)||DomainRouting::surface()==='admin')return null;
  if(isset(self::DETAILS[$page])){if(!self::title($page,(int)($query['id']??0)))throw new HttpError(404,'صفحه پیدا نشد.');}
  $target=self::path($page,$query);if($target===null)return null;
  // URI comparison tolerates percent-encoding case but canonicalizes aliases, trailing slashes and old slugs.
  $currentPath=parse_url($uri,PHP_URL_PATH)?:'/';$currentQuery=[];parse_str(parse_url($uri,PHP_URL_QUERY)??'',$currentQuery);
  if(rawurldecode($currentPath)!==rawurldecode(parse_url($target,PHP_URL_PATH)?:'/'))return $target;
  $expected=[];parse_str(parse_url($target,PHP_URL_QUERY)??'',$expected);return $currentQuery===$expected?null:$target;
 }
 private static function legacy(string $value):?string {
  $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');$u=parse_url($value);if(!$u||($u['path']??'')!=='/index.php')return null;
  if(isset($u['host'])&&strtolower($u['host'])!==strtolower(parse_url(Env::get('PUBLIC_APP_URL',Env::get('APP_URL')),PHP_URL_HOST)??''))return null;
  parse_str($u['query']??'',$params);$page=$params['page']??'';if(!is_string($page))return null;$path=self::path($page,$params);return $path===null?null:$path.(isset($u['fragment'])?'#'.$u['fragment']:'');
 }
 public static function html(string $html):string {
  if(!self::enabled())return $html;
  // Only owned rendered GET forms are normalized. POST bodies, endpoints, APIs and downloads stay intact.
  $html=preg_replace_callback('~<form\b[^>]*>.*?</form>~si',function($m){$form=$m[0];if(!preg_match('~^<form\b[^>]*\bmethod=["\']get["\']~i',$form))return $form;
   if(!preg_match('~<input\b(?=[^>]*\bname=["\']page["\'])(?=[^>]*\bvalue=["\']([^"\']+)["\'])[^>]*>~i',$form,$field))return $form;
   $path=self::path($field[1]);if($path===null||isset(self::DETAILS[$field[1]]))return $form;
   $form=str_replace($field[0],'',$form);return preg_replace_callback('~^<form\b[^>]*>~i',fn($head)=>preg_match('~\baction=["\'][^"\']*["\']~i',$head[0])?preg_replace('~\baction=["\'][^"\']*["\']~i','action="'.e($path).'"',$head[0]):substr($head[0],0,-1).' action="'.e($path).'">',$form,1);
  },$html);
  return preg_replace_callback('~\bhref=(["\'])(.*?)\1~si',function($m){$clean=self::legacy($m[2]);return $clean===null?$m[0]:'href='.$m[1].e($clean).$m[1];},$html);
 }
 public static function sitemap(PDO $db):string {
  $parts=['martyrs'=>['profile','SELECT id FROM martyrs WHERE published=1'],'artists'=>['artist','SELECT id FROM artists WHERE public_visible=1 AND archived=0'],'calls'=>['call',"SELECT id FROM art_calls WHERE status='published'"],'products'=>['product','SELECT p.id FROM products p LEFT JOIN store_product_details d ON d.product_id=p.id WHERE p.published=1 AND COALESCE(d.archived,0)=0'],'images'=>['image','SELECT id FROM images WHERE published=1']];
  $part=$_GET['part']??'';$urls=[];$index=$part==='';$size=200;
  if($index){$urls[]=DomainRouting::absolute('store-sitemap',['part'=>'pages']);foreach($parts as $key=>[$route,$sql]){$count=(int)Db::query($db,'SELECT COUNT(*) FROM ('.$sql.') sitemap_rows')->fetchColumn();for($p=1;$p<=(int)ceil($count/$size);$p++)$urls[]=DomainRouting::absolute('store-sitemap',['part'=>$key,'p'=>$p]);}}
  elseif($part==='pages'){foreach(['home','gallery','martyrs','artists','calls','shop','about'] as $page)$urls[]=DomainRouting::absolute($page);}
  elseif(is_string($part)&&isset($parts[$part])){[$route,$sql]=$parts[$part];$page=max(1,(int)($_GET['p']??1));foreach(Db::query($db,$sql.' ORDER BY id LIMIT '.$size.' OFFSET '.(($page-1)*$size))->fetchAll(PDO::FETCH_COLUMN) as $id)if(self::publicExists($route,(int)$id))$urls[]=DomainRouting::absolute($route,['id'=>(int)$id]);}
  else throw new HttpError(404,'بخش نقشه سایت پیدا نشد.');
  $tag=$index?'sitemapindex':'urlset';$item=$index?'sitemap':'url';return '<?xml version="1.0" encoding="UTF-8"?><'.$tag.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('',array_map(fn($url)=>'<'.$item.'><loc>'.e($url).'</loc></'.$item.'>',$urls)).'</'.$tag.'>';
 }
 public static function canonicalParams(string $page,array $query):array {
  $params=[];if(isset(self::DETAILS[$page])||$page==='store-order')$params['id']=(int)($query['id']??0);
  if($page==='store-info')$params['section']=$query['section']??'sales_terms';
  foreach(['q','axis','date_kind','calendar','year_from','year_to','geo_role','geo_country','geo_province','geo_county','geo_city','category','sort','p','production','max_price','min_price'] as $key)if(isset($query[$key])&&$query[$key]!=='')$params[$key]=$query[$key];
  return $params;
 }
}

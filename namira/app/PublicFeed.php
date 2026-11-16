<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class PublicFeed {
 private static ?PDO $db=null;
 public const SELECTORS=['gallery'=>'.masonry','martyrs'=>'.hs-martyr-grid','advanced-search'=>'.masonry,.hs-martyr-grid','artist'=>'.masonry','profile'=>'.masonry','artists'=>'.artist-grid','calls'=>'.call-grid','shop'=>'.store-grid'];
 public static function bind(PDO $db):void{self::$db=$db;}
 public static function number(mixed $v):int{if(!is_scalar($v)||!ctype_digit((string)$v)||(int)$v<1||(int)$v>PHP_INT_MAX)throw new HttpError(422,'شناسه یا شماره مجموعه معتبر نیست.');return (int)$v;}
 public static function pageSize(string $type):int{return max($type==='martyrs'?15:20,min($type==='martyrs'?20:24,(int)Env::get($type==='martyrs'?'PUBLIC_MARTYRS_PER_PAGE':'PUBLIC_IMAGES_PER_PAGE',$type==='martyrs'?'18':'24')));}
 public static function config(string $route,array $f,int $count,int $per,int $p):?array {
  if(($_GET['page']??'')==='public-feed'||!isset(self::SELECTORS[$route])||!$count)return null;unset($f['page'],$f['p']);$people=$route==='martyrs'||($route==='advanced-search'&&($f['results']??'')==='martyrs');
  $table=match($route){'artists'=>'artists','calls'=>'art_calls','shop'=>'products',default=>$people?'martyrs':'images'};
  $max=self::$db?(int)self::$db->query('SELECT MAX(id) FROM '.$table)->fetchColumn():0;
  return ['route'=>$route,'filters'=>$f,'selector'=>self::SELECTORS[$route],'count'=>$count,'per'=>$per,'page'=>$p,'max'=>$max,'cursor'=>in_array($route,['gallery','martyrs','advanced-search','artist'],true)&&($f['sort']??'new')==='new','window'=>5,'cache'=>12];
 }
 public static function grid(string $html,string $selector):string {
  $doc=new \DOMDocument();$previous=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8"><main>'.$html.'</main>');libxml_clear_errors();libxml_use_internal_errors($previous);$xp=new \DOMXPath($doc);
  foreach(explode(',',$selector) as $class){$name=substr($class,1);$nodes=$xp->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")]');if($nodes->length)return $doc->saveHTML($nodes->item(0));}return '';
 }
 public static function response(PDO $db,array $in):array {
  $route=Security::text($in,'route',30);if(!isset(self::SELECTORS[$route]))throw new HttpError(422,'این بخش فهرست عمومی نیست.');
  $p=self::number($in['batch']??1);if($p>1000000)throw new HttpError(422,'شماره مجموعه بیش از حد است.');$max=self::number($in['max']??0);$after=isset($in['after'])?self::number($in['after']):0;
  $f=$in;foreach(['route','batch','max','after','page'] as $k)unset($f[$k]);$f['p']=(string)$p;$people=$route==='martyrs'||($route==='advanced-search'&&PublicSearch::people($f));$per=self::pageSize($people?'martyrs':'images');$view='cards';$vars=[];$cursor=false;
  if(in_array($route,['gallery','martyrs','advanced-search','artist','profile'],true)){
   if(in_array($route,['gallery','martyrs','advanced-search'],true))$f=PublicSearch::input($db,$f,$route==='advanced-search');
   else {$id=self::number($in['id']??0);if($route==='artist'){if(!(new Artists($db,[]))->get($id,true))throw new HttpError(404,'هنرمند منتشرشده پیدا نشد.');$f=['artist_id'=>$id];}else{if(!(new Archive($db))->find($id))throw new HttpError(404,'شهید منتشرشده پیدا نشد.');$f=['martyr_id'=>$id,'kind'=>'all','media_type'=>$in['media_type']??''];}}
   $cursor=$route!=='profile'&&($f['sort']??'new')==='new';$w=['max'=>$max,'after'=>$after];$result=$people?MartyrDirectory::listing($db,$f,$p,$per,$w):(new Archive($db))->images($f,$p,$per,$w);
   $more=$result['more'];if($people){$view='feed-martyrs';$vars=['items'=>$result['items']];}else $vars=['images'=>$result['items']];
  }elseif($route==='artists'){$f['_feed_max']=$max;$result=(new Artists($db,[]))->listing($f,true);$per=$result['per'];$more=$p*$per<$result['count'];$view='artists';$vars=['result'=>$result,'specialties'=>Db::query($db,"SELECT id,title FROM protocol_terms WHERE taxonomy='art_specialty' AND active=1")->fetchAll()];}
  elseif($route==='calls'){$f['_feed_max']=$max;$result=CallCampaigns::listing($db,$f,true);$per=$result['per'];$more=$p*$per<$result['count'];$view='calls-list-v2';$vars=['result'=>$result];}
  else {$f['_feed_max']=$max;$result=(new StoreCatalog($db))->listing($f);$per=$result['per'];$more=$p*$per<$result['count'];$view='store-cards';$vars=['result'=>$result,'f'=>$f,'categories'=>[],'attributes'=>[],'categoryMedia'=>[]];}
  ob_start();view($view,$vars);$html=self::grid(ob_get_clean(),self::SELECTORS[$route]);$items=$result['items'];return ['html'=>$html,'more'=>(bool)$more,'next'=>$p+1,'after'=>$cursor&&$items?(int)end($items)['id']:null,'ids'=>array_map('intval',array_column($items,'id')),'url'=>url($route,array_replace($in['route']==='advanced-search'?['results'=>$people?'martyrs':'images']:[],array_diff_key($in,array_flip(['route','batch','max','after','page'])),['p'=>$p]))];
 }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Layout references existing public content; no content copies or automatic publication. */
final class HomeShowcase {
 public const KEY='home_showcase_v1';
 public const TYPES=['banner'=>'بنر و باکس معرفی','images'=>'تصاویر شهدا','request'=>'درخواست طراحی','products'=>'محصولات فروشگاه','calls'=>'فراخوان‌ها','artists'=>'هنرمندان','martyrs'=>'شهدا'];
 public static function defaults(PDO $db):array {
  $hero=Storefront::settings($db);$make=fn($id,$type,$title,$count=6)=>['id'=>$id,'type'=>$type,'title'=>$title,'description'=>'','enabled'=>true,'mode'=>'auto','count'=>$count,'ids'=>[],'button'=>'مشاهده بیشتر','link'=>'','assets'=>[]];
  $blocks=[$make('hero001','banner',$hero['home_hero_title']),$make('images01','images','تازه‌ترین قاب‌های ماندگار',12),$make('calls001','calls','فرصتی برای خلق اثری ماندگار',2),$make('products1','products','یادگارهایی برای ماندن',4),$make('request1','request','تصویر شهیدتان را به نامیرا بسپارید'),$make('artists1','artists','هنرمندانِ روایت ماندگار'),$make('martyrs1','martyrs','نام‌هایی که زنده می‌مانند')];
  $blocks[0]['description']=$hero['home_hero_subtitle'];$blocks[0]['link']=url('gallery');$blocks[0]['button']='کاوش در همه تصاویر';$blocks[4]['description']='برای طراحی تصویر شهید موردنظرتان، اطلاعات و عکس‌های اولیه را رایگان ارسال کنید.';
  return ['version'=>0,'draft'=>$blocks,'published'=>$blocks,'published_at'=>null];
 }
 public static function state(PDO $db):array {
  if(!PlatformSchema::ready($db))return self::defaults($db);
  $raw=Db::query($db,'SELECT value FROM app_settings WHERE setting_key=?',[self::KEY])->fetchColumn();
  return $raw===false?self::defaults($db):json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 }
 public static function link(string $value):string {
  if($value==='')return '';if(preg_match('/[\x00-\x20\\\\]/',$value)||str_starts_with($value,'//'))throw new HttpError(422,'لینک مقصد معتبر نیست.');
  if(str_starts_with($value,'/'))return $value;
  $p=parse_url($value);if(!filter_var($value,FILTER_VALIDATE_URL)||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass']))throw new HttpError(422,'لینک باید داخلی یا با https باشد.');return $value;
 }
 public static function normalize(mixed $blocks,array $previous):array {
  if(!is_array($blocks)||!array_is_list($blocks)||count($blocks)>20)throw new HttpError(422,'حداکثر ۲۰ بخش در ویترین قابل استفاده است.');
  $old=array_column($previous,null,'id');$seen=[];$out=[];
  foreach($blocks as $b){
   if(!is_array($b)||!isset(self::TYPES[$b['type']??''])||!is_string($b['id']??null)||!preg_match('/^[A-Za-z0-9_-]{6,40}$/D',$b['id'])||isset($seen[$b['id']]))throw new HttpError(422,'شناسه یا نوع بخش معتبر نیست.');$seen[$b['id']]=true;
   $ids=$b['ids']??[];if(!is_array($ids)||count($ids)>24)throw new HttpError(422,'در هر بخش حداکثر ۲۴ مورد انتخاب کنید.');$ids=array_values(array_unique(array_map(fn($id)=>StoreCatalog::integer($id,1),$ids)));
   $mode=$b['mode']??'auto';if(!in_array($mode,['auto','manual'],true))throw new HttpError(422,'روش انتخاب معتبر نیست.');
   $assets=($old[$b['id']]['type']??'')===$b['type']?($old[$b['id']]['assets']??[]):[];
   foreach(['desktop','mobile'] as $device)if(!empty($b['remove_'.$device]))unset($assets[$device]);
   $out[]=['id'=>$b['id'],'type'=>$b['type'],'title'=>Security::text($b,'title',160),'description'=>Security::text($b,'description',600,false),'enabled'=>!empty($b['enabled']),'mode'=>$mode,'count'=>StoreCatalog::integer($b['count']??6,1,24),'ids'=>$ids,'button'=>Security::text($b,'button',60,false)?:'مشاهده بیشتر','link'=>self::link(Security::text($b,'link',1500,false)),'assets'=>$assets];
  }return $out;
 }
 public static function banner(string $source):array {
  $meta=Upload::validate($source);Upload::assertDecodeMemory($meta);$image=match($meta['mime']){'image/jpeg'=>@imagecreatefromjpeg($source),'image/png'=>@imagecreatefrompng($source),'image/webp'=>@imagecreatefromwebp($source)};
  if(!$image)throw new HttpError(422,'بنر قابل پردازش نیست.');$scale=min(1,1920/max($meta['width'],$meta['height']));$w=max(1,(int)round($meta['width']*$scale));$h=max(1,(int)round($meta['height']*$scale));$canvas=imagecreatetruecolor($w,$h);imagealphablending($canvas,false);imagesavealpha($canvas,true);imagecopyresampled($canvas,$image,0,0,0,0,$w,$h,$meta['width'],$meta['height']);$dir=Env::storage().'/home-banners';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new HttpError(503,'پوشه بنر قابل نوشتن نیست.');$path='home-banners/'.bin2hex(random_bytes(24)).'.webp';
  try{if(!imagewebp($canvas,Env::storage().'/'.$path,82))throw new HttpError(503,'ذخیره بنر انجام نشد.');chmod(Env::storage().'/'.$path,0600);}finally{imagedestroy($image);imagedestroy($canvas);}return ['path'=>$path,'width'=>$w,'height'=>$h,'mime'=>'image/webp'];
 }
 public static function save(PDO $db,array $actor,array $in,array $uploads=[]):array {
  if(!Permissions::allows($db,$actor,'settings.manage'))throw new HttpError(403,'مجوز مدیریت صفحه اصلی ندارید.');
  if(!PlatformSchema::ready($db))throw new HttpError(503,'ساختار تنظیمات آماده نیست.');
  try{$blocks=json_decode(Security::text($in,'layout',60000),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'تنظیمات بخش‌ها معتبر نیست.');}
  $version=StoreCatalog::integer($in['version']??-1,0);$publish=($in['intent']??'draft')==='publish';$created=[];
  try{return Db::transaction($db,function()use($db,$actor,$in,$uploads,$blocks,$version,$publish,&$created){
   $row=Db::query($db,'SELECT value FROM app_settings WHERE setting_key=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[self::KEY])->fetchColumn();$state=$row===false?self::defaults($db):json_decode($row,true,64,JSON_THROW_ON_ERROR);
   if($state['version']!==$version)throw new HttpError(409,'چیدمان در پنجره دیگری تغییر کرده است. ابتدا نسخه تازه را بارگیری کنید؛ ورودی‌های شما حفظ شده‌اند.');
   $normalized=self::normalize($blocks,$state['draft']);
   foreach($normalized as &$b)if($b['type']==='banner')foreach(['desktop','mobile'] as $device){$key='banner_'.$b['id'].'_'.$device;if(isset($uploads[$key])){$asset=self::banner($uploads[$key]);$created[]=$asset['path'];$b['assets'][$device]=$asset;}}unset($b);
   $next=['version'=>$version+1,'draft'=>$normalized,'published'=>$publish?$normalized:$state['published'],'published_at'=>$publish?date(DATE_ATOM):$state['published_at']];$json=Protocol::encode($next);if(strlen($json)>60000)throw new HttpError(422,'حجم تنظیمات ویترین زیاد است؛ متن یا تعداد بخش‌ها را کاهش دهید.');
   if($publish){$dir=Env::storage().'/backups';if(!is_dir($dir))mkdir($dir,0700,true);$file=$dir.'/home-layout-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';if(file_put_contents($file,Protocol::encode($state),LOCK_EX)===false)throw new HttpError(503,'پشتیبان چیدمان ساخته نشد.');chmod($file,0600);}
   if($row===false)Db::insert($db,'app_settings',['setting_key'=>self::KEY,'value'=>$json,'updated_by'=>$actor['id'],'updated_at'=>date(DATE_ATOM)]);else Db::query($db,'UPDATE app_settings SET value=?,updated_by=?,updated_at=? WHERE setting_key=?',[$json,$actor['id'],date(DATE_ATOM),self::KEY]);
   Db::audit($db,$publish?'home.publish':'home.draft','version:'.$next['version']);return $next;
  });}catch(\Throwable $e){foreach($created as $path)if(is_file(Env::storage().'/'.$path))unlink(Env::storage().'/'.$path);if($e instanceof \PDOException&&$e->getCode()==='23000')throw new HttpError(409,'ویترین هم‌زمان تغییر کرده است؛ نسخه تازه را بررسی کنید.');throw $e;}
 }
 public static function content(PDO $db,string $type,array $ids=[],int $limit=6,string $q=''):array {
  $limit=max(1,min(24,$limit));$manual=(bool)$ids;$ids=array_slice($ids,0,24);$archive=new Archive($db);$items=[];
  if($type==='images'){
   if(!$manual)return $archive->images(['q'=>$q],1,$limit)['items'];
   foreach($ids as $id)if($item=$archive->image($id))$items[]=$item;
  }elseif($type==='products'&&StoreSchema::ready($db)){
   $catalog=new StoreCatalog($db);if(!$manual)return array_slice($catalog->listing(['q'=>$q])['items'],0,$limit);
   foreach($ids as $id)try{$p=$catalog->get($id);if(!$p['variants'])continue;$prices=array_map(fn($v)=>(int)($v['sale_price']??$v['price']),$p['variants']);$p['min_price']=min($prices);$p['cover_id']=$p['media'][0]['id']??null;$items[]=$p;}catch(HttpError $e){if($e->status!==404)throw $e;}
  }elseif($type==='artists'&&PlatformSchema::ready($db)){
   $artists=new Artists($db,[]);if(!$manual)return array_slice($artists->listing(['q'=>$q],true)['items'],0,$limit);foreach($ids as $id)if($item=$artists->get($id,true))$items[]=$item;
  }elseif($type==='martyrs'){
   $where='m.published=1';$args=[];if($manual){$where.=' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=$ids;}elseif($q!==''){$where.=' AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.code LIKE ?)';$args=array_fill(0,3,'%'.$q.'%');}
   $rows=Db::query($db,'SELECT m.id FROM martyrs m WHERE '.$where.' ORDER BY m.id DESC LIMIT 24',$args)->fetchAll(PDO::FETCH_COLUMN);if($manual)$rows=array_values(array_intersect($ids,array_map('intval',$rows)));
   foreach(array_slice($rows,0,$limit) as $id){$m=$archive->find((int)$id,true);if(!$m)continue;$m['cover']=$archive->images(['martyr_id'=>$id,'kind'=>'all'],1,1)['items'][0]??null;$m['title']=($m['display_name']??'')?:trim($m['first_name'].' '.$m['last_name']);$items[]=$m;}
  }elseif($type==='calls'&&CallSchema::ready($db)){
   $where="status='published'";$args=[];if($manual){$where.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=$ids;}if($q!==''){$where.=' AND title LIKE ?';$args[]='%'.$q.'%';}$now=date('Y-m-d H:i:s');$where.=' AND (start_at IS NULL OR start_at<=?) AND (end_at IS NULL OR end_at>=?)';array_push($args,$now,$now);
   foreach(Db::query($db,'SELECT * FROM art_calls WHERE '.$where.' ORDER BY id DESC LIMIT 100',$args)->fetchAll() as $c){$c['config']=array_replace(CallCampaigns::defaults(),Protocol::decode($c['criteria']));if(CallCampaigns::accepting($c))$items[]=$c;}
   if($manual){$map=array_column($items,null,'id');$items=[];foreach($ids as $id)if(isset($map[$id]))$items[]=$map[$id];}
  }return array_slice($items,0,$limit);
 }
 public static function sections(PDO $db,bool $draft=false):array {
  $state=self::state($db);$out=[];foreach($state[$draft?'draft':'published'] as $b){if(!$b['enabled'])continue;$b['items']=[];if($b['type']==='banner'&&empty($b['assets']))$b['items']=(new Archive($db))->images([],1,3)['items'];if(!in_array($b['type'],['banner','request'],true)){if($b['mode']==='manual'&&!$b['ids'])continue;$b['items']=self::content($db,$b['type'],$b['mode']==='manual'?$b['ids']:[],$b['count']);if(!$b['items'])continue;}$out[]=$b;}return $out;
 }
 public static function asset(PDO $db,string $id,string $device,bool $draft=false):array {
  if(!in_array($device,['desktop','mobile'],true))throw new HttpError(404,'بنر پیدا نشد.');
  foreach(self::state($db)[$draft?'draft':'published'] as $b)if($b['id']===$id&&$b['type']==='banner'&&($draft||$b['enabled'])&&isset($b['assets'][$device]))return $b['assets'][$device];throw new HttpError(404,'بنر پیدا نشد.');
 }
}

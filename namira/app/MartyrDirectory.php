<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Public directory and shared presentation of published martyr records. */
final class MartyrDirectory {
 /** Publicly renderable image metadata, regardless of design/photo/document classification. */
 public static function imageCondition(string $alias):string {
  if(!in_array($alias,['i','di'],true))throw new \LogicException('Unexpected image alias');
  return "$alias.published=1 AND $alias.mime LIKE 'image/%' AND $alias.width>0 AND $alias.height>0 AND $alias.bytes>0 AND TRIM($alias.thumb_path)<>''";
 }
 /** Directory-only scope: never apply this to admin lookups or direct profiles. */
 public static function conditions(PDO $db,array $f=[]):array {
  [$where,$args]=(new Archive($db))->conditions($f);$image=self::imageCondition('di');
  $exists=PlatformSchema::ready($db)
   ?'EXISTS(SELECT 1 FROM media_public_martyrs dr JOIN images di ON di.id=dr.image_id JOIN media_publication dp ON dp.image_id=di.id WHERE dr.martyr_id=m.id AND '.$image.')'
   :'EXISTS(SELECT 1 FROM images di WHERE di.martyr_id=m.id AND '.$image.')';
  return [$where.' AND '.$exists,$args];
 }

 public static function card(PDO $db,array $m):array {
  $m['title']=($m['display_name']??'')?:trim($m['first_name'].' '.$m['last_name']);
  $m['cover']=(new Archive($db))->images(['martyr_id'=>$m['id'],'kind'=>'all'],1,1)['items'][0]??null;
  $m['topics']=[];$m['card_city']='';
  if(MartyrTaxonomySchema::ready($db)){
   $m['topics']=Db::query($db,"SELECT DISTINCT t.title,t.sort_order,t.id FROM martyr_public_terms r JOIN protocol_terms t ON t.id=r.term_id WHERE r.martyr_id=? AND r.axis_key='collection' AND t.active=1 ORDER BY t.sort_order,t.id",[$m['id']])->fetchAll(PDO::FETCH_COLUMN);
   $m['card_city']=(string)(Db::query($db,"SELECT t.title FROM martyr_public_facts f JOIN protocol_terms t ON t.id=f.city_id WHERE f.martyr_id=? AND f.fact_key='residence_at_incident' AND t.active=1 ORDER BY f.ordinal LIMIT 1",[$m['id']])->fetchColumn()?:'');
  }
  if($m['card_city']==='')$m['card_city']=(string)($m['city']??'');
  return $m;
 }
 public static function cards(PDO $db,array $items):array {
  if(!$items)return [];$ids=array_column($items,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$covers=[];$topics=[];$cities=[];
  $rows=Db::query($db,"SELECT * FROM (SELECT i.id,i.title,i.kind,i.width,i.height,r.martyr_id AS owner_id,m.id AS martyr_id,m.first_name,m.last_name,m.code,m.province,m.city,p.public_name AS display_name,mp.download_allowed,ROW_NUMBER() OVER(PARTITION BY r.martyr_id ORDER BY r.is_featured DESC,mp.sort_order,i.id DESC) AS rn FROM media_public_martyrs r JOIN images i ON i.id=r.image_id AND ".self::imageCondition('i')." JOIN media_publication mp ON mp.image_id=i.id JOIN martyrs m ON m.id=r.martyr_id AND m.published=1 LEFT JOIN martyr_protocols p ON p.martyr_id=m.id WHERE r.martyr_id IN ($marks)) cover_rows WHERE rn=1",$ids)->fetchAll();
  foreach(Storefront::cards($db,$rows) as $row)$covers[$row['owner_id']]=$row;
  foreach(Db::query($db,"SELECT DISTINCT r.martyr_id,t.id,t.title,t.sort_order FROM martyr_public_terms r JOIN protocol_terms t ON t.id=r.term_id AND t.active=1 WHERE r.axis_key='collection' AND r.martyr_id IN ($marks) ORDER BY t.sort_order,t.id",$ids)->fetchAll() as $r)$topics[$r['martyr_id']][]=$r['title'];
  foreach(Db::query($db,"SELECT f.martyr_id,t.title FROM martyr_public_facts f JOIN protocol_terms t ON t.id=f.city_id AND t.active=1 WHERE f.fact_key='residence_at_incident' AND f.martyr_id IN ($marks) ORDER BY f.ordinal",$ids)->fetchAll() as $r)$cities[$r['martyr_id']]??=$r['title'];
  foreach($items as &$m){$m['title']=($m['display_name']??'')?:trim($m['first_name'].' '.$m['last_name']);$m['cover']=$covers[$m['id']]??null;$m['topics']=$topics[$m['id']]??[];$m['card_city']=$cities[$m['id']]??($m['city']??'');}unset($m);return $items;
 }
 public static function listing(PDO $db,array $f=[],int $page=1,int $per=0,?array $window=null):array {
  [$where,$args]=self::conditions($db,$f);$page=max(1,$page);$per=$per?:PublicFeed::pageSize('martyrs');$per=max(1,min(48,$per));$cursor=$window&&!empty($window['after'])&&($f['sort']??'new')==='new';if($window){$where.=' AND m.id<=?';$args[]=$window['max'];if($cursor&&!empty($window['after'])){$where.=' AND m.id<?';$args[]=$window['after'];}}
  $count=$window?0:(int)Db::query($db,'SELECT COUNT(*) FROM martyrs m WHERE '.$where,$args)->fetchColumn();
  $sort=Security::text($f,'sort',20,false)==='name'?'m.last_name,m.first_name,m.id':'m.id DESC';$rows=Db::query($db,'SELECT m.id,m.code,m.first_name,m.last_name,m.city,m.province,mp.public_name AS display_name FROM martyrs m LEFT JOIN martyr_protocols mp ON mp.martyr_id=m.id WHERE '.$where.' ORDER BY '.$sort.' LIMIT '.($per+($window?1:0)).' OFFSET '.($cursor?0:(($page-1)*$per)),$args)->fetchAll();$more=$window&&count($rows)>$per;if($more)array_pop($rows);$items=self::cards($db,$rows);
  return compact('items','count','more');
 }
}

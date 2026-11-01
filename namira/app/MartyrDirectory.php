<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Public directory and shared presentation of published martyr records. */
final class MartyrDirectory {
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
 public static function listing(PDO $db,array $f=[],int $page=1,int $per=24):array {
  [$where,$args]=(new Archive($db))->conditions($f);$page=max(1,$page);$per=max(1,min(48,$per));
  $count=(int)Db::query($db,'SELECT COUNT(*) FROM martyrs m WHERE '.$where,$args)->fetchColumn();
  $ids=Db::query($db,'SELECT m.id FROM martyrs m WHERE '.$where.' ORDER BY m.id DESC LIMIT '.$per.' OFFSET '.(($page-1)*$per),$args)->fetchAll(PDO::FETCH_COLUMN);$items=[];
  foreach($ids as $id)if($m=(new Archive($db))->find((int)$id))$items[]=self::card($db,$m);
  return compact('items','count');
 }
}

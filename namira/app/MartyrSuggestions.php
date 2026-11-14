<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MartyrSuggestions {
 public static function search(PDO $db,array|false $actor,string $first,string $last,int $exclude=0):array {
  if(!$actor||!Permissions::allows($db,$actor,'martyrs.edit'))throw new HttpError(403,'جست‌وجوی پرونده‌های مدیریت مجاز نیست.');
  $name=Security::normalize(str_replace("\u{200C}",' ',trim($first.' '.$last)));if(mb_strlen($name)<2)return [];
  if(mb_strlen($name)>301)throw new HttpError(422,'نام بیش از حد طولانی است.');
  $words=array_slice(preg_split('/\s+/u',$name,-1,PREG_SPLIT_NO_EMPTY),0,8);$where=['id<>?'];$args=[$exclude];
  foreach($words as $word){$word=str_replace(['!','%','_'],['!!','!%','!_'],$word);$where[]="(REPLACE(REPLACE(first_name,'ي','ی'),'ك','ک') LIKE ? ESCAPE '!' OR REPLACE(REPLACE(last_name,'ي','ی'),'ك','ک') LIKE ? ESCAPE '!')";array_push($args,'%'.$word.'%','%'.$word.'%');}
  $rows=Db::query($db,'SELECT id,code,first_name,last_name,father_name,province,city FROM martyrs WHERE '.implode(' AND ',$where).' ORDER BY last_name,first_name,id LIMIT 10',$args)->fetchAll();
  foreach($rows as &$row){$row['location']=implode('، ',array_filter([$row['province'],$row['city']],fn($v)=>$v!==''));if(MartyrTaxonomySchema::ready($db)){$place=Db::query($db,"SELECT p.title AS province,c.title AS city FROM martyr_public_facts f LEFT JOIN protocol_terms p ON p.id=f.province_id LEFT JOIN protocol_terms c ON c.id=f.city_id WHERE f.martyr_id=? AND f.fact_key IN ('birth_place','residence_at_incident','martyrdom_place') ORDER BY f.fact_key LIMIT 1",[$row['id']])->fetch();if($place)$row['location']=implode('، ',array_filter([$place['province']??'',$place['city']??''],fn($v)=>$v!==''))?:$row['location'];}$row['url']=url('admin',['tab'=>'martyrs','edit'=>$row['id']]);unset($row['province'],$row['city']);}unset($row);return $rows;
 }
 public static function response(PDO $db):never {
  $actor=Security::user($db);if(!$actor||!Permissions::allows($db,$actor,'martyrs.edit'))throw new HttpError(403,'جست‌وجو مجاز نیست.');Security::limit($db,'martyr-similar',120,60);$items=self::search($db,$actor,Security::text($_GET,'first',150,false),Security::text($_GET,'last',150,false),(int)($_GET['exclude']??0));header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');echo json_encode($items,JSON_UNESCAPED_UNICODE);exit;
 }
}

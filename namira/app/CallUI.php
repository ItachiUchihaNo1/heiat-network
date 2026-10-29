<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallUI {
 public static function render(string $view,array $data=[]):string {ob_start();try{view($view,$data);return ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;}}
 public static function terms(PDO $db):array {return Db::query($db,'SELECT id,title,taxonomy,parent_id,metadata,active FROM protocol_terms ORDER BY sort_order,title')->fetchAll();}
 public static function publicState(string $s):string {return ['private'=>'منتشر نشده','published'=>'منتشر شده','withdrawn'=>'کنارگذاشته‌شده از انتشار'][$s]??$s;}
 public static function limit(?int $n):string {return $n===null?'بدون محدودیت تعداد':fa($n);}
 public static function fields(array $fields,string $scope):array {return array_values(array_filter($fields,fn($f)=>($f['scope']??'work')===$scope&&!empty($f['active'])));}
 public static function sectionTitle(array $sections,array $section):string {
  $map=array_column($sections,null,'id');$parts=[$section['title']];$p=(int)$section['parent_id'];$seen=[];
  while($p&&isset($map[$p])&&!isset($seen[$p])){$seen[$p]=true;array_unshift($parts,$map[$p]['title']);$p=(int)$map[$p]['parent_id'];}return implode(' / ',$parts);
 }
 public static function csrfForm(string $action,array $values=[]):string {
  $s=Security::field().'<input type="hidden" name="action" value="'.e($action).'">';
  foreach($values as $key=>$value)$s.='<input type="hidden" name="'.e($key).'" value="'.e($value).'">';return $s;
 }
}

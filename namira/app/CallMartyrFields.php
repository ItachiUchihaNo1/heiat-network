<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallMartyrFields {
 public static function catalog(PDO $db):array {
  $out=[];foreach(range(1,7) as $step)foreach(SimpleMartyrForm::definitions($db,$step) as $key=>$def)$out[$key]=$def+['step'=>$step,'group'=>SimpleMartyrForm::STEPS[$step]];
  return $out;
 }
 public static function selection(PDO $db,array $selection):array {
  $all=self::catalog($db);$out=[];
  foreach($selection as $key=>$rule){
   if(!isset($all[$key]))throw new HttpError(422,'فیلد اطلاعات شهید در فرم اصلی وجود ندارد.');
   $out[$key]=$all[$key]+['required'=>is_array($rule)?!empty($rule['required']):!empty($rule)];
  }return $out;
 }
 public static function validate(PDO $db,array $actor,array $definitions,array $input,bool $strict=false,array $previous=[]):array {
  unset($input['states'],$input['source_id']);$known=array_intersect_key($input,$definitions);$known['_present']=array_intersect_key((array)($input['_present']??[]),$definitions);
  $validator=new ProtocolValidator($db,$actor,null,true);$out=$validator->fields($definitions,$known,'martyr.',$previous);$validator->assert();unset($out['states']);
  if($strict){$errors=[];foreach($definitions as $key=>$def){$v=$out[$key]??null;$empty=$v===null||$v===''||$v===[];if($def['type']==='date')$empty=($v['precision']??'unknown')==='unknown';if($def['type']==='place')$empty=!array_filter($v??[],fn($x)=>$x!==''&&$x!==null&&$x!==0);if(!empty($def['required'])&&$empty)$errors['martyr.'.$key]=$def['label'].' الزامی است.';}if($errors)throw new ProtocolErrors($errors);}
  return $out;
 }
 public static function value(PDO $db,array $def,mixed $v):string {
  if($v===null)return '';if($def['type']==='date'&&is_array($v))return ProtocolDates::label($v);
  if(in_array($def['type'],['term','terms'],true)){$labels=[];foreach((array)$v as $id)$labels[]=Db::query($db,'SELECT title FROM protocol_terms WHERE id=?',[(int)$id])->fetchColumn()?:('#'.$id);return implode('، ',$labels);}
  if($def['type']==='place'&&is_array($v)){$parts=[];foreach($v as $key=>$part)if($part!==null&&$part!==''){if(str_ends_with($key,'_id')&&is_numeric($part))$parts[]=Db::query($db,'SELECT title FROM protocol_terms WHERE id=?',[(int)$part])->fetchColumn()?:('#'.$part);elseif(is_scalar($part))$parts[]=(string)$part;}return implode('، ',array_unique($parts));}
  if(is_array($v))return Protocol::encode($v);if(is_bool($v))return $v?'بله':'خیر';return (string)$v;
 }
 public static function label(PDO $db,array $values):string {
  return trim((string)($values['display_name']??''))?:trim(($values['first_name']??'').' '.($values['last_name']??''))?:((string)($values['archive_code']??'شهید پیشنهادی'));
 }
}

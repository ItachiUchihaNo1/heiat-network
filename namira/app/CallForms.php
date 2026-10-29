<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallForms {
 public const TYPES=['text'=>'متن کوتاه','textarea'=>'متن بلند','number'=>'عدد','date'=>'تاریخ','select'=>'انتخاب تکی','multi'=>'انتخاب چندگانه','checkbox'=>'چک‌باکس','file'=>'فایل'];
 public static function definitions(array $fields):array {
  if(count($fields)>100)throw new HttpError(422,'تعداد فیلدهای فرم بیش از ظرفیت مجاز است.');$keys=[];$out=[];
  foreach($fields as $i=>$f){
   if(!is_array($f))throw new HttpError(422,'ساختار فیلد معتبر نیست.');$key=Security::text($f,'key',80);
   if(!preg_match('/^[a-z][a-z0-9_]*$/D',$key)||isset($keys[$key]))throw new HttpError(422,'کلید فیلد باید پایدار و یکتا باشد.');
   $type=Security::text($f,'type',20);if(!isset(self::TYPES[$type]))throw new HttpError(422,'نوع فیلد معتبر نیست.');
   $scope=$f['scope']??'work';if(!in_array($scope,['common','work'],true))throw new HttpError(422,'تعلق فیلد را انتخاب کنید.');
   $options=[];foreach((array)($f['options']??[]) as $value=>$option){
    if(is_array($option)){$v=Security::text($option,'value',100);$label=Security::text($option,'label',150);}else{$v=is_int($value)?(string)$option:(string)$value;$label=(string)$option;}
    if($v===''||isset($options[$v])||mb_strlen($v)>100||mb_strlen($label)>150)throw new HttpError(422,'گزینه‌های فیلد معتبر و یکتا باشند.');$options[$v]=$label;
   }
   if(in_array($type,['select','multi'],true)&&!$options)throw new HttpError(422,'برای فیلد انتخابی گزینه تعریف کنید.');
   $out[]=['key'=>$key,'label'=>Security::text($f,'label',150),'help'=>Security::text($f,'help',1000,false),'type'=>$type,'scope'=>$scope,'required'=>!empty($f['required']),'active'=>!array_key_exists('active',$f)||!empty($f['active']),'sort_order'=>(int)($f['sort_order']??$i),'max_length'=>self::integer($f['max_length']??20000,1,20000),'options'=>$options];$keys[$key]=true;
  }
  usort($out,fn($a,$b)=>$a['sort_order']<=>$b['sort_order']);return $out;
 }
 public static function integer(mixed $value,int $min=0,int $max=1000000):int {
  if(!is_scalar($value)||filter_var(Security::normalize((string)$value),FILTER_VALIDATE_INT)===false)throw new HttpError(422,'عدد صحیح معتبر وارد کنید.');
  $n=(int)Security::normalize((string)$value);if($n<$min||$n>$max)throw new HttpError(422,'عدد خارج از محدوده مجاز است.');return $n;
 }
 public static function validate(array $fields,array $input,bool $strict=false,array $previous=[]):array {
  $out=$previous;$errors=[];
  foreach($fields as $f){
   if(empty($f['active']))continue;$key=$f['key'];if(!array_key_exists($key,$input)&&!$strict)continue;
   $v=$input[$key]??$previous[$key]??null;
   try{
    switch($f['type']){
     case 'checkbox':if(!in_array($v,[null,'',false,true,0,1,'0','1','on'],true))throw new HttpError(422,'انتخاب معتبر نیست.');$v=in_array($v,[true,1,'1','on'],true);break;
     case 'multi':case 'file':
      if($v===null||$v==='')$v=[];if(!is_array($v)||count($v)>10000)throw new HttpError(422,'فهرست معتبر لازم است.');
      $values=[];foreach($v as $part){if(!is_scalar($part))throw new HttpError(422,'گزینه معتبر نیست.');$part=(string)$part;if($f['type']==='file')$values[]=self::integer($part,1,2147483647);elseif(!array_key_exists($part,$f['options']))throw new HttpError(422,'گزینه در فرم تعریف نشده است.');else $values[]=$part;}$v=array_values(array_unique($values));break;
     default:
      if($v===null)$v='';if(!is_scalar($v))throw new HttpError(422,'مقدار تکی لازم است.');$v=trim((string)$v);
      if(mb_strlen($v)>$f['max_length'])throw new HttpError(422,'طول متن بیش از حد مجاز است.');
      if($v!==''&&$f['type']==='select'&&!array_key_exists($v,$f['options']))throw new HttpError(422,'گزینه در فرم تعریف نشده است.');
      if($v!==''&&$f['type']==='number'){$v=Security::normalize($v);if(!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$v))throw new HttpError(422,'عدد معتبر وارد کنید.');}
      if($v!==''&&$f['type']==='date'){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new HttpError(422,'تاریخ معتبر وارد کنید.');}
    }
    if($strict&&!empty($f['required'])&&($v===''||$v===null||$v===[]||$v===false))throw new HttpError(422,'تکمیل این فیلد الزامی است.');
    $out[$key]=$v;
   }catch(HttpError $e){$errors[$key]=$f['label'].' — '.$e->getMessage();}
  }
  if($errors)throw new ProtocolErrors($errors);return $out;
 }
 public static function answerLabels(array $fields,array $answers):array {
  $out=[];foreach($fields as $f)if(array_key_exists($f['key'],$answers)){$v=$answers[$f['key']];if($f['type']==='checkbox')$v=$v?'بله':'خیر';elseif(in_array($f['type'],['select','multi'],true))$v=implode('، ',array_map(fn($x)=>$f['options'][$x]??(string)$x,(array)$v));elseif(is_array($v))$v=implode('، ',array_map('strval',$v));$out[$f['label']]=$v;}return $out;
 }
}

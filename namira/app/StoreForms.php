<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreForms {
 public const TYPES=['text'=>'متن کوتاه','textarea'=>'متن بلند','number'=>'عدد','select'=>'انتخاب تکی','multi'=>'انتخاب چندگانه','checkbox'=>'چک‌باکس','date'=>'تاریخ','image'=>'تصویر','file'=>'فایل'];
 public static function definition(array $fields):array {
 if(count($fields)>30)throw new HttpError(422,'حداکثر ۳۰ فیلد مجاز است.');$keys=[];
 foreach($fields as &$f){$k=Security::text($f,'key',80);if(!preg_match('/^[a-z][a-z0-9_]*$/D',$k)||isset($keys[$k]))throw new HttpError(422,'کلید فیلد معتبر و یکتا باشد.');$f['label']=Security::text($f,'label',150);$f['help']=Security::text($f,'help',500,false);$f['type']=Security::text($f,'type',20);if(!isset(self::TYPES[$f['type']]))throw new HttpError(422,'نوع فیلد معتبر نیست.');$f['required']=!empty($f['required']);$f['max_length']=StoreCatalog::integer($f['max_length']??3000,1,20000);$f['max_files']=StoreCatalog::integer($f['max_files']??1,1,10);$f['max_mb']=StoreCatalog::integer($f['max_mb']??20,1,100);$f['formats']=$f['formats']??($f['type']==='image'?['jpg','jpeg','png','webp']:['jpg','jpeg','png','webp','pdf']);if(!is_array($f['formats'])||array_diff($f['formats'],['jpg','jpeg','png','webp','pdf','zip','psd']))throw new HttpError(422,'فرمت فایل مجاز نیست.');
 if(isset($f['condition'])&&$f['condition']){if(!is_array($f['condition'])||!isset($keys[$f['condition']['field']??''])||in_array($keys[$f['condition']['field']],['multi','image','file'],true)||!is_scalar($f['condition']['equals']??null))throw new HttpError(422,'شرط فقط به یک فیلد قبلی وابسته باشد.');}
 $f['options']??=[];if(!is_array($f['options'])||count($f['options'])>100)throw new HttpError(422,'گزینه‌ها معتبر نیستند.');$options=[];foreach($f['options'] as &$o){$o['value']=Security::text($o,'value',100);if(isset($options[$o['value']]))throw new HttpError(422,'گزینه تکراری است.');$options[$o['value']]=true;$o['label']=Security::text($o,'label',150);$o['price']=StoreCatalog::integer($o['price']??0,0,1000000000);}unset($o);if(in_array($f['type'],['select','multi'],true)&&!$options)throw new HttpError(422,'فیلد انتخابی باید گزینه داشته باشد.');$keys[$k]=$f['type'];
 }unset($f);return array_values($fields);
 }
 public static function save(PDO $db,array $actor,array $in,int $id=0):int {
 if(!Permissions::allows($db,$actor,'shop.manage'))throw new HttpError(403,'مدیریت فرم فقط برای مدیر فروشگاه مجاز است.');$title=Security::text($in,'title',150);$fields=self::definition($in['fields']??[]);
 return Db::transaction($db,function()use($db,$actor,$id,$title,$fields,$in){$old=$id?Db::query($db,'SELECT * FROM store_forms WHERE id=?'.StoreAccess::lock($db),[$id])->fetch():null;if($id&&!$old)throw new HttpError(404,'فرم پیدا نشد.');if($id&&(int)($in['revision']??-1)!==(int)$old['revision'])throw new HttpError(409,'نسخه فرم تغییر کرده است.');$d=['title'=>$title,'revision'=>($old['revision']??0)+1,'fields_json'=>Protocol::encode($fields),'active'=>!empty($in['active'])?1:0,'updated_at'=>date(DATE_ATOM)];if($id)Db::query($db,'UPDATE store_forms SET title=?,revision=?,fields_json=?,active=?,updated_at=? WHERE id=?',[...array_values($d),$id]);else $id=Db::insert($db,'store_forms',$d);StoreAccess::audit($db,$actor,'form.save','form',$id,$old?:[],$d);return $id;});
 }
 public static function forProduct(PDO $db,array $p):array {$id=(int)($p['meta']['form_id']??0);if(!$id)return ['id'=>0,'revision'=>0,'fields'=>[]];$f=Db::query($db,'SELECT * FROM store_forms WHERE id=? AND active=1',[$id])->fetch();if(!$f)throw new HttpError(422,'فرم محصول فعال نیست.');$f['fields']=Protocol::decode($f['fields_json']);return $f;}
 public static function validate(PDO $db,array $form,array $input,string $cart):array {
 if(count($input)>60)throw new HttpError(422,'تعداد ورودی بیش از حد است.');$values=[];$surcharge=0;$files=[];
 foreach($form['fields'] as $f){$k=$f['key'];$cond=$f['condition']??null;if($cond&&(string)($values[$cond['field']]??'')!==(string)$cond['equals'])continue;$v=$input[$k]??$f['default']??'';$type=$f['type'];
 if(in_array($type,['multi','image','file'],true)){$v=$v===''?[]:$v;if(!is_array($v)||count($v)>100)throw new HttpError(422,'فیلد '.$f['label'].' معتبر نیست.');$v=array_values(array_unique($v,SORT_REGULAR));}else {if(!is_scalar($v))throw new HttpError(422,'فیلد '.$f['label'].' معتبر نیست.');$v=trim((string)$v);}
 if($f['required']&&($v===''||$v===[]||($type==='checkbox'&&$v!=='1')))throw new HttpError(422,'فیلد '.$f['label'].' الزامی است.');
 if(in_array($type,['image','file'],true)){if(count($v)>$f['max_files'])throw new HttpError(422,'تعداد فایل '.$f['label'].' زیاد است.');foreach($v as $fid){$file=Db::query($db,"SELECT * FROM store_files WHERE id=? AND cart_token=? AND item_id IS NULL AND kind='input' AND field_key=? AND active=1",[StoreCatalog::integer($fid,1),$cart,$k])->fetch();if(!$file||$file['bytes']>$f['max_mb']*1048576||!in_array(strtolower(pathinfo($file['filename'],PATHINFO_EXTENSION)),$f['formats'],true)||($type==='image'&&!str_starts_with($file['mime'],'image/')))throw new HttpError(422,'پیوست '.$f['label'].' معتبر نیست.');$files[]=(int)$fid;}$v=array_map('intval',$v);
 }elseif(in_array($type,['select','multi'],true)){$map=array_column($f['options'],null,'value');foreach($type==='multi'?$v:($v!==''?[$v]:[]) as $opt){if(!is_scalar($opt)||!isset($map[$opt]))throw new HttpError(422,'گزینه '.$f['label'].' معتبر نیست.');$surcharge=StoreAccess::money($surcharge+(int)$map[$opt]['price']);}}
 elseif($type==='number'&&$v!==''){if(!is_numeric($v)||!is_finite((float)$v)||(isset($f['min'])&&(float)$v<(float)$f['min'])||(isset($f['max'])&&(float)$v>(float)$f['max']))throw new HttpError(422,'عدد '.$f['label'].' معتبر نیست.');}
 elseif($type==='date'&&$v!==''&&!preg_match('/^[0-9۰-۹]{4}[\/-][0-9۰-۹]{1,2}[\/-][0-9۰-۹]{1,2}$/uD',$v))throw new HttpError(422,'تاریخ '.$f['label'].' معتبر نیست.');
 if(is_string($v)&&mb_strlen($v)>$f['max_length'])throw new HttpError(422,'متن '.$f['label'].' طولانی است.');$values[$k]=$v;
 }return ['values'=>$values,'surcharge'=>$surcharge,'files'=>array_values(array_unique($files)),'form'=>['id'=>$form['id'],'revision'=>$form['revision'],'fields'=>$form['fields']]];
 }
}

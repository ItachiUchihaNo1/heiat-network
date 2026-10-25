<?php
use Namira\{ProtocolSpec,Protocol};
foreach($defs as $key=>$def):
$v=$values[$key]??null;$n=$prefix.'['.$key.']';$fieldId='p_'.preg_replace('/[^a-zA-Z0-9_]/','_',$n);$errKey=str_replace(['][','[',']'],['.','.',''],$n);$err=$errors[$errKey]??'';$type=$def['type'];$label=$def['label'];
?>
<div class="field <?= in_array($type,['date','place','textarea','rich','lines'],true)?'full':'' ?>" data-protocol-field>
<label for="<?= e($fieldId) ?>"><?= e($label) ?></label>
<?php if($type==='date'): $v=is_array($v)?$v:[]; ?>
<fieldset class="protocol-date"><legend class="sr-only"><?= e($label) ?></legend>
<select name="<?= e($n) ?>[calendar]" id="<?= e($fieldId) ?>" aria-label="تقویم"><?php foreach(['jalali'=>'شمسی','gregorian'=>'میلادی','hijri'=>'قمری'] as $k=>$t): ?><option value="<?= $k ?>" <?= ($v['calendar']??'jalali')===$k?'selected':'' ?>><?= $t ?></option><?php endforeach ?></select>
<select name="<?= e($n) ?>[precision]" aria-label="دقت تاریخ"><?php foreach(['unknown'=>'نامشخص یا فقط متن منبع','year'=>'فقط سال','month'=>'سال و ماه','day'=>'روز دقیق'] as $k=>$t): ?><option value="<?= $k ?>" <?= ($v['precision']??'unknown')===$k?'selected':'' ?>><?= $t ?></option><?php endforeach ?></select>
<?php foreach(['year'=>'سال','month'=>'ماه','day'=>'روز'] as $k=>$t): ?><input inputmode="numeric" name="<?= e($n) ?>[<?= $k ?>]" value="<?= e($v[$k]??'') ?>" placeholder="<?= $t ?>" aria-label="<?= $t ?>"><?php endforeach ?>
<label><input type="checkbox" name="<?= e($n) ?>[is_approximate]" value="1" <?= !empty($v['is_approximate'])?'checked':'' ?>> تقریبی</label>
<input name="<?= e($n) ?>[original_text]" value="<?= e($v['original_text']??'') ?>" placeholder="عبارت اصلی منبع" aria-label="عبارت اصلی منبع">
</fieldset><small class="hint">اجزای نامعلوم را خالی بگذارید؛ تاریخ بدون اجازه تبدیل نمی‌شود.</small>
<?php elseif($type==='place'): $v=is_array($v)?$v:[]; ?>
<fieldset class="protocol-place"><legend class="sr-only"><?= e($label) ?></legend><div class="fields">
<?php foreach(['country_id'=>['country','کشور'],'province_or_state_id'=>['province','استان / ایالت'],'county_id'=>['county','شهرستان'],'city_or_village_id'=>['city','شهر / روستا']] as $k=>$spec): ?>
<div class="field"><label for="<?= e($fieldId.'_'.$k) ?>"><?= $spec[1] ?></label><select data-geo-level="<?= $spec[0] ?>" id="<?= e($fieldId.'_'.$k) ?>" name="<?= e($n) ?>[<?= $k ?>]"><option value="">ثبت نشده</option><?php foreach($terms as $t): if($t['taxonomy']!==$spec[0])continue; ?><option data-parent="<?= e($t['parent_id']??'') ?>" value="<?= $t['id'] ?>" <?= (string)($v[$k]??'')===(string)$t['id']?'selected':'' ?>><?= e($t['title']) ?></option><?php endforeach ?></select></div>
<?php endforeach ?>
<?php foreach(['locality_text'=>'نام محل در منبع / مکان فاقد گزینه','historical_place_name'=>'نام تاریخی محل','address_text'=>'نشانی دقیق؛ خصوصی','latitude'=>'عرض جغرافیایی؛ خصوصی','longitude'=>'طول جغرافیایی؛ خصوصی'] as $k=>$t): ?><div class="field"><label for="<?= e($fieldId.'_'.$k) ?>"><?= $t ?></label><input id="<?= e($fieldId.'_'.$k) ?>" name="<?= e($n) ?>[<?= $k ?>]" value="<?= e($v[$k]??'') ?>"></div><?php endforeach ?>
</div><label><input type="checkbox" name="<?= e($n) ?>[needs_matching]" value="1" <?= !empty($v['needs_matching'])?'checked':'' ?>> نیازمند تطبیق با فرهنگ جغرافیایی</label>
<p class="hint">اگر گزینه مکان موجود نیست، نام محل را مطابق منبع بنویسید؛ مدیر می‌تواند فرهنگ را تکمیل کند.</p></fieldset>
<?php elseif(in_array($type,['textarea','rich','lines'],true)): ?>
<textarea id="<?= e($fieldId) ?>" name="<?= e($n) ?>" rows="<?= $type==='rich'?7:3 ?>"><?= e(is_array($v)?implode("\n",$v):($v??'')) ?></textarea>
<?php if($type==='rich'): ?><small class="hint">متن ساده یا HTML محدود: p، br، strong، em، ul، ol، li و blockquote. کد و ویژگی‌های ناامن حذف می‌شوند.</small><?php endif ?>
<?php elseif($type==='bool'): ?>
<input type="checkbox" id="<?= e($fieldId) ?>" name="<?= e($n) ?>" value="1" <?= $v?'checked':'' ?>>
<?php elseif($type==='enum'): ?>
<select id="<?= e($fieldId) ?>" name="<?= e($n) ?>" <?= $key==='identity_status'?'required':'' ?>><?php if($key==='identity_status'&&$v===null): ?><option value="" selected disabled>وضعیت هویت را انتخاب کنید</option><?php endif ?><?php foreach($def['options'] as $k=>$t): ?><option value="<?= e($k) ?>" <?= (!($key==='identity_status'&&$v===null)&&(string)($v??array_key_first($def['options']))===(string)$k)?'selected':'' ?>><?= e($t) ?></option><?php endforeach ?></select>
<?php elseif($type==='claim'): ?>
<input list="<?= e($fieldId) ?>_claims" dir="ltr" id="<?= e($fieldId) ?>" name="<?= e($n) ?>" value="<?= e($v??'') ?>" placeholder="identity / birth_date / collections"><datalist id="<?= e($fieldId) ?>_claims"><?php foreach(['identity','identity.display_name','identity.identification','birth_date','martyrdom_date','birth_place','martyrdom_place','occupation','rank','position','event','collections','official'] as $claim): ?><option value="<?= $claim ?>"></option><?php endforeach ?></datalist><small class="hint">کلید ادعا، مانند identity برای منبع معرفی یا collections.شناسه برای یک عنوان مشخص؛ رکورد مرتبط را هم می‌توانید انتخاب کنید.</small>
<?php elseif(in_array($type,['terms','term','source','event','record','affiliation'],true)): ?>
<?php $opts=[];if(in_array($type,['terms','term'],true)){foreach($terms as $t)if($t['taxonomy']===$def['options']['taxonomy']&&($t['active']||in_array($t['id'],(array)$v)))$opts[$t['id']]=$t['title'];}elseif(in_array($type,['source','event'],true)){foreach($type==='source'?$sources:$events as $t)$opts[$t['id']]='#'.$t['id'].' '.$t['title'];}elseif($type==='claim'){$opts=['identity'=>'معرفی / شناسایی هویت','identity.display_name'=>'نام نمایشی','birth_date'=>'تاریخ تولد','martyrdom_date'=>'تاریخ شهادت','birth_place'=>'محل تولد','martyrdom_place'=>'محل شهادت','occupation'=>'شغل / دانشمند','rank'=>'درجه','position'=>'سمت','event'=>'واقعه','collections'=>'مجموعه‌های موضوعی','official'=>'مستند رسمی'];}else{foreach($bundle['records']??[] as $kind=>$rows)foreach($rows as $row)if($type!=='affiliation'||$kind==='affiliation')$opts[$row['id']]='#'.$row['id'].' '.(ProtocolSpec::records(2)[$kind]??ProtocolSpec::records(3)[$kind]??ProtocolSpec::records(4)[$kind]??ProtocolSpec::records(5)[$kind]??$kind);}
?>
<input type="search" data-select-search="<?= e($fieldId) ?>" aria-label="جست‌وجو در <?= e($label) ?>" placeholder="جست‌وجوی گزینه‌ها…">
<select id="<?= e($fieldId) ?>" name="<?= e($n) ?><?= $type==='terms'?'[]':'' ?>" <?= $type==='terms'?'multiple size="6"':'' ?>><option value="" <?= $type==='terms'?'disabled':'' ?>>انتخاب نشده</option><?php foreach($opts as $k=>$t): ?><option value="<?= e($k) ?>" <?= in_array((string)$k,array_map('strval',(array)$v),true)?'selected':'' ?>><?= e($t) ?></option><?php endforeach ?></select>
<?php if(in_array($type,['terms','term'],true)): ?><input name="<?= e($prefix.'['.$key.'_other]') ?>" value="<?= e($values[$key.'_other']??'') ?>" placeholder="توضیح گزینه سایر" aria-label="توضیح سایر <?= e($label) ?>"><?php endif ?>
<?php if($type==='terms'): ?><small class="hint">چند گزینه مجاز است؛ در رایانه Ctrl را نگه دارید.</small><?php endif ?>
<?php elseif($type==='integer'): ?>
<input id="<?= e($fieldId) ?>" type="text" inputmode="numeric" name="<?= e($n) ?>" value="<?= e($v??'') ?>">
<?php else: ?>
<input id="<?= e($fieldId) ?>" name="<?= e($n) ?>" value="<?= e($v??'') ?>" <?= $type==='url'?'type="url" dir="ltr"':'' ?>>
<?php endif ?>
<?php if($err): ?><p class="protocol-error" role="alert"><?= e($err) ?></p><?php endif ?>
<?php if(!in_array($type,['bool','enum'],true)): ?><label class="hint">وضعیت اطلاعات <select class="protocol-state" name="<?= e($prefix.'[states]['.$key.']') ?>"><?php foreach(['recorded'=>'مطابق مقدار ثبت‌شده','missing'=>'هنوز ثبت نشده','unknown'=>'نامشخص پس از بررسی','not_applicable'=>'کاربرد ندارد'] as $k=>$t): ?><option value="<?= $k ?>" <?= ($values['states'][$key]??'recorded')===$k?'selected':'' ?>><?= $t ?></option><?php endforeach ?></select></label><?php endif ?>
</div>
<?php endforeach ?>

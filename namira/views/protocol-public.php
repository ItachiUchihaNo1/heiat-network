<?php
use Namira\{ProtocolSpec,ProtocolDates};
$s=$snapshot['sections']??[];$identity=$s[1]??[];$terms=$snapshot['terms']??[];
$render=function($value,array $def)use($terms,$snapshot):string{
    if($value===null||$value===''||$value===[]||$value===false)return '';
    $type=$def['type'];
    if($type==='date')return e(ProtocolDates::label($value));
    if($type==='bool')return $value?'بله':'';
    if($type==='enum')return e($def['options'][$value]??$value);
    if($type==='place'){$out=[];foreach(['country_id','province_or_state_id','county_id','city_or_village_id'] as $k)if(!empty($value[$k]))$out[]=$terms[$value[$k]]??'';foreach(['locality_text','historical_place_name','village_name'] as $k)if(!empty($value[$k]))$out[]=$value[$k];return e(implode('، ',array_filter($out)));}
    if(in_array($type,['terms','term'],true)){return e(implode('، ',array_filter(array_map(fn($id)=>$terms[$id]??'',(array)$value))));}
    if($type==='source')return e($snapshot['sources'][$value]['title']??'منبع ثبت‌شده');
    if($type==='event')return e($snapshot['events'][$value]['title']??'واقعه ثبت‌شده');
    if(in_array($type,['record','affiliation'],true))return '#'.e($value);
    if($type==='url')return '<a rel="noopener noreferrer" href="'.e($value).'">'.e($value).'</a>';
    if($type==='rich')return Namira\ProtocolValidator::safeHtml((string)$value);
    if($type==='lines')return e(implode('، ',$value));
    return nl2br(e((string)$value));
};
?>
<div class="heading"><h2><?= e($snapshot['display_name']??'') ?></h2><a class="outline" href="<?= e(url('gallery')) ?>">آرشیو تصاویر</a></div>
<div class="profile"><aside><?php if(!empty($images)): ?><img src="<?= e(url('media',['id'=>$images[0]['id']])) ?>" alt="<?= e($snapshot['display_name']) ?>"><?php endif ?><h2>شهید <?= e($snapshot['display_name']) ?></h2><p class="prose"><?= e($identity['short_bio']??'') ?></p><?php if(!empty($snapshot['archive_code'])): ?><p>کد آرشیو: <?= e($snapshot['archive_code']) ?></p><?php endif ?><p>شناسه ثابت: <?= fa($profileId??0) ?></p><?php if(!empty($s[2])): ?><p>سن محاسبه‌شده: <?= e(ProtocolDates::age($s[2]['birth_date']??[],$s[2]['martyrdom_date']??[])) ?></p><?php endif ?></aside>
<section><h2>تصاویر و آثار</h2><?php if(!empty($images)): view('profile-media',['images'=>$images,'profileId'=>$profileId,'personTypes'=>$personTypes??[],'selectedType'=>$selectedType??0]); else: ?><p class="hint">هنوز تصویری برای این پرونده منتشر نشده است.</p><?php endif ?>
<?php foreach(ProtocolSpec::STEPS as $stepNumber=>$stepTitle):if($stepNumber===8)continue; ?>
<details <?= $stepNumber===1?'open':'' ?>><summary><?= e($stepTitle) ?></summary>
<dl><?php foreach(array_replace(ProtocolSpec::fields($stepNumber),Namira\SimpleMartyrForm::additions($stepNumber),$stepNumber===7?['will_text'=>ProtocolSpec::field('وصیت‌نامه','textarea'),'memories'=>ProtocolSpec::field('خاطرات','textarea')]:[]) as $k=>$def):if(in_array($k,['display_name','short_bio','archive_code','collections_note','service_notes','official_note'],true)||!array_key_exists($k,$s[$stepNumber]??[]))continue;$html=$render($s[$stepNumber][$k],$def);if($html==='')continue; ?><dt><b><?= e(str_replace(['؛ پیش‌فرض خصوصی','؛ نشانی دقیق خصوصی'],'',$def['label'])) ?></b></dt><dd class="prose"><?= $html ?></dd><?php endforeach ?></dl>
<?php foreach(ProtocolSpec::records($stepNumber) as $kind=>$label):foreach($snapshot['records'][$kind]??[] as $record): ?><article class="panel"><h3><?= e($label) ?></h3><dl><?php foreach(ProtocolSpec::fields($kind) as $k=>$def):$html=$render($record['data'][$k]??null,$def);if($html==='')continue; ?><dt><b><?= e($def['label']) ?></b></dt><dd class="prose"><?= $html ?></dd><?php endforeach ?></dl></article><?php endforeach;endforeach ?>
</details><?php endforeach ?>
<?php if(!empty($snapshot['events'])): ?><details><summary>شرح وقایع مرتبط</summary><?php foreach($snapshot['events'] as $ev): ?><article><h3><?= e($ev['title']) ?></h3><dl><?php foreach(ProtocolSpec::fields('event') as $k=>$def):$html=$render($ev['data'][$k]??null,$def);if($html==='')continue; ?><dt><?= e($def['label']) ?></dt><dd><?= $html ?></dd><?php endforeach ?></dl><?php foreach($ev['data']['locations']??[] as $loc): ?><p><?= e($loc['label']??'') ?>: <?= $render($loc['place']??[],ProtocolSpec::field('مکان','place')) ?></p><?php endforeach ?></article><?php endforeach ?></details><?php endif ?>
<?php if(!empty($snapshot['sources'])): ?><details><summary>منابع</summary><?php foreach($snapshot['sources'] as $source): ?><article><h3><?= e($source['title']) ?></h3><dl><?php foreach(ProtocolSpec::fields('source') as $k=>$def):$html=$render($source['data'][$k]??null,$def);if($html==='')continue; ?><dt><?= e($def['label']) ?></dt><dd><?= $html ?></dd><?php endforeach ?></dl></article><?php endforeach ?></details><?php endif ?>
<?php foreach(['will_text'=>'وصیت‌نامه ثبت‌شده قبلی','memories'=>'خاطرات ثبت‌شده قبلی'] as $k=>$label): if(empty($snapshot['legacy_text'][$k]))continue; ?><details><summary><?= e($label) ?></summary><p class="prose"><?= nl2br(e($snapshot['legacy_text'][$k])) ?></p></details><?php endforeach ?>
</section></div>

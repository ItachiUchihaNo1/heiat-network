<?php use Namira\{MartyrTaxonomySchema};if(!MartyrTaxonomySchema::ready($db))return;
$groups=['thematic'=>[],'geography'=>[],'other'=>[]];$otherCount=0;
foreach($catalogFilters['axes'] as $axis){$group=in_array($axis['filter_group'],['thematic','geography'],true)?$axis['filter_group']:'other';$groups[$group][]=$axis;if($group==='other')$otherCount+=count(array_filter((array)($f['axis'][$axis['axis_key']]??[]),fn($v)=>$v!==''));}
if(($f['year_from']??'')!==''||($f['year_to']??'')!=='')$otherCount++;
?>
<link rel="stylesheet" href="/assets/protocol.css?v=20261027-groups"><script defer src="/assets/protocol.js?v=20261027-005"></script>
<div class="gallery-primary-filters">
<?php foreach(['thematic'=>'فیلترهای موضوعی','geography'=>'فیلترهای جغرافیایی'] as $key=>$title):$axes=$groups[$key];$selected=false;foreach($axes as $axis)if(array_filter((array)($f['axis'][$axis['axis_key']]??[]),fn($v)=>$v!==''))$selected=true;if($key==='geography')foreach(['country','province','county','city'] as $level)if(($f['geo_'.$level]??'')!=='')$selected=true; ?>
<details class="gallery-main-group" data-gallery-group="<?= $key ?>" <?= $selected?'open':'' ?>><summary><?= $title ?></summary><div class="gallery-group-body">
<?php if($key==='geography'):view('gallery-geography-filters',compact('catalogFilters','f'));elseif(!$axes): ?><p class="hint">محور موضوعی فعالی تعریف نشده است.</p><?php endif ?>
<?php view('gallery-axis-filters',compact('axes','db','f')); ?>
</div></details><?php endforeach ?>
</div>
<details class="gallery-more-filters" data-gallery-more><summary><span class="more-closed">نمایش سایر فیلترها</span><span class="more-open">بستن سایر فیلترها</span><?php if($otherCount): ?><small> · <?= fa($otherCount) ?> انتخاب فعال</small><?php endif ?></summary><div class="gallery-group-body"><?php $axes=$groups['other'];view('gallery-axis-filters',compact('axes','db','f'));view('gallery-date-filter',compact('f')); ?></div></details>

<?php
$socials=[];
foreach(array_merge(!empty($fields['website'])?[$fields['website']]:[],$fields['social_links']??[]) as $value){
 if(!is_string($value)||!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($value,PHP_URL_SCHEME)??''),['http','https'],true))continue;
 $host=strtolower(parse_url($value,PHP_URL_HOST)??'');$host=preg_replace('/^www\./','',$host);$kind='globe';$label='وب‌سایت';
 foreach(['instagram.com'=>['instagram','اینستاگرام'],'eitaa.com'=>['eitaa','ایتا'],'t.me'=>['telegram','تلگرام'],'telegram.me'=>['telegram','تلگرام'],'ble.ir'=>['bale','بله'],'bale.ai'=>['bale','بله'],'wa.me'=>['whatsapp','واتساپ'],'whatsapp.com'=>['whatsapp','واتساپ'],'aparat.com'=>['video','آپارات'],'youtube.com'=>['video','یوتیوب']] as $domain=>$info)if($host===$domain||str_ends_with($host,'.'.$domain)){[$kind,$label]=$info;break;}
 $socials[$value]=[$kind,$label];
}
?>
<?php if($socials): ?><nav class="artist-socials" aria-label="راه‌های ارتباطی هنرمند"><?php foreach($socials as $href=>[$icon,$label]): ?><a class="nm-social-icon" href="<?= e($href) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"><?= Namira\MobileNavigation::icon($icon) ?></a><?php endforeach ?></nav><?php endif ?>

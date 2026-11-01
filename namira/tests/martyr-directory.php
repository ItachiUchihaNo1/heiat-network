<?php
declare(strict_types=1);
require __DIR__.'/home-showcase.php';
use Namira\{Db,Archive,MartyrDirectory as D,GalleryFacets as F,Media,AdminPublishing,Upload,HomeShowcase as H,MobileNavigation};
set_error_handler(static function($n,$s,$f,$l){if(error_reporting()&$n)throw new ErrorException($s,0,$n,$f,$l);});
try{
 ok((new Archive($db))->images(['q'=>'اثر عمومی'])['count']===1&&F::count($db,['q'=>'اثر عمومی'])===1,'gallery search matches published image title and facet count');
 ok(D::listing($db,['q'=>'اثر عمومی'])['count']===0,'martyr search never searches unrelated image metadata');
 $baseline=(new Archive($db))->images([])['count'];$noPhoto=$a->save(['code'=>'000125','first_name'=>'شهید','last_name'=>'بدون تصویر','published'=>1,'city'=>'شهر قدیمی']);
 $rows=D::listing($db);ok($rows['count']===2&&in_array($noPhoto,array_column($rows['items'],'id')),'directory includes published people without pictures and excludes drafts');
 ok(D::listing($db,['q'=>'بدون تصویر'])['count']===1,'directory searches full names');
 ok(D::listing($db,['q'=>'ناشناخته'])['count']===0,'directory empty state has zero count');
 ok(D::listing($db,[],2,1)['count']===2&&count(D::listing($db,[],2,1)['items'])===1,'directory pagination counts people');
 $topic=(int)Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='collection' AND active=1 LIMIT 1")->fetchColumn();
 $job=(int)Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='occupation' AND active=1 LIMIT 1")->fetchColumn();
 if(!$job)$job=(int)Db::query($db,"SELECT t.id FROM protocol_terms t JOIN martyr_taxonomy_axes a ON a.axis_key=t.taxonomy WHERE a.public_filter=1 AND a.axis_key<>'collection' LIMIT 1")->fetchColumn();
 $jobKey=(string)Db::query($db,'SELECT taxonomy FROM protocol_terms WHERE id=?',[$job])->fetchColumn();
 if(!$topic||!$job)throw new RuntimeException('Reference fixture terms required');
 foreach([[$person,'collection',$topic],[$noPhoto,'collection',$topic],[$person,$jobKey,$job]] as [$p,$axis,$term])Db::query($db,'DELETE FROM martyr_public_terms WHERE martyr_id=? AND axis_key=? AND term_id=?',[$p,$axis,$term]);
 foreach([[$person,'collection',$topic],[$noPhoto,'collection',$topic],[$person,$jobKey,$job]] as [$p,$axis,$term])Db::insert($db,'martyr_public_terms',['martyr_id'=>$p,'axis_key'=>$axis,'term_id'=>$term,'revision'=>1]);
 $f=['axis'=>['collection'=>[(string)$topic],$jobKey=>[(string)$job]]];
 ok(D::listing($db,$f)['count']===1&&F::count($db,$f,true)===1,'independent directory axes combine with AND');
 ok(F::count($db,[],true)===2&&F::count($db,[])===$baseline,'directory facets count people while gallery still counts images');
 $snap=F::response($db,['axis'=>['collection'=>[(string)$topic]]],'',true,true);ok($snap['count']===2&&str_contains($snap['url'],'page=martyrs')&&str_contains($snap['html'],'hs-martyr-grid')&&str_contains($snap['tags'],'page=martyrs'),'AJAX response uses directory cards tags URLs and count');
 $country=(int)Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='country' AND active=1 LIMIT 1")->fetchColumn();
 $city=(int)Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='city' AND active=1 LIMIT 1")->fetchColumn();
 if(!$city)$city=Db::insert($db,'protocol_terms',['taxonomy'=>'city','title'=>'شهر آزمون','slug'=>'directory-city','active'=>1]);
 Db::insert($db,'martyr_public_facts',['martyr_id'=>$noPhoto,'fact_key'=>'birth_place','country_id'=>$country,'city_id'=>$city,'revision'=>1]);
 ok(F::count($db,['geo_role'=>'birth_place','geo_country'=>(string)$country,'geo_city'=>(string)$city],true)===1,'directory geography filters one published person without requiring a photo');
 ok(F::count($db,['geo_role'=>'martyrdom_place','geo_country'=>(string)$country,'geo_city'=>(string)$city],true)===0,'geographic roles never substitute birth location for martyrdom');
 ok(in_array($city,array_map('intval',array_column(F::geo($db,['geo_country'=>(string)$country],'birth_place',true)['city'],'id')),true),'dependent geographic choices include valid directory results');
 $card=D::card($db,$a->find($noPhoto));ok($card['cover']===null&&$card['card_city']==='شهر قدیمی'&&count($card['topics'])===1,'card preserves legacy city and public topic without fake photo');
 $canvas=imagecreatetruecolor(240,360);imagepng($canvas,$dir.'/second.png');imagedestroy($canvas);$second=Upload::attach($db,$person,$dir.'/second.png','000123-2.png',['title'=>'شاخص تازه']);AdminPublishing::publish($db,$actor,'media',$second);
 $media=new Media($db,$actor);$v=$media->get($second);$v['martyrs']=[$person,$noPhoto];$v['featured_for']=$person;$media->save($second,$v);AdminPublishing::publish($db,$actor,'media',$second);
 ok(D::card($db,$a->find($person))['cover']['id']===$second,'admin selected feature replaces previous default');
 $v=$media->get($second);$v['featured_for']=$noPhoto;$media->save($second,$v);AdminPublishing::publish($db,$actor,'media',$second);
 $v=$media->get($second);$v['featured_for']='';$v['title']='ویرایش عنوان';$media->save($second,$v);AdminPublishing::publish($db,$actor,'media',$second);
 ok(count($media->get($second)['featured_people'])===2,'metadata save preserves features for all linked people');
 $v=$media->get($image);$v['featured_for']=$person;$media->save($image,$v);AdminPublishing::publish($db,$actor,'media',$image);
 ok((int)Db::query($db,'SELECT SUM(is_featured) FROM media_martyrs WHERE martyr_id=?',[$person])->fetchColumn()===1&&(int)Db::query($db,'SELECT SUM(is_featured) FROM media_public_martyrs WHERE martyr_id=?',[$person])->fetchColumn()===1,'changing feature leaves one draft and one public featured image per person');
 ok(D::card($db,$a->find($person))['cover']['id']===$image&&D::card($db,$a->find($noPhoto))['cover']['id']===$second,'changing one person feature preserves other person feature');
 Db::query($db,'UPDATE images SET published=0 WHERE id=?',[$image]);
 ok(D::card($db,$a->find($person))['cover']['id']===$second,'hidden feature falls back to a public linked image');
 Db::query($db,'UPDATE images SET published=1 WHERE id=?',[$image]);
 $home=H::content($db,'martyrs',[$person])[0];ok($home['cover']['id']===D::card($db,$a->find($person))['cover']['id'],'homepage and directory share featured-image presentation');
 $sections=H::sections($db);$page='home';ob_start();view('home-showcase',compact('db','sections','page'));$html=ob_get_clean();ok(str_contains($html,'page=martyrs'),'homepage more link leads to independent directory');
 $nav=MobileNavigation::items('martyrs',false,true);ok(in_array('martyrs',array_column(array_merge(...array_column($nav['groups'],'items')),'page'))&&count($nav['bottom'])===5,'mobile drawer adds martyrs and retains five bottom destinations');
 $f=[];$catalogFilters=F::options($db,[],true);$result=D::listing($db);ob_start();view('martyrs',compact('db','f','catalogFilters','result'));$html=ob_get_clean();ok(!str_contains($html,'martyr-directory-search')&&str_contains($html,'data-gallery-dialog')&&!str_contains($html,'Warning:'),'directory renders shared filter sheet and search without PHP warnings');
 if(str_starts_with($dsn,'sqlite:')){$fixture=json_decode(file_get_contents(BASE_PATH.'/storage/home-showcase-fixture.json'),true);$fixture['topic']=$topic;$fixture['directoryCount']=2;file_put_contents(BASE_PATH.'/storage/home-showcase-fixture.json',json_encode($fixture));}
 echo ($checks-37)." directory checks passed
";
}finally{restore_error_handler();}

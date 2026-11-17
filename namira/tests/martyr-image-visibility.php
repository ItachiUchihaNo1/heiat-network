<?php
declare(strict_types=1);
require __DIR__.'/gallery-facets.php';
use Namira\{MartyrDirectory,PublicSearch,PublicFeed,AdminQueries,CleanUrls,Db,Archive,GalleryFacets,Upload};
try {
$start=$checks;
function listed(int $id):bool {global $db;return MartyrDirectory::listing($db,['martyr_id'=>$id])['count']===1;}
ok(!listed($noImage),'name-only public martyr is excluded');
Db::query($db,'UPDATE martyrs SET biography=?,will_text=?,memories=?,summary=? WHERE id=?',['زندگی‌نامه کامل','وصیت‌نامه کامل','خاطرات کامل','اطلاعات کامل',$noImage]);
ok(!listed($noImage),'complete biography will and memories never qualify without an image');
ok(listed($photoOnly),'one published original photo qualifies without any design');
ok(listed($personA),'published design qualifies');ok(listed($personB),'several/shared published images produce one martyr');
ok(!listed($draftPerson),'non-public image does not qualify');
foreach(['pending','rejected','draft'] as $state){Db::query($db,'UPDATE media_details SET status=? WHERE image_id=?',[$state,$draftImage]);ok(!listed($draftPerson),'unpublished '.$state.' media does not qualify');}
$galleryBefore=(new Archive($db))->images([])['count'];
Namira\AdminPublishing::media($db,$actor,$draftImage);ok(listed($draftPerson),'publishing first image immediately adds the martyr');
ok(GalleryFacets::count($db,['martyr_id'=>$draftPerson],true)===1,'count updates after first publication');
Namira\AdminPublishing::visibility($db,$actor,'media',$draftImage,'archived',(int)Db::query($db,'SELECT revision FROM media_details WHERE image_id=?',[$draftImage])->fetchColumn());ok(!listed($draftPerson),'archiving last public image immediately removes the martyr');
$publicBefore=GalleryFacets::count($db,[],true);$allAdminBefore=(int)$db->query('SELECT COUNT(*) FROM martyrs')->fetchColumn();
ok((new Archive($db))->find($noImage)!==false,'published direct profile remains accessible');
ok((new Archive($db))->find($noImage,false)!==false,'admin upload can still resolve no-image martyr');
ok(in_array($noImage,ids(AdminQueries::martyrs($db,['q'=>'facet_none'])['items']),true),'admin listing and search retain no-image martyr');
CleanUrls::bind($db);ok(CleanUrls::publicExists('profile',$noImage),'canonical direct profile policy is unchanged');
$jobOptions=GalleryFacets::options($db,[],true);ok(!in_array($noImageJob,axisOptions($jobOptions,'occupation'),true),'people filter options exclude text-only values');ok(!in_array($emptyTopic,axisOptions($jobOptions,'collection'),true),'people thematic filter excludes no-image-only topics');ok(in_array($photoJob,axisOptions($jobOptions,'occupation'),true),'people options include original-photo-only values');
Db::insert($db,'martyr_public_facts',['martyr_id'=>$noImage,'fact_key'=>'birth_place','ordinal'=>0,'revision'=>1,'country_id'=>$unused['country_id'],'province_id'=>$unused['province_or_state_id'],'county_id'=>$unused['county_id'],'city_id'=>$unused['city_or_village_id']]);
$geo=GalleryFacets::geo($db,[],'birth_place',true);ok(!in_array($unused['country_id'],ids($geo['country']),true),'geographic options ignore image-less birthplaces');
ok(MartyrDirectory::listing($db,['axis'=>['collection'=>[$emptyTopic]]])['count']===0,'thematic results obey image requirement');
ok(MartyrDirectory::listing($db,['geo_role'=>'birth_place','geo_country'=>(string)$unused['country_id']])['count']===0,'geographic results obey image requirement');
ok(MartyrDirectory::listing($db,['q'=>'facet_none'])['count']===0,'name/archive search never bypasses image requirement');
$r=PublicSearch::advancedResponse($db,['results'=>'martyrs','q'=>'facet_none'],true);ok($r['count']===0&&$r['result']['count']===0&&!$r['result']['items'],'advanced people count and results agree');
$r=PublicSearch::basicResponse($db,['mode'=>'results','q'=>'facet_none'],true);ok($r['count']===0&&!str_contains($r['html'],'data-card-id='),'basic people AJAX has no hidden placeholder cards');
$probe=routeProbe(['page'=>'martyrs-data','simple'=>'1','mode'=>'results','q'=>'facet_none']);ok($probe['status']===200&&$probe['data']['count']===0,'actual HTTP people JSON uses server-side condition');
Db::insert($db,'media_martyrs',['image_id'=>$imageA,'martyr_id'=>$noImage,'is_featured'=>0]);ok(!listed($noImage),'draft-only relationship to a public image does not qualify');
Db::insert($db,'media_public_martyrs',['image_id'=>$imageA,'martyr_id'=>$noImage,'is_featured'=>0]);ok(listed($noImage),'approved shared image relation qualifies its secondary martyr');Db::query($db,'DELETE FROM media_public_martyrs WHERE image_id=? AND martyr_id=?',[$imageA,$noImage]);ok(!listed($noImage),'removing final approved image association removes martyr');
$single=person('visibility_single',$teacher,['birth_place'=>$a],$topic);$singleImage=photo($single,96,[],'document');ok(listed($single),'published historical/document image qualifies if it is an actual image');
$original=Db::query($db,'SELECT mime,width,height,bytes,thumb_path FROM images WHERE id=?',[$singleImage])->fetch();
foreach(['mime'=>'application/pdf','width'=>0,'height'=>0,'bytes'=>0,'thumb_path'=>''] as $field=>$value){Db::query($db,'UPDATE images SET '.$field.'=? WHERE id=?',[$value,$singleImage]);ok(!listed($single),'invalid image metadata excluded: '.$field);Db::query($db,'UPDATE images SET '.$field.'=? WHERE id=?',[$original[$field],$singleImage]);}
Db::query($db,'UPDATE media_publication SET download_allowed=0 WHERE image_id=?',[$singleImage]);ok(listed($single),'publicly visible image qualifies even when original download is restricted');
$pub=Db::query($db,'SELECT * FROM media_publication WHERE image_id=?',[$singleImage])->fetch();Db::query($db,'DELETE FROM media_publication WHERE image_id=?',[$singleImage]);ok(!listed($single),'missing public publication cannot qualify');Db::insert($db,'media_publication',$pub);
$invalidNewer=photo($single,97,[],'document');Db::query($db,'UPDATE images SET mime=? WHERE id=?',['application/pdf',$invalidNewer]);$card=MartyrDirectory::listing($db,['martyr_id'=>$single])['items'][0];ok((int)$card['cover']['id']===$singleImage,'batched cover chooses a valid public image rather than malformed newer media');
$actual=MartyrDirectory::listing($db);ok($actual['count']===GalleryFacets::count($db,[],true),'directory and facet counts use identical eligibility');
$base=['route'=>'martyrs','max'=>(string)$db->query('SELECT MAX(id) FROM martyrs')->fetchColumn()];$seen=[];$after=0;$page=1;
do{$params=$base+['batch'=>(string)$page];if($after)$params['after']=(string)$after;$r=PublicFeed::response($db,$params);ok(!array_intersect($seen,$r['ids']),'cursor contains no duplicates');$seen=[...$seen,...$r['ids']];$after=$r['after']??0;$page++;}while($r['more']&&$page<20);
ok(count($seen)===$actual['count']&&!in_array($noImage,$seen,true)&&!in_array($draftPerson,$seen,true),'infinite scroll includes exactly image-eligible people');
$r=PublicFeed::response($db,['route'=>'advanced-search','results'=>'martyrs','max'=>$base['max'],'batch'=>'1','q'=>'facet_none']);ok(!$r['ids']&&!$r['more'],'advanced infinite scroll cannot leak no-image results');
ok((int)$db->query('SELECT COUNT(*) FROM martyrs')->fetchColumn()===$allAdminBefore+1,'no martyr record was deleted by visibility policy');
ok((new Archive($db))->images([])['count']===$galleryBefore,'default image gallery results remain unchanged by directory-only condition');
ok(hash_file('sha256',Upload::absolute(Db::query($db,'SELECT original_path FROM images WHERE id=?',[$imageA])->fetchColumn()))===$hash,'original file bytes remain unchanged');
[$scope,$params]=MartyrDirectory::conditions($db);$explain=Db::query($db,($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'EXPLAIN ':'EXPLAIN QUERY PLAN ').'SELECT m.id FROM martyrs m WHERE '.$scope,$params)->fetchAll();ok((bool)$explain,'database can plan the correlated indexed EXISTS');
echo ($checks-$start)." martyr image visibility checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
}catch(Throwable $e){while(ob_get_level())ob_end_clean();fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}

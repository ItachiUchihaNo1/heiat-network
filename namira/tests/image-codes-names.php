<?php
declare(strict_types=1);
require __DIR__.'/media-upload.php';
use Namira\{ImageCode,MartyrSuggestions,Protocol,AdminPublishing,Upload,Db,AdminQueries};
$more=0;function checkNew(bool $value,string $message):void{global $more;if(!$value)throw new RuntimeException('FAIL '.$message);$more++;echo 'PASS '.$message.PHP_EOL;}
checkNew(ImageCode::format(12)==='000012'&&ImageCode::format(13)==='000013','image codes contain six digits');
checkNew(ImageCode::format(1000000)==='1000000','large image codes never wrap');
$saved=Db::query($db,'SELECT id,original_path,sha256 FROM images WHERE id=?',[$ids[0]])->fetch();
$fileA=uploadFixture(201);$idA=AdminPublishing::upload($db,$actor,$fileA,'نام آزاد.jpg',$normal);$fileB=uploadFixture(202);$idB=AdminPublishing::upload($db,$actor,$fileB,'نام آزاد دوم.jpg',$normal);
checkNew((int)ImageCode::format($idB)===(int)ImageCode::format($idA)+1,'successive uploads receive successive codes');
checkNew(Db::query($db,'SELECT id,original_path,sha256 FROM images WHERE id=?',[$ids[0]])->fetch()===$saved,'old image identity and original file stay unchanged');
checkNew(in_array($idA,array_map('intval',array_column(AdminQueries::media($db,['q'=>ImageCode::format($idA)])['items'],'id')),true),'image code is searchable in admin');
$service=new Protocol($db,$actor);$one=AdminPublishing::save($db,$actor,'martyr',fn()=>$service->quick(['first_name'=>'علی','last_name'=>'همنام']));$two=AdminPublishing::save($db,$actor,'martyr',fn()=>$service->quick(['first_name'=>'علی','last_name'=>'همنام']));
checkNew($one!==$two,'homonyms may create independent dossiers without archive code');
checkNew(Db::query($db,'SELECT code FROM martyrs WHERE id=?',[$one])->fetchColumn()!==Db::query($db,'SELECT code FROM martyrs WHERE id=?',[$two])->fetchColumn(),'internal dossier keys remain unique');
Db::query($db,'UPDATE martyrs SET father_name=?,city=? WHERE id=?',['پدر اول','شیراز',$one]);Db::query($db,'UPDATE martyrs SET father_name=?,city=? WHERE id=?',['پدر دوم','قزوین',$two]);
$matches=MartyrSuggestions::search($db,$actor,'علی','همنام');$found=array_map('intval',array_column($matches,'id'));
checkNew(in_array($one,$found,true)&&in_array($two,$found,true),'both same-name people appear as suggestions');
checkNew(in_array('شیراز',array_column($matches,'location'),true)&&in_array('قزوین',array_column($matches,'location'),true),'known places help distinguish homonyms');
checkNew(!in_array($one,array_map('intval',array_column(MartyrSuggestions::search($db,$actor,'علی','همنام',$one),'id')),true),'editing excludes current person');
checkNew(MartyrSuggestions::search($db,$actor,'%','_')===[],'LIKE wildcard characters are literal');
uploadDenied(fn()=>MartyrSuggestions::search($db,false,'علی','همنام'),403,'anonymous suggestions rejected');
Db::query($db,'UPDATE martyrs SET first_name=?,last_name=? WHERE id=?',['كريم','ياري',$two]);checkNew(in_array($two,array_map('intval',array_column(MartyrSuggestions::search($db,$actor,'کریم','یاری'),'id')),true),'Arabic and Persian letter variants match');
ob_start();view('protocol',['id'=>0,'panel'=>'','errors'=>[],'old'=>[],'bundle'=>null,'actor'=>$actor]);$quickHtml=ob_get_clean();$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8">'.$quickHtml);$xpath=new DOMXPath($dom);
checkNew($xpath->query('//input[@name="code" and not(@required)]')->length===1,'quick form no longer requires martyr archive code');
checkNew($xpath->query('//input[@name="first_name" and @required]')->length===1&&$xpath->query('//input[@name="last_name" and @required]')->length===1,'name and surname remain required');
checkNew(str_contains($quickHtml,'data-martyr-suggestions')&&str_contains($quickHtml,'data-similar-results'),'quick form exposes nonblocking suggestions');
checkNew(Namira\DomainRouting::adminPage('martyr-similar'),'name search endpoint belongs to admin surface');
echo $more." image code/name checks passed.\n";

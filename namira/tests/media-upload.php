<?php
declare(strict_types=1);
require __DIR__.'/admin-direct.php';
// Reuse only the isolated SQLite/MySQL fixtures, never production credentials.
$uploadChecks=0;
function uploadOk(bool $value,string $label):void{global $uploadChecks;if(!$value)throw new RuntimeException('FAIL '.$label);$uploadChecks++;echo "PASS $label\n";}
function uploadDenied(callable $work,int $status,string $label):void{try{$work();}catch(Namira\HttpError $e){uploadOk($e->status===$status,$label);return;}throw new RuntimeException('Expected rejection '.$label);}
function uploadFixture(int $color,string $format='jpg'):string{global $root;$file=$root.'/manual-'.$color.'.'.$format;$canvas=imagecreatetruecolor(44,65);imagefill($canvas,0,0,imagecolorallocate($canvas,$color,30,80));match($format){'png'=>imagepng($canvas,$file),'webp'=>imagewebp($canvas,$file),default=>imagejpeg($canvas,$file)};imagedestroy($canvas);return $file;}
$normal=['upload_mode'=>'normal','upload_martyr_id'=>$person,'upload_artist_id'=>$artist];
$beforeCounts=Namira\DataBackup::counts($db);
foreach([['upload_mode'=>'normal'],array_replace($normal,['upload_martyr_id'=>999999]),array_replace($normal,['upload_martyr_id'=>[$person]]),array_replace($normal,['upload_artist_id'=>999999]),array_replace($normal,['upload_artist_id'=>[$artist]]),['upload_mode'=>'unknown']] as $bad)uploadDenied(fn()=>Namira\AdminPublishing::uploadSelection($db,$bad),422,'invalid selection rejected before storing a file');
uploadOk($beforeCounts===Namira\DataBackup::counts($db),'invalid choices preserve all table counts');
$ids=[];
foreach(['jpg','png','webp'] as $i=>$format){$file=uploadFixture(110+$i,$format);$id=Namira\AdminPublishing::upload($db,$actor,$file,'نام دلخواه '.$i.'.'.$format,$normal);$ids[]=$id;
uploadOk((int)Namira\Db::query($db,'SELECT martyr_id FROM media_public_martyrs WHERE image_id=?',[$id])->fetchColumn()===$person,'manual arbitrary filename gets selected martyr '.$format);
uploadOk((int)Namira\Db::query($db,'SELECT artist_id FROM media_public_artists WHERE image_id=?',[$id])->fetchColumn()===$artist,'manual images get stable artist relationship '.$format);
uploadOk(hash_file('sha256',$file)===hash_file('sha256',Namira\Upload::absolute((string)Namira\Db::query($db,'SELECT original_path FROM images WHERE id=?',[$id])->fetchColumn())),'original bytes preserved '.$format);
}
$file=uploadFixture(130);$optional=Namira\AdminPublishing::upload($db,$actor,$file,'بدون هنرمند.jpeg',array_replace($normal,['upload_artist_id'=>'']));
uploadOk(!(new Namira\Media($db,$actor))->get($optional)['creators'],'artist is optional');
$old=Namira\DataBackup::counts($db);uploadDenied(fn()=>Namira\AdminPublishing::upload($db,$actor,uploadFixture(110),'تکراری.jpg',array_replace($normal,['upload_martyr_id'=>$draft])),409,'duplicate cannot silently reassign existing image');uploadOk($old===Namira\DataBackup::counts($db),'duplicate changes no rows');
uploadDenied(fn()=>Namira\AdminPublishing::upload($db,$member,uploadFixture(131),'random.jpg',$normal),403,'member cannot upload through admin facade');
uploadDenied(fn()=>Namira\AdminPublishing::upload($db,$actor,uploadFixture(132),'random.exe',$normal),422,'manual format extension checked on server');
uploadDenied(fn()=>Namira\AdminPublishing::upload($db,$actor,__FILE__,'fake.jpg',$normal),422,'fake image content rejected');
$bulk=['upload_mode'=>'bulk','upload_martyr_id'=>$draft,'upload_artist_id'=>999999];
$bulkId=Namira\AdminPublishing::upload($db,$actor,uploadFixture(133),'000D1-900.jpg',$bulk);
uploadOk((int)Namira\Db::query($db,'SELECT martyr_id FROM media_public_martyrs WHERE image_id=?',[$bulkId])->fetchColumn()===$person,'bulk uses filename code and ignores manual controls');
uploadDenied(fn()=>Namira\AdminPublishing::upload($db,$actor,uploadFixture(134),'UNKNOWN-1.jpg',$bulk),422,'unknown archive code is not misassigned');
uploadDenied(fn()=>Namira\AdminPublishing::upload($db,$actor,uploadFixture(135),'plain.jpg',$bulk),422,'bulk requires archive-code filename');
$legacyId=Namira\AdminPublishing::upload($db,$actor,uploadFixture(136),'000D1-901.jpg',[]);uploadOk($legacyId>0,'legacy filename-based callers preserved');
$newArtist=Namira\AdminPublishing::save($db,$actor,'artist',fn()=>(new Namira\Artists($db,$actor))->save(['display_name'=>'هنرمند دوم آزمون','data'=>[],'public_fields'=>[]]));
$edit=(new Namira\Media($db,$actor))->get($ids[0]);$originalPath=$edit['original_path'];
$editInput=['revision'=>$edit['revision'],'title'=>$edit['title'],'type_id'=>$edit['type_id'],'martyrs'=>[$draft],'creators'=>[['artist_id'=>$newArtist,'role'=>'طراح']],'download_allowed'=>'1'];
Namira\AdminPublishing::save($db,$actor,'media',function()use($db,$actor,$ids,$editInput){(new Namira\Media($db,$actor))->save($ids[0],$editInput);return $ids[0];});
uploadOk(array_map('intval',Namira\Db::query($db,'SELECT martyr_id FROM media_public_martyrs WHERE image_id=?',[$ids[0]])->fetchAll(PDO::FETCH_COLUMN))===[$draft],'editing replaces public martyr association');
uploadOk((int)Namira\Db::query($db,'SELECT artist_id FROM media_public_artists WHERE image_id=?',[$ids[0]])->fetchColumn()===$newArtist,'editing replaces public artist association');
uploadOk((new Namira\Media($db,$actor))->get($ids[0])['original_path']===$originalPath,'reassignment preserves original path');
uploadOk(in_array($ids[0],array_column((new Namira\Archive($db))->forPerson($draft)['items'],'id')),'martyr profile sees reassigned image');
$editing=null;ob_start();view('media-upload',['db'=>$db]);$uploadHtml=ob_get_clean();
uploadOk(str_contains($uploadHtml,'value="normal"')&&str_contains($uploadHtml,'value="bulk"')&&str_contains($uploadHtml,'data-upload-preview="true"'),'both methods retain preview/progress form');
$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8">'.$uploadHtml);libxml_clear_errors();$xp=new DOMXPath($doc);
uploadOk($xp->query('//select[@name="upload_martyr_id" and @required and not(@multiple)]')->length===1,'exactly one required martyr');
uploadOk($xp->query('//select[@name="upload_artist_id" and not(@required) and not(@multiple)]')->length===1,'at most one optional artist');
if(getenv('UPLOAD_UI_HTML'))file_put_contents(getenv('UPLOAD_UI_HTML'),$uploadHtml);
if(getenv('MEDIA_UPLOAD_MANIFEST'))file_put_contents(getenv('MEDIA_UPLOAD_MANIFEST'),json_encode(['storage'=>$root,'dsn'=>$dsn,'admin'=>$actor['id'],'person'=>$person,'artist'=>$artist,'first_image'=>$ids[0]]));
echo "$uploadChecks media upload checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";

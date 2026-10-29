<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallPublishing {
 public static function version(PDO $db,array $work,bool $public=false):array {
  $id=$public?$work['published_version_id']:$work['latest_version_id'];$row=$id?Db::query($db,'SELECT * FROM call_work_versions WHERE id=? AND submission_id=?',[$id,$work['id']])->fetch():false;
  if(!$row)throw new HttpError(422,'نسخه ثبت نهایی اثر موجود نیست.');$row['data']=Protocol::decode($row['snapshot']);return $row;
 }
 public static function prepare(PDO $db,array $actor,int $id):array {
  CallSchema::permit($db,$actor,'submissions.view');$work=CallEntries::get($db,$id,$actor);$version=self::version($db,$work);$data=$version['data'];$assets=[];
  $ids=array_column($data['files'],'asset_id');foreach($data['section_common_fields']??[] as $f)if($f['type']==='file')$ids=[...$ids,...($data['section_common'][$f['key']]??[])];foreach($data['common_fields']??[] as $f)if($f['type']==='file')$ids=[...$ids,...($data['common'][$f['key']]??[])];
  foreach(array_unique(array_map('intval',$ids)) as $asset){$a=Db::query($db,'SELECT a.*,l.filename FROM incoming_assets a JOIN call_asset_links l ON l.asset_id=a.id WHERE a.id=? AND l.batch_id=?',[$asset,$work['batch_id']])->fetch();if($a)$assets[]=$a;}
  return compact('work','version','assets');
 }
 public static function publish(PDO $db,array $actor,int $id,array $in,array $verifiedPreviews=[]):array {
  CallSchema::permit($db,$actor,'submissions.publish');$created=[];
  try{return Db::transaction($db,function()use($db,$actor,$id,$in,$verifiedPreviews,&$created){
   $w=CallEntries::get($db,$id,$actor,false,true);
   if($w['publication_state']==='published'&&(int)$w['published_version_id']===(int)$w['latest_version_id']&&$w['review_state']!=='draft')return ['already_published'=>true,'publications'=>Db::query($db,'SELECT id FROM call_publications WHERE submission_id=? AND version_id=?',[$id,$w['published_version_id']])->fetchAll(PDO::FETCH_COLUMN),'martyr_id'=>$w['martyr_id']];
   if($w['review_state']!=='approved'||(int)($in['revision']??-1)!==(int)$w['revision'])throw new HttpError(409,'نسخه فعلی باید ابتدا توسط دبیرخانه پذیرفته شود.');
   if(($in['publication_ack']??'')!=='1')throw new HttpError(422,'انتشار نسخه و انتساب را صریحاً تأیید کنید.');
   $version=self::version($db,$w);$snap=$version['data'];$person=CallForms::integer($in['martyr_id']??0,1,2147483647);
   $martyr=Db::query($db,'SELECT * FROM martyrs WHERE id=?',[$person])->fetch();if(!$martyr)throw new HttpError(422,'پرونده شهید انتخاب‌شده موجود نیست.');
   if(!$martyr['published']){if(($in['publish_martyr']??'')!=='1')throw new HttpError(422,'پرونده شهید منتشر نشده است؛ از مسیر اصلی بررسی و منتشر کنید.');AdminPublishing::martyr($db,$actor,$person);}
   $artist=(int)($in['artist_id']??0);$credit=$snap['owner_mode']==='unknown'?'صاحب اثر نامشخص':($snap['owner']['name']??'');
   if($artist){$a=Db::query($db,'SELECT * FROM artists WHERE id=? AND archived=0',[$artist])->fetch();if(!$a)throw new HttpError(422,'هنرمند انتخاب‌شده موجود نیست.');
    if(!$a['public_visible']){if(($in['publish_artist']??'')!=='1')throw new HttpError(422,'هنرمند را از مسیر بانک هنرمندان بررسی و منتشر کنید.');AdminPublishing::artist($db,$actor,$artist);$a=Db::query($db,'SELECT * FROM artists WHERE id=?',[$artist])->fetch();}
    $credit=Protocol::decode($a['public_snapshot'])['display_name']??$a['display_name'];
   }
   $available=self::prepare($db,$actor,$id)['assets'];$byId=array_column($available,null,'id');$selected=$in['asset_ids']??[];
   if(!is_array($selected))throw new HttpError(422,'فایل‌های قابل انتشار را انتخاب کنید.');$selected=array_values(array_unique(array_map('intval',$selected)));
   foreach($selected as $asset)if(!isset($byId[$asset]))throw new HttpError(422,'فایل خارج از نسخه اثر انتخاب شده است.');
   $publishText=($in['publish_text']??'')==='1'&&trim($snap['text']??'')!=='';
   if(!$selected&&!$publishText)throw new HttpError(422,'دست‌کم متن یا یک فایل از همین نسخه را برای انتشار انتخاب کنید.');
   $type=(int)($in['media_type_id']??0);$hasImages=(bool)array_filter($selected,fn($a)=>in_array($byId[$a]['mime'],['image/jpeg','image/png','image/webp'],true));
   if($hasImages&&!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='media_type' AND active=1",[$type])->fetchColumn())throw new HttpError(422,'نوع تصویر را از مقادیر واقعی آرشیو انتخاب کنید.');
   $now=date('Y-m-d H:i:s');$old=Db::query($db,'SELECT * FROM call_publications WHERE submission_id=? AND visible=1',[$id])->fetchAll();$oldIds=array_filter(array_column($old,'image_id'));$published=[];$slot=0;
   $base=['title'=>$snap['title'],'description'=>$snap['description'],'credit_name'=>$credit,'tracking'=>$w['tracking'],'ai_used'=>$snap['ai_used'],'call_id'=>$w['call_id'],'rights_text'=>$snap['rules_text']];
   foreach($selected as $assetId){$asset=$byId[$assetId];$kind='attachment';$image=null;$createdByCall=false;
    if(in_array($asset['mime'],['image/jpeg','image/png','image/webp'],true)){
     $kind='media';$existing=(int)Db::query($db,'SELECT id FROM images WHERE sha256=?',[$asset['sha256']])->fetchColumn();
     $owned=false;
     if($existing){$current=Db::query($db,'SELECT payload FROM call_publications WHERE submission_id=? AND version_id=? AND image_id=?',[$id,$version['id'],$existing])->fetchColumn();if($current&&!empty(Protocol::decode($current)['created_by_call']))$owned=true;}
     if($existing){foreach($old as $p)if((int)$p['image_id']===$existing&&!empty(Protocol::decode($p['payload'])['created_by_call']))$owned=true;
      if(Db::query($db,'SELECT id FROM call_publications WHERE image_id=? AND visible=1 AND submission_id<>?',[$existing,$id])->fetchColumn())$owned=false;
     }
     if($existing&&!$owned){
      if(($in['existing_ack']??'')!=='1')throw new HttpError(409,'این فایل از قبل در آرشیو موجود است؛ استفاده از رسانه موجود را پس از مقایسه تأیید کنید.');
      if(!Db::query($db,'SELECT image_id FROM media_public_martyrs WHERE image_id=? AND martyr_id=?',[$existing,$person])->fetchColumn()||($artist&&!Db::query($db,'SELECT image_id FROM media_public_artists WHERE image_id=? AND artist_id=?',[$existing,$artist])->fetchColumn()))throw new HttpError(422,'انتساب رسانه موجود با انتخاب شما یکسان نیست؛ ابتدا همان رسانه را در مدیریت تصاویر اصلاح کنید.');
      $image=$existing;
     }else{
      if($existing){$image=$existing;$createdByCall=true;}else{
       $preview=$verifiedPreviews[$assetId]??null;if($preview&&($in['verified_preview_ack']??'')!=='1')throw new HttpError(422,'پیش‌نمایش بازتولیدشده از اصل را بررسی و تأیید کنید.');
       $image=IncomingAssets::promote($db,$asset,$person,$snap['title'],$type,$created,$preview);$createdByCall=true;
      }
      $media=new Media($db,$actor);$m=$media->get($image);
      $media->save($image,['revision'=>$m['revision'],'title'=>$snap['title'],'description'=>mb_substr($snap['description'],0,10000),'type_id'=>$type,'source_text'=>'اثر فراخوان؛ کد '.$w['tracking'],'rights_text'=>mb_substr($snap['rules_text'],0,5000),'download_allowed'=>'1','martyrs'=>[$person],'creators'=>$artist?[['artist_id'=>$artist,'role'=>'صاحب اثر']]:[],'reason'=>'انتشار صریح اثر فراخوان '.$w['tracking']]);
      AdminPublishing::media($db,$actor,$image);
      Db::query($db,'UPDATE images SET designer=? WHERE id=?',[mb_substr($credit,0,150),$image]);
     }
    }
    $payload=$base+['filename'=>$w['tracking'].'-'.str_pad((string)($slot+1),2,'0',STR_PAD_LEFT).'.'.strtolower(pathinfo($asset['filename'],PATHINFO_EXTENSION)),'created_by_call'=>$createdByCall];
    $published[]=self::publication($db,['submission_id'=>$id,'version_id'=>$version['id'],'martyr_id'=>$person,'artist_id'=>$artist?:null,'kind'=>$kind,'asset_id'=>$assetId,'image_id'=>$image,'slot'=>$slot++,'payload'=>Protocol::encode($payload),'published_by'=>$actor['id'],'published_at'=>$now]);
   }
   if($publishText)$published[]=self::publication($db,['submission_id'=>$id,'version_id'=>$version['id'],'martyr_id'=>$person,'artist_id'=>$artist?:null,'kind'=>'text','slot'=>$slot,'payload'=>Protocol::encode($base+['text'=>$snap['text']]),'published_by'=>$actor['id'],'published_at'=>$now]);
   Db::query($db,'UPDATE call_publications SET visible=0 WHERE submission_id=? AND id NOT IN ('.implode(',',array_fill(0,count($published),'?')).')',[$id,...$published]);
   foreach(array_unique($oldIds) as $oldImage){
    if(Db::query($db,'SELECT id FROM call_publications WHERE image_id=? AND visible=1',[$oldImage])->fetchColumn())continue;
    $owned=false;foreach($old as $p)if((int)$p['image_id']===(int)$oldImage&&!empty(Protocol::decode($p['payload'])['created_by_call']))$owned=true;
    if($owned){Db::query($db,'UPDATE images SET published=0 WHERE id=?',[$oldImage]);Db::query($db,"UPDATE media_details SET status='archived',revision=revision+1 WHERE image_id=?",[$oldImage]);}
   }
   Db::query($db,"UPDATE call_work_details SET publication_state='published',published_version_id=? WHERE submission_id=?",[$version['id'],$id]);
   Db::query($db,'UPDATE art_submissions SET martyr_id=?,artist_id=?,image_id=?,revision=revision+1,updated_at=? WHERE id=?',[$person,$artist?:null,($published?Db::query($db,'SELECT image_id FROM call_publications WHERE id=?',[$published[0]])->fetchColumn():null)?:null,$now,$id]);
   CommunityHistory::add($db,'art_submission',$id,$actor,$w['publication_state'],'published',Protocol::encode(['version_id'=>$version['id'],'martyr_id'=>$person,'artist_id'=>$artist?:null,'publications'=>$published]));
   CallEntries::notify($db,(int)$w['user_id'],$id,'published-'.$version['id'],'اثر '.$w['tracking'].' در صفحه شهید منتشر شد.');
   return ['publications'=>$published,'martyr_id'=>$person,'version_id'=>$version['id']];
  });}catch(\Throwable $e){IncomingAssets::discardPaths($created);throw $e;}
 }
 private static function publication(PDO $db,array $data):int {
  $old=Db::query($db,'SELECT id FROM call_publications WHERE submission_id=? AND version_id=? AND slot=?',[$data['submission_id'],$data['version_id'],$data['slot']])->fetchColumn();
  if(!$old)return Db::insert($db,'call_publications',$data);$data['visible']=1;$set=[];$args=[];foreach($data as $key=>$value){$set[]=$key.'=?';$args[]=$value;}$args[]=$old;Db::query($db,'UPDATE call_publications SET '.implode(',',$set).' WHERE id=?',$args);return (int)$old;
 }
 public static function unpublish(PDO $db,array $actor,int $id,array $in):void {
  CallSchema::permit($db,$actor,'submissions.publish');$note=Security::text($in,'note',3000);
  Db::transaction($db,function()use($db,$actor,$id,$in,$note){
   $w=CallEntries::get($db,$id,$actor,false,true);if((int)($in['revision']??-1)!==(int)$w['revision'])throw new HttpError(409,'نسخه اثر تغییر کرده است.');
   $rows=Db::query($db,'SELECT * FROM call_publications WHERE submission_id=? AND visible=1',[$id])->fetchAll();Db::query($db,'UPDATE call_publications SET visible=0 WHERE submission_id=?',[$id]);
   foreach($rows as $p)if($p['image_id']&&!empty(Protocol::decode($p['payload'])['created_by_call'])&&!Db::query($db,'SELECT id FROM call_publications WHERE image_id=? AND visible=1',[$p['image_id']])->fetchColumn())Db::query($db,'UPDATE images SET published=0 WHERE id=?',[$p['image_id']]);
   Db::query($db,"UPDATE call_work_details SET publication_state='withdrawn' WHERE submission_id=?",[$id]);Db::query($db,'UPDATE art_submissions SET revision=revision+1 WHERE id=?',[$id]);CommunityHistory::add($db,'art_submission',$id,$actor,$w['publication_state'],'withdrawn',$note);
  });
 }
 public static function publicWorks(PDO $db,?int $person=null,?int $artist=null,?int $image=null):array {
  if(!CallSchema::ready($db))return [];$where="p.visible=1 AND w.publication_state='published' AND p.version_id=w.published_version_id AND m.published=1";$args=[];
  foreach(['p.martyr_id'=>$person,'p.artist_id'=>$artist,'p.image_id'=>$image] as $col=>$id)if($id){$where.=' AND '.$col.'=?';$args[]=$id;}
  $rows=Db::query($db,'SELECT p.*,s.call_id,s.title AS private_title,c.title AS call_title,c.status AS call_status,a.public_visible AS artist_public FROM call_publications p JOIN call_work_details w ON w.submission_id=p.submission_id JOIN art_submissions s ON s.id=p.submission_id JOIN martyrs m ON m.id=p.martyr_id JOIN art_calls c ON c.id=s.call_id LEFT JOIN artists a ON a.id=p.artist_id WHERE '.$where.' ORDER BY p.published_at DESC,p.submission_id,p.slot',$args)->fetchAll();$out=[];
  foreach($rows as $r){$key=$r['submission_id'];if(!isset($out[$key]))$out[$key]=['submission_id'=>$key,'version_id'=>$r['version_id'],'martyr_id'=>$r['martyr_id'],'artist_id'=>$r['artist_public']?$r['artist_id']:null,'data'=>Protocol::decode($r['payload']),'call_title'=>$r['call_status']==='published'?$r['call_title']:'','call_id'=>$r['call_status']==='published'?$r['call_id']:null,'files'=>[],'text'=>''];$payload=Protocol::decode($r['payload']);if($r['kind']==='text')$out[$key]['text']=$payload['text'];else $out[$key]['files'][]=['publication_id'=>$r['id'],'image_id'=>$r['image_id'],'kind'=>$r['kind'],'filename'=>$payload['filename']??'فایل'];}
  return array_values($out);
 }
}

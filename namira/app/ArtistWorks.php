<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** New artist works reuse the existing private submissions, files and publication service. */
final class ArtistWorks {
 public static function get(PDO $db,array $actor,int $id):array {
  ArtistPortalSchema::requireReady($db);$extra=Db::query($db,'SELECT * FROM artist_work_requests WHERE submission_id=?',[$id])->fetch();if(!$extra)throw new HttpError(404,'اثر هنرمند پیدا نشد.');$row=(new Submissions($db,$actor))->get($id);if(!$row)throw new HttpError(404,'اثر پیدا نشد.');return $row+['proposal'=>Protocol::decode($extra['proposal']),'martyr_state'=>$extra['martyr_state'],'created_martyr_id'=>$extra['created_martyr_id']];
 }
 public static function submit(PDO $db,array $actor,array $in,?string $source=null,int $id=0,?string $preview=null):int {
  [$actor,$artist]=ArtistMemberships::requireArtist($db,$actor);$old=$id?self::get($db,$actor,$id):null;if($old&&((int)$old['user_id']!==(int)$actor['id']||$old['status']!=='needs_changes'))throw new HttpError(403,'فقط اثر بازگردانده‌شده خودتان قابل اصلاح است.');
  $profile=(new Artists($db,$actor))->get($artist);$person=(int)($in['martyr_id']??0);$proposal=[];
  if(($in['missing_martyr']??'')==='1'){$person=0;foreach(['first_name'=>150,'last_name'=>150,'code'=>80,'place'=>255,'details'=>5000] as $key=>$max)$proposal[$key]=Security::text($in,'proposed_'.$key,$max,in_array($key,['first_name','last_name'],true));}
  elseif(!$person)throw new HttpError(422,'شهید موجود یا گزینه نبودن شهید در فهرست را انتخاب کنید.');
  $input=['title'=>Security::text($in,'title',255,false)?:'اثر هنری '.($proposal?trim($proposal['first_name'].' '.$proposal['last_name']):(Db::query($db,'SELECT first_name FROM martyrs WHERE id=? AND published=1',[$person])->fetchColumn()?:'')),'artist_id'=>$artist,'artist_name'=>$profile['display_name'],'martyr_id'=>$person,'martyr_name'=>$proposal?trim($proposal['first_name'].' '.$proposal['last_name']):'','martyr_code'=>$proposal['code']??'','martyr_details'=>($proposal['place']??'').' '.($proposal['details']??''),'description'=>Security::text($in,'description',10000,false),'rights_text'=>$in['rights_text']??'','consent'=>$in['consent']??'','download_consent'=>$in['download_consent']??'','type_id'=>$in['type_id']??PlatformSchema::termId($db,'media_type','portrait'),'specialty_id'=>$profile['data']['specialties'][0]??0,'revision'=>$in['revision']??-1,'call_id'=>0];
  $created=[];try{return Db::transaction($db,function()use($db,$actor,$input,$source,$preview,$id,$old,$proposal,&$created){
   $sid=(new Submissions($db,$actor))->submit($input,$source,$preview,$id);$assets=Db::query($db,'SELECT a.* FROM submission_assets m JOIN incoming_assets a ON a.id=m.asset_id WHERE m.submission_id=?',[$sid])->fetchAll();$oldIds=array_column($old['assets']??[],'id');foreach($assets as $a)if(!in_array($a['id'],$oldIds))$created[]=$a;
   if($old)Db::query($db,'UPDATE artist_work_requests SET proposal=?,martyr_state=? WHERE submission_id=?',[Protocol::encode($proposal),$proposal?'pending':'resolved',$sid]);else Db::insert($db,'artist_work_requests',['submission_id'=>$sid,'proposal'=>Protocol::encode($proposal),'martyr_state'=>$proposal?'pending':'resolved']);return $sid;
  });}catch(\Throwable $e){IncomingAssets::discard($created);throw $e;}
 }
 public static function resolve(PDO $db,array $actor,int $id,array $in,bool $create=false):int {
  $actor=ArtistMemberships::admin($db,$actor,'artist_works.review');if(!Permissions::allows($db,$actor,'submissions.review'))throw new HttpError(403,'مجوز بررسی آثار لازم است.');
  return Db::transaction($db,function()use($db,$actor,$id,$in,$create){$w=self::get($db,$actor,$id);$s=Db::query($db,'SELECT * FROM art_submissions WHERE id=?'.ArtistMemberships::lock($db),[$id])->fetch();if($s['status']==='published'||(int)$s['revision']!==(int)($in['revision']??-1))throw new HttpError(409,'اثر منتشر شده یا نسخه آن تغییر کرده است.');
   if($create){if(!Permissions::allows($db,$actor,'martyrs.edit'))throw new HttpError(403,'مجوز ایجاد پرونده شهید لازم است.');if($w['created_martyr_id'])throw new HttpError(409,'برای این درخواست قبلاً پرونده ساخته شده است.');$p=$w['proposal'];if(empty($p['first_name'])||empty($p['last_name'])||($in['create_ack']??'')!=='1')throw new HttpError(422,'بررسی تشابه و تأیید ایجاد پرونده لازم است.');$person=(new Protocol($db,$actor))->quick(['first_name'=>$p['first_name'],'last_name'=>$p['last_name'],'code'=>$p['code']??'']);Db::query($db,'UPDATE artist_work_requests SET created_martyr_id=? WHERE submission_id=?',[$person,$id]);}
   else {$person=(int)($in['martyr_id']??0);if(!$person||!Db::query($db,'SELECT id FROM martyrs WHERE id=?',[$person])->fetchColumn())throw new HttpError(422,'پرونده شهید را انتخاب کنید.');}
   Db::query($db,"UPDATE artist_work_requests SET martyr_state='resolved',processed_by=? WHERE submission_id=?",[$actor['id'],$id]);Db::query($db,'UPDATE art_submissions SET martyr_id=?,revision=revision+1,updated_at=? WHERE id=?',[$person,date('Y-m-d H:i:s'),$id]);CommunityHistory::add($db,'art_submission',$id,$actor,$s['status'],$s['status'],Protocol::encode(['action'=>$create?'create_martyr':'resolve_martyr','martyr_id'=>$person,'proposal_preserved'=>$w['proposal']]));return $person;
  });
 }
 public static function review(PDO $db,array $actor,int $id,array $in,?string $preview=null):?int {
  $actor=ArtistMemberships::admin($db,$actor,'artist_works.review');$to=Security::text($in,'status',30);if(!in_array($to,['reviewing','needs_changes','rejected','published'],true))throw new HttpError(422,'وضعیت بررسی معتبر نیست.');
  return Db::transaction($db,function()use($db,$actor,$id,$in,$to,$preview){$row=Db::query($db,'SELECT * FROM art_submissions WHERE id=?'.ArtistMemberships::lock($db),[$id])->fetch();$work=self::get($db,$actor,$id);if(!$row||(int)$row['revision']!==(int)($in['revision']??-1))throw new HttpError(409,'نسخه اثر تغییر کرده است.');if($row['status']==='published')throw new HttpError(409,'اثر منتشرشده فقط از مدیریت تصاویر قابل تغییر است.');$service=new Submissions($db,$actor);
   if($to!=='published'){$service->review($id,['status'=>$to,'revision'=>$row['revision'],'note'=>Security::text($in,'note',5000,in_array($to,['rejected','needs_changes'],true))?:'شروع بررسی اثر']);return null;}
   if(($in['publication_ack']??'')!=='1'||$work['martyr_state']!=='resolved')throw new HttpError(422,'بررسی پرونده شهید و تأیید صریح انتشار لازم است.');if(ArtistMemberships::approved($db,(int)$row['user_id'])!==(int)$row['artist_id'])throw new HttpError(409,'عضویت هنرمند فعال نیست یا انتساب نیاز به بررسی دارد.');
   if($row['status']==='submitted'){$service->review($id,['status'=>'reviewing','revision'=>$row['revision'],'note'=>'شروع بررسی برای انتشار']);$row=$service->get($id);}
   if($row['status']==='reviewing'){$service->review($id,['status'=>'approved','revision'=>$row['revision'],'note'=>Security::text($in,'note',5000,false)?:'تأیید اثر و انتساب برای انتشار','identity_ack'=>'1','creator_role'=>'طراح','reviewed_rights'=>Security::text($in,'reviewed_rights',5000,false)?:($work['data']['rights_text']??''),'allow_download'=>($in['allow_download']??'')==='1'?'1':'']);$row=$service->get($id);}
   return $service->publish($id,['revision'=>$row['revision'],'publication_ack'=>'1','verified_visual_ack'=>$in['verified_visual_ack']??'','existing_ack'=>$in['existing_ack']??''],$preview);
  });
 }
}

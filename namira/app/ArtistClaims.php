<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ArtistClaims {
 public static function available(PDO $db,int $image):bool {
  return (bool)Db::query($db,"SELECT i.id FROM images i WHERE i.id=? AND i.published=1 AND i.kind='design' AND TRIM(i.designer)='' AND EXISTS(SELECT 1 FROM media_public_martyrs p JOIN martyrs m ON m.id=p.martyr_id WHERE p.image_id=i.id AND m.published=1) AND NOT EXISTS(SELECT 1 FROM media_artists a WHERE a.image_id=i.id) AND NOT EXISTS(SELECT 1 FROM media_public_artists a WHERE a.image_id=i.id)",[$image])->fetchColumn();
 }
 public static function selection(PDO $db,array $actor):array {[$actor]=ArtistMemberships::requireArtist($db,$actor);return array_map('intval',Db::query($db,'SELECT image_id FROM artist_archive_selections WHERE user_id=? ORDER BY image_id',[$actor['id']])->fetchAll(PDO::FETCH_COLUMN));}
 public static function select(PDO $db,array $actor,int $image,bool $selected):array {
  [$actor]=ArtistMemberships::requireArtist($db,$actor);return Db::transaction($db,function()use($db,$actor,$image,$selected){Db::query($db,'SELECT id FROM users WHERE id=?'.ArtistMemberships::lock($db),[$actor['id']])->fetch();$ids=self::selection($db,$actor);
   if($selected){if(!self::available($db,$image))throw new HttpError(409,'این اثر قابل انتخاب نیست؛ اثر دارای هنرمند از مسیر مدیریت تصاویر بررسی می‌شود.');if(!in_array($image,$ids,true)){if(count($ids)>=200)throw new HttpError(422,'حداکثر ۲۰۰ اثر را در هر درخواست انتخاب کنید.');Db::insert($db,'artist_archive_selections',['user_id'=>$actor['id'],'image_id'=>$image,'created_at'=>date('Y-m-d H:i:s')]);}}
   else Db::query($db,'DELETE FROM artist_archive_selections WHERE user_id=? AND image_id=?',[$actor['id'],$image]);return self::selection($db,$actor);
  });
 }
 public static function clear(PDO $db,array $actor):void {[$actor]=ArtistMemberships::requireArtist($db,$actor);Db::query($db,'DELETE FROM artist_archive_selections WHERE user_id=?',[$actor['id']]);}
 public static function request(PDO $db,array $actor):array {
  [$actor,$artist]=ArtistMemberships::requireArtist($db,$actor);Security::limit($db,'artist-work-claims',20,3600);return Db::transaction($db,function()use($db,$actor,$artist){Db::query($db,'SELECT id FROM users WHERE id=?'.ArtistMemberships::lock($db),[$actor['id']])->fetch();$ids=self::selection($db,$actor);if(!$ids)throw new HttpError(422,'ابتدا آثاری را انتخاب کنید.');$batch=bin2hex(random_bytes(16));$created=[];$now=date('Y-m-d H:i:s');foreach($ids as $image){if(!self::available($db,$image))throw new HttpError(409,'اثر #'.$image.' اکنون قابل انتساب عادی نیست؛ آن را از انتخاب‌ها حذف کنید.');$old=Db::query($db,'SELECT * FROM artist_work_claims WHERE artist_id=? AND image_id=?',[$artist,$image])->fetch();if($old&&$old['status']!=='rejected')throw new HttpError(409,'برای اثر #'.$image.' درخواست ثبت شده است.');if($old){$id=(int)$old['id'];Db::query($db,"UPDATE artist_work_claims SET batch_key=?,status='pending',review_note='',revision=revision+1,updated_at=? WHERE id=?",[$batch,$now,$id]);}else $id=Db::insert($db,'artist_work_claims',['user_id'=>$actor['id'],'artist_id'=>$artist,'image_id'=>$image,'batch_key'=>$batch,'review_note'=>'','created_at'=>$now,'updated_at'=>$now]);CommunityHistory::add($db,'artist_work_claim',$id,$actor,$old['status']??'','pending','درخواست انتساب؛ گروه '.$batch);$created[]=$id;}
   Db::query($db,'DELETE FROM artist_archive_selections WHERE user_id=?',[$actor['id']]);return $created;
  });
 }
 private static function attach(PDO $db,array $actor,int $image,int $artist,string $reason):void {
  foreach(['media.edit','media.publish'] as $permission)if(!Permissions::allows($db,$actor,$permission))throw new HttpError(403,'مجوز اصلاح و انتشار انتساب رسانه لازم است.');
  Db::query($db,'SELECT image_id FROM media_details WHERE image_id=?'.ArtistMemberships::lock($db),[$image])->fetch();if(!self::available($db,$image))throw new HttpError(409,'اثر قبلاً به هنرمند منتسب شده یا دیگر عمومی نیست؛ بازنویسی انتساب انجام نشد.');$name=Db::query($db,"SELECT public_snapshot FROM artists WHERE id=? AND public_visible=1 AND archived=0",[$artist])->fetchColumn();if(!$name)throw new HttpError(409,'پروفایل هنرمند باید عمومی و فعال باشد.');
  // Change only creator relationships. Do not publish unrelated pending media changes.
  Db::insert($db,'media_artists',['image_id'=>$image,'artist_id'=>$artist,'creator_role'=>'طراح','credit_order'=>0]);Db::insert($db,'media_public_artists',['image_id'=>$image,'artist_id'=>$artist,'creator_role'=>'طراح','credit_name'=>Protocol::decode($name)['display_name']??'','credit_order'=>0]);Db::query($db,'UPDATE media_details SET revision=revision+1,updated_at=? WHERE image_id=?',[date('Y-m-d H:i:s'),$image]);
  Db::insert($db,'media_changes',['image_id'=>$image,'user_id'=>$actor['id'],'action'=>'artist.claim','before_value'=>'[]','after_value'=>Protocol::encode(['artist_id'=>$artist]),'reason'=>$reason,'created_at'=>date('Y-m-d H:i:s')]);
 }
 public static function review(PDO $db,array $actor,array $decisions):void {
  $actor=ArtistMemberships::admin($db,$actor,'artist_claims.review');if(!$decisions||count($decisions)>200)throw new HttpError(422,'بین یک تا ۲۰۰ تصمیم انتخاب کنید.');
  Db::transaction($db,function()use($db,$actor,$decisions){ksort($decisions,SORT_NUMERIC);foreach($decisions as $id=>$decision){if(!ctype_digit((string)$id)||!is_array($decision))throw new HttpError(422,'درخواست معتبر نیست.');$to=Security::text($decision,'status',30);if(!in_array($to,['approved','rejected'],true))throw new HttpError(422,'تصمیم معتبر نیست.');$r=Db::query($db,'SELECT * FROM artist_work_claims WHERE id=?'.ArtistMemberships::lock($db),[(int)$id])->fetch();if(!$r)throw new HttpError(404,'درخواست پیدا نشد.');if($r['status']!=='pending'||(int)$r['revision']!==(int)($decision['revision']??-1))throw new HttpError(409,'نسخه یا وضعیت درخواست تغییر کرده است.');$note=Security::text($decision,'note',5000,$to==='rejected');if($to==='approved'){if(ArtistMemberships::approved($db,(int)$r['user_id'])!==(int)$r['artist_id'])throw new HttpError(409,'عضویت هنرمند فعال نیست.');self::attach($db,$actor,(int)$r['image_id'],(int)$r['artist_id'],$note?:'تأیید انتساب درخواست '.$id);}
   Db::query($db,'UPDATE artist_work_claims SET status=?,review_note=?,reviewed_by=?,revision=revision+1,updated_at=? WHERE id=?',[$to,$note,$actor['id'],date('Y-m-d H:i:s'),$id]);CommunityHistory::add($db,'artist_work_claim',(int)$id,$actor,'pending',$to,$note);}
  });
 }
 public static function assign(PDO $db,array $actor,int $artist,array $images,string $reason):void {
  $actor=ArtistMemberships::admin($db,$actor,'artist_claims.review');if(trim($reason)===''||!$images||count($images)>200)throw new HttpError(422,'آثار و دلیل انتساب لازم است.');if(!Db::query($db,"SELECT id FROM artist_join_requests WHERE artist_id=? AND status='approved'",[$artist])->fetchColumn())throw new HttpError(422,'پروفایل جدید و تأییدشده را انتخاب کنید.');$ids=array_values(array_unique(array_map('intval',$images)));sort($ids,SORT_NUMERIC);Db::transaction($db,function()use($db,$actor,$artist,$ids,$reason){foreach($ids as $image){self::attach($db,$actor,$image,$artist,$reason);CommunityHistory::add($db,'artist_assignment',$image,$actor,'unassigned','approved',Protocol::encode(['artist_id'=>$artist,'reason'=>$reason]));}});
 }
}

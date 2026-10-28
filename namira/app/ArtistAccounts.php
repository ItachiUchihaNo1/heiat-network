<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ArtistAccounts {
    public static function owns(PDO $db,array $actor,int $artist):bool {
        if(MemberSchema::ready($db))return false;
        if(!$artist||empty($actor['id'])||!CommunitySchema::ready($db))return false;
        return (bool)Db::query($db,'SELECT a.artist_id FROM artist_accounts a LEFT JOIN artist_account_states s ON s.artist_id=a.artist_id AND s.user_id=a.user_id WHERE a.artist_id=? AND a.user_id=? AND COALESCE(s.active,1)=1',[$artist,$actor['id']])->fetchColumn();
    }
    public static function owned(PDO $db,array $actor):array {
        if(MemberSchema::ready($db))return [];
        if(!CommunitySchema::ready($db))return [];
        return Db::query($db,'SELECT a.id,a.display_name,a.status FROM artists a JOIN artist_accounts x ON x.artist_id=a.id LEFT JOIN artist_account_states s ON s.artist_id=x.artist_id AND s.user_id=x.user_id WHERE x.user_id=? AND COALESCE(s.active,1)=1 AND a.archived=0 ORDER BY a.display_name',[$actor['id']])->fetchAll();
    }
    public static function claim(PDO $db,array $actor,array $in):int {
        if(MemberSchema::ready($db))throw new HttpError(410,'اتصال حساب عمومی به مجوز ویرایش هنرمند غیرفعال شده است.');CommunitySchema::requireReady($db);$actor=CommunityHistory::requireActor($db,$actor);Security::limit($db,'artist-claim',10,3600);$artist=(int)($in['artist_id']??0);$evidence=Security::text($in,'evidence',5000);
        return Db::transaction($db,function()use($db,$actor,$artist,$evidence){
            $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            if(!Db::query($db,'SELECT id FROM artists WHERE id=? AND public_visible=1 AND archived=0'.$lock,[$artist])->fetchColumn())throw new HttpError(404,'پروفایل عمومی هنرمند پیدا نشد.');
            if(self::owns($db,$actor,$artist))throw new HttpError(409,'این پروفایل از قبل به حساب شما متصل است.');
            $previous=Db::query($db,'SELECT * FROM artist_account_claims WHERE artist_id=? AND user_id=? ORDER BY id DESC LIMIT 1',[$artist,$actor['id']])->fetch();$now=date('Y-m-d H:i:s');
            if($previous){if(!in_array($previous['status'],['needs_changes','rejected','revoked'],true))throw new HttpError(409,'درخواست شما در حال بررسی است.');$id=(int)$previous['id'];Db::query($db,"UPDATE artist_account_claims SET evidence=?,status='pending',updated_at=? WHERE id=?",[$evidence,$now,$id]);}
            else $id=Db::insert($db,'artist_account_claims',['artist_id'=>$artist,'user_id'=>$actor['id'],'evidence'=>$evidence,'review_note'=>'','created_at'=>$now,'updated_at'=>$now]);
            CommunityHistory::add($db,'artist_claim',$id,$actor,$previous['status']??'','pending',Protocol::encode(['evidence'=>$evidence,'previous_evidence'=>$previous['evidence']??null]));return $id;
        });
    }
    public static function review(PDO $db,array $actor,int $id,array $in):void {
        $actor=CommunityHistory::requireActor($db,$actor);if(!Permissions::allows($db,$actor,'artists.claims.review'))throw new HttpError(403,'مجوز بررسی اتصال حساب را ندارید.');
        $status=Security::text($in,'status',30);$note=Security::text($in,'note',5000);
        if(!in_array($status,['approved','rejected','needs_changes','revoked'],true))throw new HttpError(422,'نتیجه بررسی معتبر نیست.');
        if($status==='approved'&&($in['verification_ack']??'')!=='1')throw new HttpError(422,'تأیید بررسی مالکیت یا نمایندگی هنرمند لازم است.');
        Db::transaction($db,function()use($db,$actor,$id,$status,$note){
            $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=Db::query($db,'SELECT * FROM artist_account_claims WHERE id=?'.$lock,[$id])->fetch();if(!$row)throw new HttpError(404,'درخواست اتصال پیدا نشد.');
            if(($row['status']==='approved'&&$status!=='revoked')||($row['status']!=='approved'&&($row['status']!=='pending'||$status==='revoked')))throw new HttpError(409,'وضعیت درخواست تغییر کرده است؛ صفحه را تازه کنید.');
            $now=date('Y-m-d H:i:s');Db::query($db,'UPDATE artist_account_claims SET status=?,reviewed_by=?,review_note=?,updated_at=? WHERE id=?',[$status,$actor['id'],$note,$now,$id]);
            if(in_array($status,['approved','revoked'],true)){
                if(!Db::query($db,'SELECT artist_id FROM artist_accounts WHERE artist_id=? AND user_id=?',[$row['artist_id'],$row['user_id']])->fetchColumn())Db::insert($db,'artist_accounts',['artist_id'=>$row['artist_id'],'user_id'=>$row['user_id'],'verified_by'=>$actor['id'],'verified_at'=>$now]);
                $values=[$status==='approved'?1:0,$note,$actor['id'],$now,$row['artist_id'],$row['user_id']];
                if(Db::query($db,'SELECT artist_id FROM artist_account_states WHERE artist_id=? AND user_id=?',[$row['artist_id'],$row['user_id']])->fetchColumn())Db::query($db,'UPDATE artist_account_states SET active=?,reason=?,updated_by=?,updated_at=? WHERE artist_id=? AND user_id=?',$values);
                else Db::insert($db,'artist_account_states',['artist_id'=>$row['artist_id'],'user_id'=>$row['user_id'],'active'=>$values[0],'reason'=>$note,'updated_by'=>$actor['id'],'updated_at'=>$now]);
            }
            CommunityHistory::add($db,'artist_claim',$id,$actor,$row['status'],$status,$note);
        });
    }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Submissions {
    public function __construct(private PDO $db,private array $actor){}
    private function permit(string $permission):void {if(!Permissions::allows($this->db,$this->actor,$permission))throw new HttpError(403,'مجوز رسیدگی به آثار را ندارید.');}
    public function get(int $id):array|false {
        $row=Db::query($this->db,'SELECT s.*,u.email FROM art_submissions s JOIN users u ON u.id=s.user_id WHERE s.id=?',[$id])->fetch();if(!$row)return false;
        if((int)$row['user_id']!==(int)$this->actor['id'])$this->permit('submissions.view');
        $row['data']=Protocol::decode($row['payload']);$row['assets']=Db::query($this->db,'SELECT a.*,m.is_current,m.sequence_no FROM submission_assets m JOIN incoming_assets a ON a.id=m.asset_id WHERE m.submission_id=? ORDER BY m.sequence_no',[$id])->fetchAll();return $row;
    }
    public function listing(array $f=[],bool $own=false):array {
        if(!$own)$this->permit('submissions.view');$where=$own?'s.user_id=?':'1=1';$args=$own?[$this->actor['id']]:[];
        foreach(['status'=>'s.status','call_id'=>'s.call_id'] as $key=>$col)if(($f[$key]??'')!==''){if($key==='status'&&$f[$key]==='unpublished')$where.=" AND s.status<>'published'";else{$where.=' AND '.$col.'=?';$args[]=$key==='call_id'?(int)$f[$key]:Security::text($f,$key,30);}}
        $q=Security::text($f,'q',150,false);if($q!==''){$where.=' AND s.title LIKE ?';$args[]='%'.$q.'%';}
        return AdminQueries::paginate($this->db,'SELECT s.id,s.title,s.status,s.revision,s.call_id,s.artist_id,s.martyr_id,s.image_id,s.created_at,s.updated_at,u.email,c.title AS call_title','FROM art_submissions s JOIN users u ON u.id=s.user_id LEFT JOIN art_calls c ON c.id=s.call_id',$where,$args,['new'=>'s.id DESC','updated'=>'s.updated_at DESC'],$f);
    }
    private function input(array $in,?array $call):array {
        if(($in['consent']??'')!=='1')throw new HttpError(422,'رضایت ارسال و پذیرش شرایط لازم است.');
        $title=Security::text($in,'title',255);$artistName=Security::text($in,'artist_name',255);$description=Security::text($in,'description',20000,false);$rights=Security::text($in,'rights_text',5000);
        $artist=(int)($in['artist_id']??0);$verified=$artist&&ArtistAccounts::owns($this->db,$this->actor,$artist)?$artist:null;
        if($artist&&!$verified&&!Db::query($this->db,'SELECT id FROM artists WHERE id=? AND public_visible=1 AND archived=0',[$artist])->fetchColumn())throw new HttpError(422,'پروفایل هنرمند انتخاب‌شده پیدا نشد.');
        $person=(int)($in['martyr_id']??0);$personName=Security::text($in,'martyr_name',255,false);$code=Security::normalize(Security::text($in,'martyr_code',80,false));
        if($person&&!Db::query($this->db,'SELECT id FROM martyrs WHERE id=? AND published=1',[$person])->fetchColumn())throw new HttpError(422,'پرونده عمومی شهید پیدا نشد.');
        $criteria=$call['criteria']??['allow_new_martyr'=>true,'media_types'=>[],'specialties'=>[],'martyr_ids'=>[],'max_file_bytes'=>min(20,(int)Env::get('MAX_UPLOAD_MB','20'))*1048576];
        if(!$person&&($personName===''||empty($criteria['allow_new_martyr'])))throw new HttpError(422,'شهید موجود را انتخاب کنید؛ پیشنهاد پرونده تازه فقط در فراخوان مجاز پذیرفته می‌شود.');
        if($person&&!empty($criteria['martyr_ids'])&&!in_array($person,$criteria['martyr_ids'],true))throw new HttpError(422,'اثر با شهدای منتخب این فراخوان تطبیق ندارد.');
        $type=(int)($in['type_id']??0);if(!Db::query($this->db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='media_type' AND active=1",[$type])->fetchColumn())throw new HttpError(422,'نوع اثر را انتخاب کنید.');
        $specialty=(int)($in['specialty_id']??0);if(!Db::query($this->db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='art_specialty' AND active=1",[$specialty])->fetchColumn())throw new HttpError(422,'رشته هنری را انتخاب کنید.');
        if(!empty($criteria['media_types'])&&!in_array($type,$criteria['media_types'],true))throw new HttpError(422,'این نوع اثر در فراخوان مجاز نیست.');
        if(!empty($criteria['specialties'])&&!in_array($specialty,$criteria['specialties'],true))throw new HttpError(422,'این رشته هنری در فراخوان مجاز نیست.');
        $payload=['artist_name'=>$artistName,'claimed_artist_id'=>$artist?:null,'artist_details'=>Security::text($in,'artist_details',5000,false),'martyr_name'=>$personName,'martyr_code'=>$code,'martyr_details'=>Security::text($in,'martyr_details',5000,false),'description'=>$description,'source_text'=>Security::text($in,'source_text',2000,false),'rights_text'=>$rights,'download_consent'=>($in['download_consent']??'')==='1','type_id'=>$type,'specialty_id'=>$specialty,'accepted_at'=>date(DATE_ATOM),'call_snapshot'=>$call?['id'=>$call['id'],'title'=>$call['title'],'terms_text'=>$call['terms_text'],'revision'=>$call['revision']]:null];
        return compact('title','payload','verified','person','criteria');
    }
    public function submit(array $in,?string $source,?string $preview=null,int $id=0):int {
        if(MemberSchema::ready($this->db))MemberServices::requireMember($this->db,$this->actor);CommunitySchema::requireReady($this->db);$this->actor=CommunityHistory::requireActor($this->db,$this->actor);Security::limit($this->db,'art-submission',10,3600);
        $created=[];try{return Db::transaction($this->db,function()use($in,$source,$preview,$id,&$created){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$old=$id?Db::query($this->db,'SELECT * FROM art_submissions WHERE id=?'.$lock,[$id])->fetch():null;
            if($id&&(!$old||(int)$old['user_id']!==(int)$this->actor['id']))throw new HttpError(404,'ارسال شما پیدا نشد.');
            if($old&&($old['status']!=='needs_changes'||(int)$old['revision']!==(int)($in['revision']??-1)))throw new HttpError(409,'فقط اثر درخواست‌شده برای اصلاح و با نسخه فعلی قابل ارسال مجدد است.');
            $callId=$old?($old['call_id']?:0):(int)($in['call_id']??0);$call=null;
            if($callId){if($old){$call=Db::query($this->db,"SELECT * FROM art_calls WHERE id=? AND status IN ('published','paused')".$lock,[$callId])->fetch();if(!$call)throw new HttpError(422,'فراخوان در وضعیت پذیرش اصلاح نیست.');$call['criteria']=Protocol::decode($call['criteria']);}else $call=Calls::accepting($this->db,$callId,true);}
            if(!$old){$count=(int)Db::query($this->db,'SELECT COUNT(*) FROM art_submissions WHERE user_id=? AND '.($callId?'call_id=?':'call_id IS NULL'),$callId?[$this->actor['id'],$callId]:[$this->actor['id']])->fetchColumn();if($count>=($call['max_entries']??50))throw new HttpError(422,'تعداد آثار مجاز برای این فراخوان یا حساب تکمیل شده است.');}
            $data=$this->input($in,$call);$asset=null;
            if($source){$asset=IncomingAssets::save($this->db,(int)$this->actor['id'],$source,$preview,(int)$data['criteria']['max_file_bytes']);$created[]=$asset;}
            if(!$old&&!$asset)throw new HttpError(422,'یک فایل اثر انتخاب کنید.');$now=date('Y-m-d H:i:s');$payload=Protocol::encode($data['payload']);
            if($old){Db::query($this->db,"UPDATE art_submissions SET title=?,artist_id=?,martyr_id=?,payload=?,original_path=?,mime=?,sha256=?,status='submitted',review_note='',revision=revision+1,updated_at=? WHERE id=?",[$data['title'],$data['verified'],$data['person']?:null,$payload,$asset['original_path']??$old['original_path'],$asset['mime']??$old['mime'],$asset['sha256']??$old['sha256'],$now,$id]);}
            else $id=Db::insert($this->db,'art_submissions',['call_id'=>$callId?:null,'user_id'=>$this->actor['id'],'artist_id'=>$data['verified'],'martyr_id'=>$data['person']?:null,'title'=>$data['title'],'payload'=>$payload,'original_path'=>$asset['original_path'],'mime'=>$asset['mime'],'sha256'=>$asset['sha256'],'review_note'=>'','created_at'=>$now,'updated_at'=>$now]);
            if($asset){$next=(int)Db::query($this->db,'SELECT COALESCE(MAX(sequence_no),0)+1 FROM submission_assets WHERE submission_id=?',[$id])->fetchColumn();Db::query($this->db,'UPDATE submission_assets SET is_current=0 WHERE submission_id=?',[$id]);Db::insert($this->db,'submission_assets',['submission_id'=>$id,'asset_id'=>$asset['id'],'sequence_no'=>$next,'is_current'=>1]);}
            CommunityHistory::add($this->db,'art_submission',$id,$this->actor,$old['status']??'','submitted',Protocol::encode(['previous_payload'=>$old?Protocol::decode($old['payload']):null,'payload'=>$data['payload'],'asset_id'=>$asset['id']??null]));return $id;
        });}catch(\Throwable $e){IncomingAssets::discard($created);throw $e;}
    }
    public function review(int $id,array $in):void {
        $this->permit('submissions.review');$to=Security::text($in,'status',30);$note=Security::text($in,'note',5000);
        Db::transaction($this->db,function()use($id,$in,$to,$note){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=Db::query($this->db,'SELECT * FROM art_submissions WHERE id=?'.$lock,[$id])->fetch();if(!$row)throw new HttpError(404,'اثر پیدا نشد.');
            $allowed=['submitted'=>['reviewing','needs_changes','rejected'],'reviewing'=>['approved','needs_changes','rejected'],'approved'=>['reviewing','rejected']];if(!in_array($to,$allowed[$row['status']]??[],true)||(int)$row['revision']!==(int)($in['revision']??-1))throw new HttpError(409,'وضعیت یا نسخه اثر تغییر کرده است.');
            $artist=(int)($in['artist_id']??$row['artist_id']);$person=(int)($in['martyr_id']??$row['martyr_id']);$data=Protocol::decode($row['payload']);
            if($artist&&!Db::query($this->db,'SELECT id FROM artists WHERE id=? AND archived=0',[$artist])->fetchColumn())throw new HttpError(422,'هنرمند پیدا نشد.');if($person&&!Db::query($this->db,'SELECT id FROM martyrs WHERE id=?',[$person])->fetchColumn())throw new HttpError(422,'شهید پیدا نشد.');
            if($to==='approved'&&(!$artist||!$person||($in['identity_ack']??'')!=='1'))throw new HttpError(422,'تطبیق پرونده شهید و هنرمند و تأیید انتساب برای تصویب اثر لازم است.');
            if($to==='approved'){$data['reviewed_rights']=Security::text($in,'reviewed_rights',5000);$data['creator_role']=Security::text($in,'creator_role',100);$data['review_download']=!empty($in['allow_download'])&&!empty($data['download_consent']);}
            Db::query($this->db,'UPDATE art_submissions SET status=?,artist_id=?,martyr_id=?,payload=?,review_note=?,reviewed_by=?,revision=revision+1,updated_at=? WHERE id=?',[$to,$artist?:null,$person?:null,Protocol::encode($data),$note,$this->actor['id'],date('Y-m-d H:i:s'),$id]);CommunityHistory::add($this->db,'art_submission',$id,$this->actor,$row['status'],$to,Protocol::encode(['note'=>$note,'artist_id'=>$artist,'martyr_id'=>$person,'reviewed_rights'=>$data['reviewed_rights']??null]));
        });
    }
    public function publish(int $id,array $in,?string $verifiedPreview=null,bool $direct=false):int {
        if(MemberSchema::ready($this->db))$direct=false;
        $this->permit('submissions.publish');if(!$direct)$this->permit('media.publish');if(!$direct&&$verifiedPreview&&($in['verified_visual_ack']??'')!=='1')throw new HttpError(422,'پیش‌نمایش بازتولیدشده از اصل فایل را پیش از انتشار بررسی کنید.');if(!$direct&&($in['publication_ack']??'')!=='1')throw new HttpError(422,'انتشار اثر و حقوق و انتساب آن را صریحاً تأیید کنید.');if($direct)AdminPublishing::guard($this->db,$this->actor,'media');
        $created=[];try{return Db::transaction($this->db,function()use($id,$in,$verifiedPreview,$direct,&$created){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=Db::query($this->db,'SELECT * FROM art_submissions WHERE id=?'.$lock,[$id])->fetch();if(!$row)throw new HttpError(404,'اثر پیدا نشد.');if($row['status']==='published'&&$row['image_id'])return (int)$row['image_id'];
            if((!$direct&&$row['status']!=='approved')||(int)$row['revision']!==(int)($in['revision']??-1))throw new HttpError(409,'نسخه اثر تغییر کرده است؛ صفحه را دوباره باز کنید.');
            if($direct){$data=Protocol::decode($row['payload']);$row['martyr_id']=(int)($in['martyr_id']??$row['martyr_id']);$row['artist_id']=(int)($in['artist_id']??$row['artist_id']);if(!$row['martyr_id']||!$row['artist_id'])throw new HttpError(422,'شهید و هنرمند مرتبط را انتخاب کنید.');
                if(!Db::query($this->db,'SELECT published FROM martyrs WHERE id=?',[$row['martyr_id']])->fetchColumn())AdminPublishing::martyr($this->db,$this->actor,$row['martyr_id']);if(!Db::query($this->db,'SELECT id FROM artists WHERE id=? AND public_visible=1 AND archived=0',[$row['artist_id']])->fetchColumn())AdminPublishing::artist($this->db,$this->actor,$row['artist_id']);
                $data['reviewed_rights']=array_key_exists('reviewed_rights',$in)?Security::text($in,'reviewed_rights',5000,false):($data['rights_text']??'');$data['creator_role']=Security::text($in,'creator_role',100,false)?:'پدیدآورنده';$data['review_download']=!empty($in['allow_download'])&&!empty($data['download_consent']);$row['payload']=Protocol::encode($data);
            }
            if(!Db::query($this->db,'SELECT id FROM martyrs WHERE id=? AND published=1',[$row['martyr_id']])->fetchColumn())throw new HttpError(422,'پرونده شهید باید پیش از اثر منتشر شود.');
            if(!Db::query($this->db,'SELECT id FROM artists WHERE id=? AND public_visible=1 AND archived=0',[$row['artist_id']])->fetchColumn())throw new HttpError(422,'پروفایل هنرمند باید پیش از اثر منتشر شود.');
            $asset=Db::query($this->db,'SELECT a.* FROM submission_assets s JOIN incoming_assets a ON a.id=s.asset_id WHERE s.submission_id=? AND s.is_current=1',[$id])->fetch();if(!$asset)throw new HttpError(422,'اصل فایل اثر پیدا نشد.');$data=Protocol::decode($row['payload']);IncomingAssets::verify($asset);
            $existing=Db::query($this->db,'SELECT id FROM images WHERE sha256=?',[$asset['sha256']])->fetchColumn();
            if($existing){
                $public=(new Archive($this->db))->image((int)$existing);$matching=$public&&Db::query($this->db,'SELECT image_id FROM media_public_martyrs WHERE image_id=? AND martyr_id=?',[$existing,$row['martyr_id']])->fetchColumn()&&Db::query($this->db,'SELECT image_id FROM media_public_artists WHERE image_id=? AND artist_id=?',[$existing,$row['artist_id']])->fetchColumn();
                if((!$direct&&($in['existing_ack']??'')!=='1')||!$matching)throw new HttpError(409,'اصل فایل قبلاً در آرشیو است؛ ابتدا انتساب رسانه موجود را بررسی کنید. بدون تأیید، بازنویسی انجام نمی‌شود.');$image=(int)$existing;
            }else{
                $image=IncomingAssets::promote($this->db,$asset,(int)$row['martyr_id'],$row['title'],(int)$data['type_id'],$created,$verifiedPreview);$media=new Media($this->db,$this->actor);$current=$media->get($image);
                $media->save($image,['revision'=>$current['revision'],'title'=>$row['title'],'type_id'=>$data['type_id'],'description'=>$data['description'],'source_text'=>$data['source_text'],'rights_text'=>$data['reviewed_rights'],'download_allowed'=>!empty($data['review_download'])?'1':'','martyrs'=>[$row['martyr_id']],'creators'=>[['artist_id'=>$row['artist_id'],'role'=>$data['creator_role']]],'reason'=>'انتقال اثر تأییدشده شماره '.$id]);
                if($direct)AdminPublishing::media($this->db,$this->actor,$image);else foreach(['pending','approved','published'] as $status)$media->transition($image,$status,['revision'=>$media->get($image)['revision'],'publication_ack'=>'1','reason'=>'بررسی اثر شماره '.$id]);
            }
            Db::query($this->db,"UPDATE art_submissions SET status='published',image_id=?,artist_id=?,martyr_id=?,payload=?,reviewed_by=?,revision=revision+1,updated_at=? WHERE id=?",[$image,$row['artist_id'],$row['martyr_id'],$row['payload'],$this->actor['id'],date('Y-m-d H:i:s'),$id]);CommunityHistory::add($this->db,'art_submission',$id,$this->actor,$row['status'],'published',Protocol::encode(['image_id'=>$image,'existing'=>$existing?:null]));return $image;
        });}catch(\Throwable $e){IncomingAssets::discardPaths($created);throw $e;}
    }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Media {
    public function __construct(private PDO $db,private array $actor){}
    private function permit(string $p):void{if(!Permissions::allows($this->db,$this->actor,$p))throw new HttpError(403,'مجوز مدیریت رسانه را ندارید.');}
    public function get(int $id):array|false {
        $this->permit('media.view');
        $im=Db::query($this->db,'SELECT i.*,d.title AS draft_title,d.type_id,d.status,d.description,d.source_text,d.rights_text,d.download_allowed,d.sort_order,d.revision FROM images i JOIN media_details d ON d.image_id=i.id WHERE i.id=?',[$id])->fetch();if(!$im)return false;$im['public_title']=$im['title'];$im['title']=$im['draft_title'];
        $im['martyrs']=array_map('intval',Db::query($this->db,'SELECT martyr_id FROM media_martyrs WHERE image_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN));
        $im['featured_for']=(int)Db::query($this->db,'SELECT martyr_id FROM media_martyrs WHERE image_id=? AND is_featured=1',[$id])->fetchColumn();
        $im['creators']=Db::query($this->db,'SELECT ma.*,a.display_name,a.status AS artist_status FROM media_artists ma JOIN artists a ON a.id=ma.artist_id WHERE ma.image_id=? ORDER BY credit_order,artist_id',[$id])->fetchAll();return $im;
    }
    public function save(int $id,array $in):void {
        $this->permit('media.edit');$before=$this->get($id);if(!$before)throw new HttpError(404,'رسانه پیدا نشد.');
        $type=Db::query($this->db,"SELECT * FROM protocol_terms WHERE id=? AND taxonomy='media_type' AND active=1",[(int)($in['type_id']??0)])->fetch();if(!$type)throw new HttpError(422,'نوع رسانه لازم است.');
        $martyrs=array_values(array_unique(array_map('intval',(array)($in['martyrs']??[]))));if(!$martyrs||count($martyrs)>100)throw new HttpError(422,'بین ۱ تا ۱۰۰ شهید مرتبط انتخاب کنید.');foreach($martyrs as $person)if(!Db::query($this->db,'SELECT id FROM martyrs WHERE id=?',[$person])->fetchColumn())throw new HttpError(422,'شهید مرتبط پیدا نشد.');
        $creators=$in['creators']??[];if(!is_array($creators)||count($creators)>30)throw new HttpError(422,'حداکثر ۳۰ پدیدآورنده مجاز است.');$credits=[];foreach($creators as $index=>$creator){$artist=(int)($creator['artist_id']??0);if(!$artist)continue;if(!Db::query($this->db,'SELECT id FROM artists WHERE id=? AND archived=0',[$artist])->fetchColumn())throw new HttpError(422,'هنرمند پیدا نشد.');$role=Security::text($creator,'role',100);$credits[$artist.':'.$role]=['artist'=>$artist,'role'=>$role,'order'=>(int)$index];}
        $title=Security::text($in,'title',255);$description=Security::text($in,'description',10000,false);$source=Security::text($in,'source_text',2000,false);$rights=Security::text($in,'rights_text',5000,false);$reason=Security::text($in,'reason',2000,false);
        $download=!empty($in['download_allowed'])?1:0;$sort=(int)($in['sort_order']??0);$featured=(int)($in['featured_for']??0);if($featured&&!in_array($featured,$martyrs,true))throw new HttpError(422,'تصویر شاخص باید برای یکی از شهدای مرتبط باشد.');
        Db::transaction($this->db,function()use($id,$in,$before,$type,$martyrs,$credits,$title,$description,$source,$rights,$reason,$download,$sort,$featured){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$revision=Db::query($this->db,'SELECT revision FROM media_details WHERE image_id=?'.$lock,[$id])->fetchColumn();if((int)$revision!==(int)($in['revision']??-1))throw new HttpError(409,'رسانه در صفحه دیگری تغییر کرده است.');
            // Draft metadata and reviewed public projections are separate.
            Db::query($this->db,"UPDATE media_details SET title=?,type_id=?,description=?,source_text=?,rights_text=?,download_allowed=?,sort_order=?,status='draft',revision=revision+1,updated_at=? WHERE image_id=?",[$title,$type['id'],$description,$source,$rights,$download,$sort,date('Y-m-d H:i:s'),$id]);
            // Public image fields and relationships are updated only by explicit reviewed publication.
            Db::query($this->db,'DELETE FROM media_martyrs WHERE image_id=?',[$id]);foreach($martyrs as $person){if($featured===$person)Db::query($this->db,'UPDATE media_martyrs SET is_featured=0 WHERE martyr_id=?',[$person]);Db::insert($this->db,'media_martyrs',['image_id'=>$id,'martyr_id'=>$person,'is_featured'=>$featured===$person?1:0]);}
            Db::query($this->db,'DELETE FROM media_artists WHERE image_id=?',[$id]);foreach($credits as $credit)Db::insert($this->db,'media_artists',['image_id'=>$id,'artist_id'=>$credit['artist'],'creator_role'=>$credit['role'],'credit_order'=>$credit['order']]);
            $this->history($id,'metadata.edit',$before,['type_id'=>$type['id'],'martyrs'=>$martyrs,'creators'=>$credits,'title'=>$title,'download'=>$download],$reason);
        });
    }
    public function transition(int $id,string $to,array $in):void {
        $this->permit($to==='published'?'media.publish':($to==='pending'?'media.edit':'media.review'));$a=$this->get($id);if(!$a)throw new HttpError(404,'رسانه پیدا نشد.');
        $allowed=['draft'=>['pending','archived'],'pending'=>['approved','needs_changes','rejected','archived'],'approved'=>['published','needs_changes','archived'],'published'=>['pending','archived'],'needs_changes'=>['pending','archived'],'rejected'=>['draft','archived'],'archived'=>['draft']];
        if(!in_array($to,$allowed[$a['status']]??[],true))throw new HttpError(422,'تغییر وضعیت رسانه مجاز نیست.');
        $reason=Security::text($in,'reason',2000,in_array($to,['needs_changes','rejected','archived'],true));
        if($to==='published'&&(($in['publication_ack']??'')!=='1'||$a['rights_text']===''))throw new HttpError(422,'انتشار به مجوز/شرایط حقوقی ثبت‌شده و تأیید صریح نیاز دارد.');
        Db::transaction($this->db,function()use($id,$to,$in,$a,$reason){
            $changed=Db::query($this->db,'UPDATE media_details SET status=?,reviewed_by=?,reviewed_at=?,revision=revision+1,updated_at=? WHERE image_id=? AND revision=?',[$to,$this->actor['id'],date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$id,(int)($in['revision']??-1)])->rowCount();if(!$changed)throw new HttpError(409,'نسخه رسانه تغییر کرده است.');
            if($to==='published'){
                $type=Db::query($this->db,"SELECT * FROM protocol_terms WHERE id=? AND taxonomy='media_type'",[$a['type_id']])->fetch();if(!$type)throw new HttpError(422,'نوع رسانه معتبر نیست.');
                $kind=(Protocol::decode($type['metadata'])['artwork']??false)?'design':(in_array($type['slug'],['document','will','handwriting'],true)?'document':'photo');
                Db::query($this->db,'UPDATE images SET title=?,kind=?,license_text=?,published=1 WHERE id=?',[$a['title'],$kind,$a['rights_text'],$id]);
                $payload=Protocol::encode(['description'=>$a['description'],'source_text'=>$a['source_text'],'rights_text'=>$a['rights_text']]);
                if(Db::query($this->db,'SELECT image_id FROM media_publication WHERE image_id=?',[$id])->fetchColumn())Db::query($this->db,'UPDATE media_publication SET type_id=?,download_allowed=?,sort_order=?,payload=?,published_at=? WHERE image_id=?',[$a['type_id'],$a['download_allowed'],$a['sort_order'],$payload,date('Y-m-d H:i:s'),$id]);
                else Db::insert($this->db,'media_publication',['image_id'=>$id,'type_id'=>$a['type_id'],'download_allowed'=>$a['download_allowed'],'sort_order'=>$a['sort_order'],'payload'=>$payload,'published_at'=>date('Y-m-d H:i:s')]);
                Db::query($this->db,'DELETE FROM media_public_martyrs WHERE image_id=?',[$id]);
                foreach(Db::query($this->db,'SELECT * FROM media_martyrs WHERE image_id=?',[$id])->fetchAll() as $person){if($person['is_featured'])Db::query($this->db,'UPDATE media_public_martyrs SET is_featured=0 WHERE martyr_id=?',[$person['martyr_id']]);Db::insert($this->db,'media_public_martyrs',$person);}
                Db::query($this->db,'DELETE FROM media_public_artists WHERE image_id=?',[$id]);
                foreach($a['creators'] as $creator){$artist=Db::query($this->db,"SELECT public_snapshot FROM artists WHERE id=? AND public_visible=1 AND archived=0",[$creator['artist_id']])->fetchColumn();if(!$artist)throw new HttpError(422,'پروفایل هنرمند مرتبط پیش از انتشار اثر باید تأیید و منتشر شود.');$name=Protocol::decode($artist)['display_name']??'';
                    Db::insert($this->db,'media_public_artists',['image_id'=>$id,'artist_id'=>$creator['artist_id'],'creator_role'=>$creator['creator_role'],'credit_order'=>$creator['credit_order'],'credit_name'=>$name]);}
            }elseif($to==='archived')Db::query($this->db,'UPDATE images SET published=0 WHERE id=?',[$id]);
            $this->history($id,'status',$a['status'],$to,$reason);
        });
    }
    private function history(int $id,string $action,mixed $before,mixed $after,string $reason):void{Db::insert($this->db,'media_changes',['image_id'=>$id,'user_id'=>$this->actor['id'],'action'=>$action,'before_value'=>Protocol::encode($before),'after_value'=>Protocol::encode($after),'reason'=>$reason,'created_at'=>date('Y-m-d H:i:s')]);Db::audit($this->db,'media.'.$action,(string)$id);}
    public static function initialize(PDO $db,int $id,int $person,string $kind,int $typeId=0):void {
        if(!PlatformSchema::ready($db))return;
        if($typeId&&!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='media_type' AND active=1",[$typeId])->fetchColumn())throw new HttpError(422,'نوع رسانه انتخاب‌شده معتبر نیست.');
        if($typeId){$type=Db::query($db,'SELECT * FROM protocol_terms WHERE id=?',[$typeId])->fetch();$kind=(Protocol::decode($type['metadata'])['artwork']??false)?'design':(in_array($type['slug'],['document','will','handwriting'],true)?'document':'photo');Db::query($db,'UPDATE images SET kind=? WHERE id=?',[$kind,$id]);}
        Db::insert($db,'media_details',['image_id'=>$id,'title'=>(string)Db::query($db,'SELECT title FROM images WHERE id=?',[$id])->fetchColumn(),'type_id'=>$typeId?:PlatformSchema::termId($db,'media_type',match($kind){'photo'=>'photo','document'=>'document',default=>'portrait'}),'status'=>'draft','description'=>'','source_text'=>'','rights_text'=>(string)Db::query($db,'SELECT license_text FROM images WHERE id=?',[$id])->fetchColumn(),'download_allowed'=>$kind==='design'?1:0,'created_by'=>$_SESSION['user_id']??null,'updated_at'=>date('Y-m-d H:i:s')]);
        Db::insert($db,'media_martyrs',['image_id'=>$id,'martyr_id'=>$person]);Db::query($db,'UPDATE images SET published=0 WHERE id=?',[$id]);
    }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Calls {
    public function __construct(private PDO $db,private array $actor){}
    private function permit(string $permission):void {if(!Permissions::allows($this->db,$this->actor,$permission))throw new HttpError(403,'مجوز مدیریت فراخوان را ندارید.');}
    private function date(array $in,string $key):?string {
        $value=Security::text($in,$key,30,false);if($value==='')return null;$value=str_replace('T',' ',$value);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i',$value);if(!$date||$date->format('Y-m-d H:i')!==$value)throw new HttpError(422,'تاریخ میلادی و ساعت تهران را بررسی کنید.');return $date->format('Y-m-d H:i:s');
    }
    private function ids(array $in,string $key,string $taxonomy):array {
        $values=$in[$key]??[];if(!is_array($values)||count($values)>100)throw new HttpError(422,'گزینه‌های فراخوان بیش از حد مجاز است.');$ids=[];
        foreach($values as $value){if(!is_scalar($value)||!ctype_digit((string)$value))throw new HttpError(422,'گزینه معتبر انتخاب کنید.');$id=(int)$value;if(!Db::query($this->db,'SELECT id FROM protocol_terms WHERE id=? AND taxonomy=? AND active=1',[$id,$taxonomy])->fetchColumn())throw new HttpError(422,'رشته یا نوع رسانه پیدا نشد.');$ids[]=$id;}return array_values(array_unique($ids));
    }
    public function get(int $id,bool $public=false):array|false {
        if(!$public)$this->permit('calls.view');$row=Db::query($this->db,'SELECT * FROM art_calls WHERE id=?'.($public?" AND status='published'":''),[$id])->fetch();if(!$row)return false;$row['criteria']=Protocol::decode($row['criteria']);
        $row['martyrs']=Db::query($this->db,'SELECT m.id,m.code,m.first_name,m.last_name FROM call_martyrs cm JOIN martyrs m ON m.id=cm.martyr_id WHERE cm.call_id=?'.($public?' AND m.published=1':'').' ORDER BY m.id',[$id])->fetchAll();
        $row['terms']=Db::query($this->db,'SELECT t.id,t.title,t.taxonomy,c.kind FROM call_terms c JOIN protocol_terms t ON t.id=c.term_id WHERE c.call_id=? ORDER BY t.title',[$id])->fetchAll();
        if($public)unset($row['created_by']);return $row;
    }
    public function listing(array $f=[],bool $public=false):array {
        if(!$public)$this->permit('calls.view');$where=$public?"c.status='published'":'1=1';$args=[];$q=Security::text($f,'q',150,false);
        if($q!==''){$where.=' AND c.title LIKE ?';$args[]='%'.$q.'%';}if(!$public&&!empty($f['status'])){if($f['status']==='unpublished')$where.=" AND c.status NOT IN ('published','paused','archived')";else{$where.=' AND c.status=?';$args[]=Security::text($f,'status',30);}}
        $result=AdminQueries::paginate($this->db,'SELECT c.id,c.title,c.slug,c.status,c.start_at,c.end_at,c.poster_asset_id,c.created_at,c.updated_at,(SELECT COUNT(*) FROM art_submissions s WHERE s.call_id=c.id) AS submission_count','FROM art_calls c',$where,$args,['new'=>'c.id DESC','deadline'=>'c.end_at,c.id DESC'],$f,12);
        if($public)foreach($result['items'] as &$row)unset($row['submission_count']);return $result;
    }
    public function save(array $in,int $id=0,?string $poster=null):int {
        CommunitySchema::requireReady($this->db);$this->permit('calls.edit');$old=$id?$this->get($id):null;if($id&&!$old)throw new HttpError(404,'فراخوان پیدا نشد.');
        $title=Security::text($in,'title',255);$description=Security::text($in,'description',20000);$terms=Security::text($in,'terms_text',20000);$slug=Security::text($in,'slug',150,false)?:($old['slug']??'call-'.bin2hex(random_bytes(8)));
        if(!preg_match('/^[a-z0-9][a-z0-9-]{0,149}$/D',$slug))throw new HttpError(422,'نامک لاتین معتبر لازم است.');if(Db::query($this->db,'SELECT id FROM art_calls WHERE slug=? AND id<>?',[$slug,$id])->fetchColumn())throw new HttpError(409,'نامک تکراری است.');
        $start=$this->date($in,'start_at');$end=$this->date($in,'end_at');if($start&&$end&&$start>$end)throw new HttpError(422,'پایان فراخوان پیش از شروع است.');
        $max=filter_var($in['max_entries']??5,FILTER_VALIDATE_INT);$mb=filter_var($in['max_file_mb']??20,FILTER_VALIDATE_INT);if(!$max||$max>50||!$mb||$mb>min(20,(int)Env::get('MAX_UPLOAD_MB','20')))throw new HttpError(422,'تعداد آثار یا حجم فایل معتبر نیست.');
        $specialties=$this->ids($in,'specialties','art_specialty');$types=$this->ids($in,'media_types','media_type');
        $martyrs=[];foreach((array)($in['martyrs']??[]) as $person){if(!is_scalar($person)||!ctype_digit((string)$person)||count($martyrs)>=200)throw new HttpError(422,'شهدای مرتبط معتبر نیستند.');$person=(int)$person;if(!Db::query($this->db,'SELECT id FROM martyrs WHERE id=?',[$person])->fetchColumn())throw new HttpError(422,'شهید انتخاب‌شده پیدا نشد.');$martyrs[]=$person;}$martyrs=array_values(array_unique($martyrs));
        $criteria=['max_file_bytes'=>$mb*1048576,'allow_new_martyr'=>!empty($in['allow_new_martyr']),'specialties'=>$specialties,'media_types'=>$types,'martyr_ids'=>$martyrs,'mimes'=>['image/jpeg','image/png','image/webp']];$asset=null;
        try{return Db::transaction($this->db,function()use($in,$old,$id,$title,$description,$terms,$slug,$start,$end,$max,$criteria,$specialties,$types,$martyrs,$poster,&$asset){
            $now=date('Y-m-d H:i:s');$posterId=$old['poster_asset_id']??null;if($poster){$asset=Assets::put($this->db,$poster,(int)$this->actor['id']);$posterId=$asset['id'];}if(!empty($in['remove_poster']))$posterId=null;
            if($id){$changed=Db::query($this->db,"UPDATE art_calls SET title=?,slug=?,description=?,terms_text=?,start_at=?,end_at=?,max_entries=?,poster_asset_id=?,criteria=?,status='draft',revision=revision+1,updated_at=? WHERE id=? AND revision=?",[$title,$slug,$description,$terms,$start,$end,$max,$posterId,Protocol::encode($criteria),$now,$id,(int)($in['revision']??-1)])->rowCount();if(!$changed)throw new HttpError(409,'فراخوان در صفحه دیگری تغییر کرده است.');}
            else $id=Db::insert($this->db,'art_calls',['title'=>$title,'slug'=>$slug,'description'=>$description,'terms_text'=>$terms,'start_at'=>$start,'end_at'=>$end,'max_entries'=>$max,'poster_asset_id'=>$posterId,'criteria'=>Protocol::encode($criteria),'created_by'=>$this->actor['id'],'created_at'=>$now,'updated_at'=>$now]);
            Db::query($this->db,'DELETE FROM call_terms WHERE call_id=?',[$id]);foreach(['specialty'=>$specialties,'media_type'=>$types] as $kind=>$ids)foreach($ids as $term)Db::insert($this->db,'call_terms',['call_id'=>$id,'term_id'=>$term,'kind'=>$kind]);
            Db::query($this->db,'DELETE FROM call_martyrs WHERE call_id=?',[$id]);foreach($martyrs as $person)Db::insert($this->db,'call_martyrs',['call_id'=>$id,'martyr_id'=>$person]);
            CommunityHistory::add($this->db,'art_call',$id,$this->actor,$old['status']??'','draft',Protocol::encode(['title'=>$title,'reason'=>Security::text($in,'reason',2000,false)]));return $id;
        });}catch(\Throwable $e){if($asset)IncomingAssets::discardPaths([$asset['path']]);throw $e;}
    }
    public function transition(int $id,array $in):void {
        $this->permit('calls.publish');$to=Security::text($in,'status',30);if(!in_array($to,['published','paused','archived','draft'],true))throw new HttpError(422,'وضعیت معتبر نیست.');
        $note=Security::text($in,'note',5000,false);if($to==='published'&&($in['publication_ack']??'')!=='1')throw new HttpError(422,'شرایط فراخوان و انتشار عمومی آن را تأیید کنید.');
        Db::transaction($this->db,function()use($id,$in,$to,$note){
            $old=$this->get($id);if(!$old)throw new HttpError(404,'فراخوان پیدا نشد.');if($to==='published')foreach($old['martyrs'] as $person)if(!Db::query($this->db,'SELECT id FROM martyrs WHERE id=? AND published=1',[$person['id']])->fetchColumn())throw new HttpError(422,'پرونده شهید منتخب پیش از فراخوان باید منتشر شود.');
            $n=Db::query($this->db,'UPDATE art_calls SET status=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?',[$to,date('Y-m-d H:i:s'),$id,(int)($in['revision']??-1)])->rowCount();if(!$n)throw new HttpError(409,'نسخه فراخوان تغییر کرده است.');
            CommunityHistory::add($this->db,'art_call',$id,$this->actor,$old['status'],$to,$note);
        });
    }
    public static function accepting(PDO $db,int $id,bool $lock=false):array {
        $suffix=$lock&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$call=Db::query($db,'SELECT * FROM art_calls WHERE id=?'.$suffix,[$id])->fetch();$now=date('Y-m-d H:i:s');
        if(!$call||$call['status']!=='published'||($call['start_at']&&$call['start_at']>$now)||($call['end_at']&&$call['end_at']<$now))throw new HttpError(422,'این فراخوان در زمان فعلی پذیرش اثر ندارد.');$call['criteria']=Protocol::decode($call['criteria']);return $call;
    }
}

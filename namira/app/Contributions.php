<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Contributions {
    public const KINDS=['correction'=>'اصلاح اطلاعات','biography'=>'اطلاعات یا زندگی‌نامه','memory'=>'خاطره','photo'=>'عکس واقعی شهید','grave'=>'تصویر مزار','will'=>'وصیت‌نامه','handwriting'=>'دست‌نوشته','document'=>'سند','new_martyr'=>'پیشنهاد پرونده جدید'];
    public function __construct(private PDO $db,private array $actor){}
    private function permit(string $permission):void {if(!Permissions::allows($this->db,$this->actor,$permission))throw new HttpError(403,'مجوز رسیدگی به مشارکت را ندارید.');}
    public function get(int $id):array|false {
        $row=Db::query($this->db,'SELECT * FROM public_contributions WHERE id=?',[$id])->fetch();if(!$row)return false;if((int)$row['user_id']!==(int)$this->actor['id'])$this->permit('contributions.view');
        $row['data']=Protocol::decode($row['payload']);$row['contact']=Protocol::decode($row['contact_private']);
        $row['files']=Db::query($this->db,'SELECT f.*,a.asset_id,i.bytes,i.width,i.height,i.preview_path FROM contribution_files f LEFT JOIN contribution_file_assets a ON a.file_id=f.id LEFT JOIN incoming_assets i ON i.id=a.asset_id WHERE f.contribution_id=? ORDER BY f.id',[$id])->fetchAll();return $row;
    }
    public function listing(array $f=[],bool $own=false):array {
        if(!$own)$this->permit('contributions.view');$where=$own?'c.user_id=?':'1=1';$args=$own?[$this->actor['id']]:[];
        foreach(['status','kind'] as $key)if(($f[$key]??'')!==''){$where.=' AND c.'.$key.'=?';$args[]=Security::text($f,$key,40);}
        if(!empty($f['martyr_id'])){$where.=' AND c.martyr_id=?';$args[]=(int)$f['martyr_id'];}
        return AdminQueries::paginate($this->db,'SELECT c.id,c.user_id,c.martyr_id,c.kind,c.status,c.revision,c.tracking,c.created_at,c.updated_at,u.email','FROM public_contributions c LEFT JOIN users u ON u.id=c.user_id',$where,$args,['new'=>'c.id DESC','updated'=>'c.updated_at DESC'],$f);
    }
    public function submit(array $in,array $files=[],int $id=0):int {
        CommunitySchema::requireReady($this->db);$this->actor=CommunityHistory::requireActor($this->db,$this->actor);Security::limit($this->db,'contribution',8,3600);
        if(($in['consent']??'')!=='1')throw new HttpError(422,'رضایت برای بررسی و تماس درباره مشارکت لازم است.');
        $kind=Security::text($in,'kind',40);if(!isset(self::KINDS[$kind]))throw new HttpError(422,'نوع مشارکت معتبر نیست.');$person=(int)($in['martyr_id']??0);$name=Security::text($in,'martyr_name',255,false);$code=Security::normalize(Security::text($in,'martyr_code',80,false));
        if($person&&!Db::query($this->db,'SELECT id FROM martyrs WHERE id=? AND published=1',[$person])->fetchColumn())throw new HttpError(422,'پرونده عمومی شهید پیدا نشد.');if(!$person&&$name==='')throw new HttpError(422,'شهید مرتبط یا نام پیشنهادی را مشخص کنید.');
        $text=Security::text($in,'text',30000,false);if(count($files)>5)throw new HttpError(422,'در هر نوبت حداکثر ۵ فایل بفرستید.');
        $phone=Security::text($in,'phone',30,false);if($phone!=='')$phone=Security::phone($phone);
        $payload=['martyr_name'=>$name,'martyr_code'=>$code,'text'=>$text,'source_text'=>Security::text($in,'source_text',5000,false),'relation'=>Security::text($in,'relation',2000,false),'rights_text'=>Security::text($in,'rights_text',5000,false),'download_consent'=>($in['download_consent']??'')==='1','accepted_at'=>date(DATE_ATOM)];
        $contact=Protocol::encode(['email'=>$this->actor['email'],'phone'=>$phone]);$created=[];
        try{return Db::transaction($this->db,function()use($in,$files,$id,$kind,$person,$payload,$contact,&$created){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$old=$id?Db::query($this->db,'SELECT * FROM public_contributions WHERE id=?'.$lock,[$id])->fetch():null;
            if($id&&(!$old||(int)$old['user_id']!==(int)$this->actor['id']))throw new HttpError(404,'مشارکت شما پیدا نشد.');
            if($old&&($old['status']!=='needs_changes'||(int)$old['revision']!==(int)($in['revision']??-1)))throw new HttpError(409,'فقط مشارکت درخواست‌شده برای اصلاح، با نسخه فعلی قابل ارسال مجدد است.');
            $count=$old?(int)Db::query($this->db,'SELECT COUNT(*) FROM contribution_files WHERE contribution_id=?',[$id])->fetchColumn():0;if(($payload['text']??'')===''&&!$files&&!$count)throw new HttpError(422,'توضیح یا فایل پیوست لازم است.');if($count+count($files)>15)throw new HttpError(422,'حداکثر ۱۵ پیوست در سابقه یک مشارکت نگهداری می‌شود.');
            if(in_array($kind,['photo','grave','will','handwriting','document'],true)&&!$files&&!$count)throw new HttpError(422,'برای این نوع مشارکت یک تصویر پیوست کنید.');
            $now=date('Y-m-d H:i:s');
            if($old)Db::query($this->db,"UPDATE public_contributions SET martyr_id=?,kind=?,payload=?,contact_private=?,status='submitted',review_note='',revision=revision+1,updated_at=? WHERE id=?",[$person?:null,$kind,Protocol::encode($payload),$contact,$now,$id]);
            else $id=Db::insert($this->db,'public_contributions',['user_id'=>$this->actor['id'],'martyr_id'=>$person?:null,'kind'=>$kind,'payload'=>Protocol::encode($payload),'contact_private'=>$contact,'tracking'=>bin2hex(random_bytes(24)),'review_note'=>'','created_at'=>$now,'updated_at'=>$now]);
            foreach($files as $file){$asset=IncomingAssets::save($this->db,(int)$this->actor['id'],$file['source'],$file['preview']??null);$created[]=$asset;$fid=Db::insert($this->db,'contribution_files',['contribution_id'=>$id,'path'=>$asset['original_path'],'mime'=>$asset['mime'],'sha256'=>$asset['sha256']]);Db::insert($this->db,'contribution_file_assets',['file_id'=>$fid,'asset_id'=>$asset['id']]);}
            CommunityHistory::add($this->db,'contribution',$id,$this->actor,$old['status']??'','submitted',Protocol::encode(['previous_payload'=>$old?Protocol::decode($old['payload']):null,'payload'=>$payload]));return $id;
        });}catch(\Throwable $e){IncomingAssets::discard($created);throw $e;}
    }
    public function review(int $id,array $in):void {
        $this->permit('contributions.review');$to=Security::text($in,'status',30);$note=Security::text($in,'note',5000);
        Db::transaction($this->db,function()use($id,$in,$to,$note){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=Db::query($this->db,'SELECT * FROM public_contributions WHERE id=?'.$lock,[$id])->fetch();if(!$row)throw new HttpError(404,'مشارکت پیدا نشد.');
            $allowed=['submitted'=>['reviewing','needs_changes','rejected'],'reviewing'=>['approved','partial_approved','needs_changes','rejected'],'approved'=>['reviewing'],'partial_approved'=>['reviewing']];
            if(!in_array($to,$allowed[$row['status']]??[],true)||(int)$row['revision']!==(int)($in['revision']??-1))throw new HttpError(409,'وضعیت یا نسخه مشارکت تغییر کرده است.');
            $person=(int)($in['martyr_id']??$row['martyr_id']);if($person&&!Db::query($this->db,'SELECT id FROM martyrs WHERE id=?',[$person])->fetchColumn())throw new HttpError(422,'پرونده مقصد پیدا نشد.');
            Db::query($this->db,'UPDATE public_contributions SET status=?,martyr_id=?,review_note=?,reviewed_by=?,revision=revision+1,updated_at=? WHERE id=?',[$to,$person?:null,$note,$this->actor['id'],date('Y-m-d H:i:s'),$id]);CommunityHistory::add($this->db,'contribution',$id,$this->actor,$row['status'],$to,$note);
        });
    }
    public function merge(int $id,array $in,array $verifiedPreviews=[]):array {
        $this->permit('contributions.merge');$this->permit('martyrs.edit');if(($in['merge_ack']??'')!=='1')throw new HttpError(422,'تطبیق اطلاعات و انتقال بخش انتخاب‌شده به پیش‌نویس را تأیید کنید.');
        $key=Security::text($in,'request_key',64);if(!preg_match('/^[a-f0-9]{32,64}$/D',$key))throw new HttpError(422,'شناسه عملیات معتبر نیست.');$reason=Security::text($in,'reason',5000);$created=[];
        try{return Db::transaction($this->db,function()use($id,$in,$key,$reason,$verifiedPreviews,&$created){
            $previous=Db::query($this->db,'SELECT * FROM contribution_merges WHERE request_key=?',[$key])->fetch();if($previous){if((int)$previous['contribution_id']!==$id)throw new HttpError(409,'شناسه عملیات برای مشارکت دیگری استفاده شده است.');return ['martyr_id'=>(int)$previous['martyr_id'],'media_ids'=>Protocol::decode($previous['media_ids'])];}
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=Db::query($this->db,'SELECT * FROM public_contributions WHERE id=?'.$lock,[$id])->fetch();
            if(!$row||!in_array($row['status'],['approved','partial_approved'],true)||(int)$row['revision']!==(int)($in['revision']??-1))throw new HttpError(409,'مشارکت تأییدشده با نسخه فعلی لازم است.');
            $person=(int)($in['martyr_id']??$row['martyr_id']);$protocol=new Protocol($this->db,$this->actor);$new=false;
            if(!$person){
                if(($in['create_new']??'')!=='1')throw new HttpError(422,'پرونده مقصد را انتخاب کنید یا ساخت پیش‌نویس جدید را تأیید کنید.');$name=Security::normalize(Security::text($in,'new_name',255));
                $concat=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?"TRIM(CONCAT(m.first_name,' ',m.last_name))":"TRIM(m.first_name || ' ' || m.last_name)";
                if(Db::query($this->db,'SELECT m.id FROM martyrs m LEFT JOIN martyr_protocols p ON p.martyr_id=m.id WHERE p.display_name=? OR '.$concat.'=? LIMIT 1',[$name,$name])->fetchColumn()&&($in['duplicate_ack']??'')!=='1')throw new HttpError(409,'نام مشابه در بانک وجود دارد؛ ابتدا تطبیق را بررسی و در صورت تفاوت، ساخت رکورد مستقل را تأیید کنید.');
                $person=$protocol->save(0,1,['form_complete'=>'1','reason'=>$reason,'data'=>['display_name'=>$name,'first_name'=>Security::text($in,'new_first_name',150,false),'last_name'=>Security::text($in,'new_last_name',150,false),'archive_code'=>Security::text($in,'new_code',80,false),'identity_status'=>Security::text($in,'new_identity_status',40,false)?:'unknown']]);$new=true;
            }
            $bundle=$protocol->get($person);$destination=Security::text($in,'destination',30,false)?:'none';if(!in_array($destination,['none','short_bio','biography'],true))throw new HttpError(422,'محل انتقال متن معتبر نیست.');
            $excerpt=Security::text($in,'accepted_text',30000,false);$before=[];$after=[];
            if($destination!=='none'){
                if($excerpt==='')throw new HttpError(422,'متن انتخاب‌شده برای پذیرش را وارد کنید.');if(!$new&&((int)($in['compared_person_id']??0)!==$person||(int)($in['person_revision']??-1)!==(int)$bundle['profile']['revision']))throw new HttpError(409,'پرونده شهید هم‌زمان تغییر کرده است؛ مقایسه را تازه کنید.');
                $data=ProtocolValidator::formValues(ProtocolSpec::fields(1),$bundle['sections'][1]);$before[$destination]=$data[$destination]??'';$data[$destination]=($in['replace_text']??'')==='1'?$excerpt:trim($before[$destination]."\n\n".$excerpt);
                $protocol->save($person,1,['form_complete'=>'1','revision'=>$bundle['profile']['revision'],'reason'=>'مشارکت '.$id.'؛ '.$reason,'data'=>$data]);$after[$destination]=$protocol->get($person)['sections'][1][$destination]??'';
            }
            $selected=array_values(array_unique(array_map('intval',(array)($in['files']??[]))));if(count($selected)>5)throw new HttpError(422,'در هر انتقال حداکثر ۵ فایل انتخاب کنید.');$mediaIds=[];$payload=Protocol::decode($row['payload']);
            foreach($selected as $fid){
                $this->permit('media.create');$this->permit('media.edit');
                $asset=Db::query($this->db,'SELECT a.*,f.id AS file_id FROM contribution_files f JOIN contribution_file_assets x ON x.file_id=f.id JOIN incoming_assets a ON a.id=x.asset_id WHERE f.id=? AND f.contribution_id=?',[$fid,$id])->fetch();if(!$asset)throw new HttpError(422,'فایل انتخاب‌شده متعلق به این مشارکت نیست.');IncomingAssets::verify($asset);
                $existing=Db::query($this->db,'SELECT id FROM images WHERE sha256=?',[$asset['sha256']])->fetchColumn();
                if($existing){if(($in['existing_media_ack']??'')!=='1'||!Db::query($this->db,'SELECT image_id FROM media_martyrs WHERE image_id=? AND martyr_id=?',[$existing,$person])->fetchColumn())throw new HttpError(409,'فایل در آرشیو موجود است؛ پیش از استفاده مجدد، ارتباط آن را در مدیریت رسانه تطبیق دهید.');$image=(int)$existing;}
                else{
                    $slug=match($row['kind']){'photo'=>'photo','grave'=>'grave','will'=>'will','handwriting'=>'handwriting',default=>'document'};$type=(int)($in['type_id']??0)?:PlatformSchema::termId($this->db,'media_type',$slug);
                    $image=IncomingAssets::promote($this->db,$asset,$person,'پیوست پذیرفته‌شده مشارکت '.$id,$type,$created,$verifiedPreviews[$asset['id']]??null);$media=new Media($this->db,$this->actor);$m=$media->get($image);
                    $media->save($image,['revision'=>$m['revision'],'title'=>'پیوست مشارکت '.$id,'type_id'=>$type,'description'=>Security::text($in,'approved_media_description',10000,false),'source_text'=>$payload['source_text'],'rights_text'=>$payload['rights_text'],'martyrs'=>[$person],'creators'=>[],'reason'=>$reason]);
                }
                Db::query($this->db,'UPDATE contribution_files SET image_id=? WHERE id=?',[$image,$fid]);$mediaIds[]=$image;
            }
            if($destination==='none'&&!$mediaIds&&!$new)throw new HttpError(422,'بخش یا فایلی برای پذیرش انتخاب نشده است.');
            Db::insert($this->db,'contribution_merges',['contribution_id'=>$id,'request_key'=>$key,'martyr_id'=>$person,'field_key'=>$destination,'accepted_text'=>$excerpt,'before_value'=>Protocol::encode($before),'after_value'=>Protocol::encode($after),'media_ids'=>Protocol::encode($mediaIds),'created_by'=>$this->actor['id'],'created_at'=>date('Y-m-d H:i:s')]);
            Db::query($this->db,"UPDATE public_contributions SET status='merged',martyr_id=?,revision=revision+1,updated_at=? WHERE id=?",[$person,date('Y-m-d H:i:s'),$id]);CommunityHistory::add($this->db,'contribution',$id,$this->actor,$row['status'],'merged',Protocol::encode(['reason'=>$reason,'destination'=>$destination,'media_ids'=>$mediaIds,'created_person'=>$new]));return ['martyr_id'=>$person,'media_ids'=>$mediaIds];
        });}catch(\Throwable $e){IncomingAssets::discardPaths($created);throw $e;}
    }
}

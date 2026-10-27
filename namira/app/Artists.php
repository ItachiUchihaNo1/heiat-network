<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Artists {
    public function __construct(private PDO $db,private array $actor){}
    private function permit(string $permission,int $id=0):void{if(!Permissions::allows($this->db,$this->actor,$permission)&&!(in_array($permission,['artists.view','artists.edit'],true)&&ArtistAccounts::owns($this->db,$this->actor,$id)))throw new HttpError(403,'مجوز مدیریت هنرمند را ندارید.');}
    private function contacts(int $id):bool{return Permissions::allows($this->db,$this->actor,'artists.contacts')||ArtistAccounts::owns($this->db,$this->actor,$id);}
    public static function definitions():array {
        $f=fn($l,$t='text',$o=[])=>ProtocolSpec::field($l,$t,$o);
        return ['first_name'=>$f('نام'),'last_name'=>$f('نام خانوادگی'),'art_name'=>$f('نام هنری'),'province'=>$f('استان'),'city'=>$f('شهر'),'summary'=>$f('معرفی کوتاه','textarea'),'biography'=>$f('زندگی‌نامه هنری','rich'),'awards'=>$f('سوابق و افتخارات','textarea'),'website'=>$f('وب‌سایت','url'),'social_links'=>$f('نشانی شبکه‌های اجتماعی؛ هر نشانی یک خط','lines'),'private_email'=>$f('ایمیل؛ پیش‌فرض خصوصی'),'private_phone'=>$f('تلفن؛ پیش‌فرض خصوصی'),'specialties'=>$f('رشته‌ها و تخصص‌ها','terms',['taxonomy'=>'art_specialty'])];
    }
    public function get(int $id,bool $public=false,bool $internal=false):array|false {
        if(!$public&&!$internal)$this->permit('artists.view',$id);$a=Db::query($this->db,'SELECT * FROM artists WHERE id=?'.($public?" AND public_visible=1 AND archived=0":''),[$id])->fetch();if(!$a)return false;
        $a['data']=$public?Protocol::decode($a['public_snapshot']):Protocol::decode($a['payload']);
        if(!$public)$a['data']['specialties']=array_map('intval',Db::query($this->db,'SELECT term_id FROM artist_terms WHERE artist_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN));
        if($public){$snapshot=$a['data'];return ['id'=>$a['id'],'slug'=>$a['slug'],'display_name'=>$snapshot['display_name']??'','data'=>$snapshot['fields']??[],'avatar_asset_id'=>$snapshot['avatar_asset_id']??null];}
        $a['visible_fields']=Protocol::decode($a['public_fields']);if(!$internal&&!$this->contacts($id)){unset($a['data']['private_email'],$a['data']['private_phone'],$a['payload']);}return $a;
    }
    public function save(array $in,int $id=0,?string $avatarSource=null):int {
        $this->permit('artists.edit',$id);$old=$id?$this->get($id,false,true):null;if($id&&!$old)throw new HttpError(404,'هنرمند پیدا نشد.');
        $incoming=is_array($in['data']??null)?$in['data']:[];if(!$this->contacts($id))foreach(['private_email','private_phone'] as $key){if(isset($incoming[$key])&&$incoming[$key]!==($old['data'][$key]??''))throw new HttpError(403,'ویرایش تماس خصوصی به مجوز جداگانه نیاز دارد.');$incoming[$key]=$old['data'][$key]??'';}
        $v=new ProtocolValidator($this->db,$this->actor);$data=$v->fields(self::definitions(),$incoming,'data.',$old['data']??[]);
        $name=Security::text($in,'display_name',255,false)?:trim(($data['art_name']??'')?:($data['first_name']??'').' '.($data['last_name']??''));if($name==='')$v->errors['display_name']='نام نمایشی هنرمند لازم است.';
        if(($data['private_email']??'')!==''&&!filter_var($data['private_email'],FILTER_VALIDATE_EMAIL))$v->errors['data.private_email']='ایمیل معتبر نیست.';
        foreach($data['social_links']??[] as $url)if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true))$v->errors['data.social_links']='نشانی شبکه اجتماعی باید http یا https معتبر باشد.';$v->assert();
        $slug=Security::text($in,'slug',150,false)?:($old['slug']??'artist-'.bin2hex(random_bytes(8)));if(!preg_match('/^[a-z0-9][a-z0-9-]{0,149}$/D',$slug))throw new HttpError(422,'نامک باید لاتین، عدد و خط تیره باشد.');
        if(Db::query($this->db,'SELECT id FROM artists WHERE slug=? AND id<>?',[$slug,$id])->fetchColumn())throw new HttpError(422,'نامک تکراری است.');
        $visibility=array_values(array_intersect((array)($in['public_fields']??(($in['public_fields_present']??'')==='1'?[]:($old['visible_fields']??['summary','biography','specialties']))),array_keys(self::definitions())));
        if(!$this->contacts($id)){if(array_diff(array_intersect($visibility,['private_email','private_phone']),$old['visible_fields']??[]))throw new HttpError(403,'نمایش تماس خصوصی مجاز نیست.');$visibility=array_values(array_unique([...array_diff($visibility,['private_email','private_phone']),...array_intersect($old['visible_fields']??[],['private_email','private_phone'])]));}
        if($this->contacts($id)&&(in_array('private_email',$visibility,true)||in_array('private_phone',$visibility,true))&&($in['contact_consent']??'')!=='1')throw new HttpError(422,'نمایش عمومی اطلاعات تماس به تأیید مشخص مجوز نیاز دارد.');
        $newAsset=null;try{return Db::transaction($this->db,function()use($id,$in,$old,$data,$name,$slug,$visibility,$avatarSource,&$newAsset){
            $avatar=$old['avatar_asset_id']??null;if($avatarSource){$newAsset=Assets::put($this->db,$avatarSource,(int)$this->actor['id']);$avatar=$newAsset['id'];}if(!empty($in['remove_avatar']))$avatar=null;
            $now=date('Y-m-d H:i:s');$terms=$data['specialties'];$payload=$data;unset($payload['specialties']);
            if($id){$lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$current=Db::query($this->db,'SELECT revision FROM artists WHERE id=?'.$lock,[$id])->fetchColumn();if((int)$current!==(int)($in['revision']??-1))throw new HttpError(409,'پروفایل در صفحه دیگری تغییر کرده است.');
                Db::query($this->db,"UPDATE artists SET display_name=?,slug=?,payload=?,public_fields=?,avatar_asset_id=?,status=CASE WHEN status IN ('pending','approved') THEN 'draft' ELSE status END,revision=revision+1,updated_at=? WHERE id=?",[$name,$slug,Protocol::encode($payload),Protocol::encode($visibility),$avatar,$now,$id]);
            }else $id=Db::insert($this->db,'artists',['display_name'=>$name,'slug'=>$slug,'avatar_asset_id'=>$avatar,'payload'=>Protocol::encode($payload),'public_fields'=>Protocol::encode($visibility),'public_snapshot'=>'{}','created_by'=>$this->actor['id'],'created_at'=>$now,'updated_at'=>$now]);
            Db::query($this->db,'DELETE FROM artist_terms WHERE artist_id=?',[$id]);foreach($terms as $term)Db::insert($this->db,'artist_terms',['artist_id'=>$id,'term_id'=>$term]);Db::audit($this->db,'artist.save',(string)$id);
            Db::insert($this->db,'protocol_changes',['martyr_id'=>null,'user_id'=>$this->actor['id'],'field_key'=>'artist.'.$id,'before_value'=>Protocol::encode($old?['data'=>$old['data'],'display_name'=>$old['display_name']]:null),'after_value'=>Protocol::encode(['data'=>$data,'display_name'=>$name]),'reason'=>Security::text($in,'reason',2000,false),'created_at'=>$now]);return $id;
        });}catch(\Throwable $e){if($newAsset&&is_file(Env::storage().'/'.$newAsset['path']))unlink(Env::storage().'/'.$newAsset['path']);throw $e;}
    }
    public function transition(int $id,string $status,array $in):void {
        $this->permit($status==='published'?'artists.publish':($status==='pending'?'artists.edit':'artists.review'),$id);
        $a=$this->get($id,false,true);if(!$a)throw new HttpError(404,'هنرمند پیدا نشد.');
        if(!in_array($status,['draft','pending','approved','published','needs_changes','archived'],true))throw new HttpError(422,'وضعیت معتبر نیست.');
        $allowed=['draft'=>['pending','archived'],'pending'=>['approved','needs_changes','archived'],'approved'=>['published','needs_changes','archived'],'published'=>['pending','archived'],'needs_changes'=>['pending','archived'],'archived'=>['draft']];
        if(!in_array($status,$allowed[$a['status']]??[],true))throw new HttpError(422,'گردش وضعیت هنرمند معتبر نیست.');
        if($status==='published'&&array_intersect($a['visible_fields'],['private_email','private_phone'])&&!$this->contacts($id))throw new HttpError(403,'انتشار پروفایل حاوی تماس، مجوز تماس خصوصی لازم دارد.');
        if($status==='published'&&($in['publication_ack']??'')!=='1')throw new HttpError(422,'تأیید صریح انتشار هنرمند لازم است.');
        $snapshot=$a['public_snapshot'];if($status==='published'){$payload=$a['data'];$visible=array_flip($a['visible_fields']);$snapshot=Protocol::encode(['display_name'=>$a['display_name'],'fields'=>array_intersect_key($payload,$visible),'avatar_asset_id'=>$a['avatar_asset_id']]);}
        Db::transaction($this->db,function()use($id,$in,$status,$snapshot,$a){$changed=Db::query($this->db,'UPDATE artists SET status=?,archived=?,public_visible=?,public_snapshot=?,public_avatar_asset_id=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?',[$status,$status==='archived'?1:0,$status==='published'?1:($status==='archived'?0:$a['public_visible']),$snapshot,$status==='published'?$a['avatar_asset_id']:($a['public_avatar_asset_id']??null),date('Y-m-d H:i:s'),$id,(int)($in['revision']??-1)])->rowCount();if(!$changed)throw new HttpError(409,'نسخه پروفایل تغییر کرده است.');if($status==='published'){Db::query($this->db,'DELETE FROM artist_public_terms WHERE artist_id=?',[$id]);foreach(in_array('specialties',$a['visible_fields'],true)?($a['data']['specialties']??[]):[] as $term)Db::insert($this->db,'artist_public_terms',['artist_id'=>$id,'term_id'=>$term]);}Db::audit($this->db,'artist.'.$status,(string)$id);});
    }
    public function listing(array $f=[],bool $public=false):array {
        if(!$public)$this->permit('artists.view');
        $where=[$public?"a.public_visible=1 AND a.archived=0":'1=1'];$args=[];$q=Security::text($f,'q',150,false);
        if($q!==''){$where[]=$public?'a.public_snapshot LIKE ?':'a.display_name LIKE ?';$args[]='%'.$q.'%';}
        if(!$public&&!empty($f['status'])){if($f['status']==='unpublished')$where[]="a.status NOT IN ('published','archived')";else{$where[]='a.status=?';$args[]=Security::text($f,'status',30);}}
        if(!empty($f['specialty'])){$where[]='EXISTS(SELECT 1 FROM '.($public?'artist_public_terms':'artist_terms').' at WHERE at.artist_id=a.id AND at.term_id=?)';$args[]=(int)$f['specialty'];}
        $sql=implode(' AND ',$where);$count=(int)Db::query($this->db,'SELECT COUNT(*) FROM artists a WHERE '.$sql,$args)->fetchColumn();$per=24;$page=min(max(1,(int)ceil($count/$per)),max(1,(int)($f['p']??1)));
        $items=Db::query($this->db,"SELECT a.*,(SELECT COUNT(*) FROM media_public_artists ma JOIN images i ON i.id=ma.image_id WHERE ma.artist_id=a.id AND i.kind='design' AND i.published=1 AND EXISTS(SELECT 1 FROM media_public_martyrs pm JOIN martyrs m ON m.id=pm.martyr_id WHERE pm.image_id=i.id AND m.published=1)) AS work_count FROM artists a WHERE $sql ORDER BY a.updated_at DESC,a.id DESC LIMIT $per OFFSET ".(($page-1)*$per),$args)->fetchAll();
        if($public)$items=array_map(function($item){$s=Protocol::decode($item['public_snapshot']);return ['id'=>$item['id'],'slug'=>$item['slug'],'display_name'=>$s['display_name']??'','data'=>$s['fields']??[],'avatar_asset_id'=>$s['avatar_asset_id']??null,'work_count'=>$item['work_count']];},$items);return compact('items','count','page','per');
    }
}

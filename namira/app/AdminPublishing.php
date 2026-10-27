<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Trusted admin entry points only. Public account submissions never call this facade. */
final class AdminPublishing {
    public static function label(string $status):string {return match($status){'published'=>'منتشرشده','archived'=>'خارج از نمایش','paused'=>'متوقف','merged'=>'ثبت‌شده','submitted'=>'دریافت‌شده',default=>'منتشرنشده'};}
    public static function guard(PDO $db,array $actor,string $kind):array {
        $actor=CommunityHistory::requireActor($db,$actor);$permissions=match($kind){'martyr'=>['martyrs.edit'],'artist'=>['artists.edit','artists.publish'],'media'=>['media.edit','media.publish'],'call'=>['calls.edit','calls.publish'],default=>[]};
        if(!Permissions::allows($db,$actor,'dashboard.view'))throw new HttpError(403,'انتشار مستقیم فقط از حساب مجاز مدیریت انجام می‌شود.');
        foreach($permissions as $permission)if(Permissions::allows($db,$actor,$permission))return $actor;throw new HttpError(403,'مجوز ثبت این نوع محتوا را ندارید.');
    }
    private static function version(PDO $db,string $table,string $key,int $id,?int $revision):array {
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$row=Db::query($db,"SELECT * FROM $table WHERE $key=?".$suffix,[$id])->fetch();if(!$row)throw new HttpError(404,'محتوا پیدا نشد.');if($revision!==null&&(int)$row['revision']!==$revision)throw new HttpError(409,'محتوا در صفحه دیگری تغییر کرده است؛ نسخه فعلی را بازخوانی کنید.');return $row;
    }
    public static function save(PDO $db,array $actor,string $kind,callable $write,array $options=[]):int {
        $actor=self::guard($db,$actor,$kind);$paths=[];$oldAsset=0;$id=(int)($options['existing_id']??0);$assetSpec=match($kind){'artist'=>['artists','avatar_asset_id'],'call'=>['art_calls','poster_asset_id'],default=>null};
        if($assetSpec&&$id)$oldAsset=(int)Db::query($db,'SELECT '.$assetSpec[1].' FROM '.$assetSpec[0].' WHERE id=?',[$id])->fetchColumn();
        try{return Db::transaction($db,function()use($db,$actor,$kind,$write,$options,$assetSpec,$oldAsset,&$paths){$id=(int)$write();if($assetSpec){$newAsset=(int)Db::query($db,'SELECT '.$assetSpec[1].' FROM '.$assetSpec[0].' WHERE id=?',[$id])->fetchColumn();if($newAsset&&$newAsset!==$oldAsset){$path=Db::query($db,'SELECT path FROM file_assets WHERE id=?',[$newAsset])->fetchColumn();if($path)$paths[]=$path;}}self::publish($db,$actor,$kind,$id,null,$options);return $id;});}
        catch(\Throwable $e){IncomingAssets::discardPaths($paths);throw $e;}
    }
    public static function publish(PDO $db,array $actor,string $kind,int $id,?int $revision=null,array $options=[]):void {
        match($kind){'martyr'=>self::martyr($db,$actor,$id,$revision,$options),'artist'=>self::artist($db,$actor,$id,$revision,$options),'media'=>self::media($db,$actor,$id,$revision),'call'=>self::call($db,$actor,$id,$revision),default=>throw new HttpError(422,'نوع محتوا معتبر نیست.')};
    }
    public static function martyr(PDO $db,array $actor,int $id,?int $revision=null,array $options=[]):void {
        $actor=self::guard($db,$actor,'martyr');Db::transaction($db,function()use($db,$actor,$id,$revision,$options){$service=new Protocol($db,$actor);$service->get($id);$profile=self::version($db,'martyr_protocols','martyr_id',$id,$revision);$bundle=$service->get($id);$fields=Protocol::decode($profile['public_fields']);
            if(($options['mother_visibility_present']??'')==='1')$fields=($options['publish_mother_name']??'')==='1'?['mother_name']:[];
            $snapshot=$service->publicSnapshot($bundle,$fields);$identity=$bundle['sections'][1]??[];$now=date('Y-m-d H:i:s');$next=(int)$profile['revision']+1;
            Db::query($db,"UPDATE martyr_protocols SET status='published',public_snapshot=?,public_name=?,public_fields=?,revision=?,updated_at=? WHERE martyr_id=?",[Protocol::encode($snapshot),$profile['display_name'],Protocol::encode($fields),$next,$now,$id]);
            Db::query($db,'UPDATE martyrs SET published=1,first_name=?,last_name=?,father_name=?,summary=?,biography=?,code=? WHERE id=?',[$identity['first_name']??$bundle['core']['first_name'],$identity['last_name']??$bundle['core']['last_name'],$identity['father_name']??$bundle['core']['father_name'],$identity['short_bio']??$bundle['core']['summary'],$identity['biography']??$bundle['core']['biography'],($identity['archive_code']??'')?:$bundle['core']['code'],$id]);
            if(MartyrTaxonomySchema::ready($db))MartyrCatalog::indexSnapshot($db,$id,$snapshot,$next);
            Db::insert($db,'protocol_reviews',['martyr_id'=>$id,'user_id'=>$actor['id'],'from_status'=>$profile['status'],'to_status'=>'published','reason'=>'ثبت و انتشار مستقیم مدیر','incomplete_ack'=>0,'created_at'=>$now]);Db::audit($db,'admin.martyr.direct_publish',(string)$id);
        });
    }
    public static function artist(PDO $db,array $actor,int $id,?int $revision=null,array $options=[]):void {
        $actor=self::guard($db,$actor,'artist');Db::transaction($db,function()use($db,$actor,$id,$revision,$options){self::version($db,'artists','id',$id,$revision);$a=(new Artists($db,$actor))->get($id,false,true);$data=$a['data'];$fields=array_intersect_key($data,array_flip($a['visible_fields']));$previous=Protocol::decode($a['public_snapshot']);
            foreach(['private_email','private_phone'] as $key)if(isset($fields[$key])&&(!Permissions::allows($db,$actor,'artists.contacts')||($options['contact_consent']??'')!=='1')){if(array_key_exists($key,$previous['fields']??[]))$fields[$key]=$previous['fields'][$key];else unset($fields[$key]);}
            $snapshot=Protocol::encode(['display_name'=>$a['display_name'],'fields'=>$fields,'avatar_asset_id'=>$a['avatar_asset_id']]);
            Db::query($db,"UPDATE artists SET status='published',archived=0,public_visible=1,public_snapshot=?,public_avatar_asset_id=?,revision=revision+1,updated_at=? WHERE id=?",[$snapshot,$a['avatar_asset_id'],date('Y-m-d H:i:s'),$id]);
            Db::query($db,'DELETE FROM artist_public_terms WHERE artist_id=?',[$id]);foreach(isset($fields['specialties'])?$fields['specialties']:[] as $term)Db::insert($db,'artist_public_terms',['artist_id'=>$id,'term_id'=>$term]);Db::audit($db,'admin.artist.direct_publish',(string)$id);
        });
    }
    public static function media(PDO $db,array $actor,int $id,?int $revision=null):void {
        $actor=self::guard($db,$actor,'media');Db::transaction($db,function()use($db,$actor,$id,$revision){self::version($db,'media_details','image_id',$id,$revision);$a=(new Media($db,$actor))->get($id);if(!$a)throw new HttpError(404,'رسانه پیدا نشد.');$type=Db::query($db,"SELECT * FROM protocol_terms WHERE id=? AND taxonomy='media_type' AND active=1",[$a['type_id']])->fetch();if(!$type)throw new HttpError(422,'نوع رسانه لازم است.');
            foreach($a['martyrs'] as $person)if(!Db::query($db,'SELECT published FROM martyrs WHERE id=?',[$person])->fetchColumn())self::martyr($db,$actor,$person);
            foreach($a['creators'] as $creator)if(!Db::query($db,'SELECT id FROM artists WHERE id=? AND public_visible=1 AND archived=0',[$creator['artist_id']])->fetchColumn())self::artist($db,$actor,(int)$creator['artist_id']);
            $kind=(Protocol::decode($type['metadata'])['artwork']??false)?'design':(in_array($type['slug'],['document','will','handwriting'],true)?'document':'photo');$now=date('Y-m-d H:i:s');
            Db::query($db,'UPDATE images SET title=?,kind=?,license_text=?,published=1 WHERE id=?',[$a['title'],$kind,$a['rights_text'],$id]);$payload=Protocol::encode(['description'=>$a['description'],'source_text'=>$a['source_text'],'rights_text'=>$a['rights_text']]);
            if(Db::query($db,'SELECT image_id FROM media_publication WHERE image_id=?',[$id])->fetchColumn())Db::query($db,'UPDATE media_publication SET type_id=?,download_allowed=?,sort_order=?,payload=?,published_at=? WHERE image_id=?',[$a['type_id'],$a['download_allowed'],$a['sort_order'],$payload,$now,$id]);else Db::insert($db,'media_publication',['image_id'=>$id,'type_id'=>$a['type_id'],'download_allowed'=>$a['download_allowed'],'sort_order'=>$a['sort_order'],'payload'=>$payload,'published_at'=>$now]);
            Db::query($db,'DELETE FROM media_public_martyrs WHERE image_id=?',[$id]);foreach(Db::query($db,'SELECT * FROM media_martyrs WHERE image_id=?',[$id])->fetchAll() as $person){if($person['is_featured'])Db::query($db,'UPDATE media_public_martyrs SET is_featured=0 WHERE martyr_id=?',[$person['martyr_id']]);Db::insert($db,'media_public_martyrs',$person);}
            Db::query($db,'DELETE FROM media_public_artists WHERE image_id=?',[$id]);foreach($a['creators'] as $creator){$snapshot=Db::query($db,'SELECT public_snapshot FROM artists WHERE id=?',[$creator['artist_id']])->fetchColumn();$name=Protocol::decode($snapshot)['display_name']??'';Db::insert($db,'media_public_artists',['image_id'=>$id,'artist_id'=>$creator['artist_id'],'creator_role'=>$creator['creator_role'],'credit_order'=>$creator['credit_order'],'credit_name'=>$name]);}
            Db::query($db,"UPDATE media_details SET status='published',reviewed_by=NULL,reviewed_at=NULL,revision=revision+1,updated_at=? WHERE image_id=?",[$now,$id]);Db::audit($db,'admin.media.direct_publish',(string)$id);
        });
    }
    public static function call(PDO $db,array $actor,int $id,?int $revision=null):void {
        $actor=self::guard($db,$actor,'call');Db::transaction($db,function()use($db,$actor,$id,$revision){$old=self::version($db,'art_calls','id',$id,$revision);foreach(Db::query($db,'SELECT martyr_id FROM call_martyrs WHERE call_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN) as $person)if(!Db::query($db,'SELECT published FROM martyrs WHERE id=?',[$person])->fetchColumn())self::martyr($db,$actor,(int)$person);Db::query($db,"UPDATE art_calls SET status='published',revision=revision+1,updated_at=? WHERE id=?",[date('Y-m-d H:i:s'),$id]);CommunityHistory::add($db,'art_call',$id,$actor,$old['status'],'published','ثبت و انتشار مستقیم مدیر');});
    }
    public static function visibility(PDO $db,array $actor,string $kind,int $id,string $status,int $revision,array $options=[]):void {
        if($status==='published'){self::publish($db,$actor,$kind,$id,$revision,$options);return;}if(!in_array($status,['archived','paused'],true)||($status==='paused'&&$kind!=='call'))throw new HttpError(410,'مراحل بازبینی حذف شده‌اند؛ از ثبت و انتشار مستقیم استفاده کنید.');$actor=self::guard($db,$actor,$kind);
        Db::transaction($db,function()use($db,$actor,$kind,$id,$status,$revision){[$table,$key]=match($kind){'martyr'=>['martyr_protocols','martyr_id'],'artist'=>['artists','id'],'media'=>['media_details','image_id'],'call'=>['art_calls','id']};self::version($db,$table,$key,$id,$revision);Db::query($db,"UPDATE $table SET status=?,revision=revision+1,updated_at=? WHERE $key=?",[$status,date('Y-m-d H:i:s'),$id]);if($kind==='martyr')Db::query($db,'UPDATE martyrs SET published=0 WHERE id=?',[$id]);if($kind==='artist')Db::query($db,'UPDATE artists SET public_visible=0,archived=1 WHERE id=?',[$id]);if($kind==='media')Db::query($db,'UPDATE images SET published=0 WHERE id=?',[$id]);Db::audit($db,'admin.'.$kind.'.'.$status,(string)$id);});
    }
    public static function upload(PDO $db,array $actor,string $source,string $filename,array $in,?string $preview=null):int {
        $actor=self::guard($db,$actor,'media');$existed=(bool)Db::query($db,'SELECT id FROM images WHERE sha256=?',[hash_file('sha256',$source)])->fetchColumn();$paths=[];
        try{return Db::transaction($db,function()use($db,$actor,$source,$filename,$in,$preview,$existed,&$paths){$id=Upload::image($db,$source,$filename,$in,$preview);if(!$existed)$paths=array_values(Db::query($db,'SELECT original_path,preview_path,thumb_path FROM images WHERE id=?',[$id])->fetch());self::media($db,$actor,$id);return $id;});}catch(\Throwable $e){IncomingAssets::discardPaths($paths);throw $e;}
    }
}

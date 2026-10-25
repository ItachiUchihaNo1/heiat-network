<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Archive {
    public function __construct(private PDO $db) {}
    public function save(array $in,?int $id=null): int {
        $data=[];
        foreach(['code'=>80,'first_name'=>150,'last_name'=>150,'father_name'=>150,'province'=>100,'city'=>100,'job'=>150,'organization'=>150,'birth_date'=>40,'death_date'=>40,'burial_place'=>255,'summary'=>2000,'biography'=>100000,'will_text'=>100000,'memories'=>100000] as $k=>$max) $data[$k]=Security::normalize(Security::text($in,$k,$max,in_array($k,['code','first_name','last_name'],true)));
        if(!preg_match('/^[0-9A-Za-z_]{1,80}$/D',$data['code'])) throw new HttpError(422,'کد آرشیو باید شامل ارقام و حروف لاتین باشد.');
        $existing=Db::query($this->db,'SELECT id FROM martyrs WHERE code=?',[$data['code']])->fetchColumn();
        if($existing&&(int)$existing!==$id) throw new HttpError(409,'کد شهید تکراری است.');
        $data['published']=isset($in['published'])?1:0;
        return Db::transaction($this->db,function()use($data,$id,$in){
            if($id){ if(!$this->find($id,false)) throw new HttpError(404,'شهید پیدا نشد.'); Db::query($this->db,'UPDATE martyrs SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($data))).' WHERE id=?',[...array_values($data),$id]); }
            else $id=Db::insert($this->db,'martyrs',$data+['created_at'=>date('Y-m-d H:i:s')]);
            Db::query($this->db,'DELETE FROM martyr_categories WHERE martyr_id=?',[$id]);
            foreach(array_unique((array)($in['categories']??[])) as $cat){ if(!Db::query($this->db,'SELECT id FROM categories WHERE id=?',[(int)$cat])->fetch()) throw new HttpError(422,'دسته نامعتبر است.'); Db::insert($this->db,'martyr_categories',['martyr_id'=>$id,'category_id'=>(int)$cat]); }
            Db::audit($this->db,'martyr.save',(string)$id); return $id;
        });
    }
    public function find(int $id,bool $public=true): array|false {
        $m=Db::query($this->db,'SELECT * FROM martyrs WHERE id=?'.($public?' AND published=1':''),[$id])->fetch();
        if($m&&ProtocolSchema::ready($this->db)){$p=Db::query($this->db,'SELECT public_name,public_snapshot FROM martyr_protocols WHERE martyr_id=?',[$id])->fetch();if($p){$m['display_name']=$p['public_name']?:trim($m['first_name'].' '.$m['last_name']);$m['protocol']=Protocol::decode($p['public_snapshot']);}}
        return $m;
    }
    private function publicNameSql():string {return ProtocolSchema::ready($this->db)?"COALESCE(NULLIF((SELECT public_name FROM martyr_protocols mp WHERE mp.martyr_id=m.id),''),TRIM(CONCAT(m.first_name,' ',m.last_name)))":"";}
    private function nameSelect():string {if(!ProtocolSchema::ready($this->db))return '';return ",(SELECT public_name FROM martyr_protocols mp WHERE mp.martyr_id=m.id) AS display_name";}

    public function conditions(array $f): array {
        $where=['m.published=1']; $args=[];
        $q=Security::normalize(Security::text($f,'q',150,false));
        if($q!=='') foreach(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY) as $term){ $name=ProtocolSchema::ready($this->db)?' OR EXISTS(SELECT 1 FROM martyr_protocols mp WHERE mp.martyr_id=m.id AND mp.public_name LIKE ?)':'';$where[]='(m.first_name LIKE ? OR m.last_name LIKE ? OR m.code LIKE ? OR m.summary LIKE ?'.$name.')'; $args=[...$args,...array_fill(0,$name?5:4,'%'.$term.'%')]; }
        foreach(['province','city','job','organization'] as $key){ $v=Security::text($f,$key,150,false); if($v!==''){ $where[]='m.'.$key.'=?'; $args[]=$v; } }
        if(!empty($f['category'])){ $where[]='EXISTS(SELECT 1 FROM martyr_categories mc WHERE mc.martyr_id=m.id AND mc.category_id=?)'; $args[]=(int)$f['category']; }
        return [implode(' AND ',$where),$args];
    }
    private function mediaFrom():string {
        if(!PlatformSchema::ready($this->db))return 'FROM images i JOIN martyrs m ON m.id=i.martyr_id';
        return 'FROM images i JOIN media_publication mp ON mp.image_id=i.id JOIN martyrs m ON m.id=(SELECT MIN(mm.martyr_id) FROM media_public_martyrs mm JOIN martyrs pm ON pm.id=mm.martyr_id WHERE mm.image_id=i.id AND pm.published=1)';
    }
    public function forPerson(int $id,int $page=1):array {return $this->images(['martyr_id'=>$id,'kind'=>'all'],$page);}
    public function creators(int $image):array {
        if(!PlatformSchema::ready($this->db))return [];
        return Db::query($this->db,'SELECT ma.artist_id,ma.creator_role,ma.credit_name,a.slug,a.status,a.archived,a.public_visible FROM media_public_artists ma JOIN artists a ON a.id=ma.artist_id WHERE ma.image_id=? ORDER BY ma.credit_order,ma.artist_id',[$image])->fetchAll();
    }
    public function images(array $f=[],int $page=1,int $perPage=24): array {
        [$where,$args]=$this->conditions($f); $where.=' AND i.published=1';
        $kind=Security::text($f,'kind',30,false)?:'design';if($kind!=='all'){$where.=' AND i.kind=?';$args[]=$kind;}
        if(PlatformSchema::ready($this->db)){
            if(!empty($f['martyr_id'])){$where.=' AND EXISTS(SELECT 1 FROM media_public_martyrs mm WHERE mm.image_id=i.id AND mm.martyr_id=?)';$args[]=(int)$f['martyr_id'];}
            if(!empty($f['artist_id'])){$where.=' AND EXISTS(SELECT 1 FROM media_public_artists ma WHERE ma.image_id=i.id AND ma.artist_id=?)';$args[]=(int)$f['artist_id'];}
        }elseif(!empty($f['martyr_id'])){$where.=' AND i.martyr_id=?';$args[]=(int)$f['martyr_id'];}
        foreach(['mime'] as $key){ if(!empty($f[$key])){ $where.=' AND i.'.$key.'=?'; $args[]=Security::text($f,$key,50); } }
        if(($f['orientation']??'')==='portrait') $where.=' AND i.height>i.width';
        if(($f['orientation']??'')==='landscape') $where.=' AND i.width>i.height';
        $sort=['new'=>'i.id DESC','name'=>'m.last_name,m.first_name,i.id','downloads'=>'i.downloads DESC,i.id DESC'][$f['sort']??'new']??'i.id DESC';
        if(!empty($f['martyr_id'])&&PlatformSchema::ready($this->db))$sort='(SELECT mm.is_featured FROM media_public_martyrs mm WHERE mm.image_id=i.id AND mm.martyr_id='.(int)$f['martyr_id'].') DESC,mp.sort_order,i.id DESC';
        $count=(int)Db::query($this->db,'SELECT COUNT(*) '.$this->mediaFrom().' WHERE '.$where,$args)->fetchColumn();
        $offset=(max(1,$page)-1)*$perPage;
        $items=Db::query($this->db,'SELECT i.*,m.id AS martyr_id,m.first_name,m.last_name,m.code,m.province,m.city'.(PlatformSchema::ready($this->db)?',mp.download_allowed':',1 AS download_allowed').$this->nameSelect().' '.$this->mediaFrom().' WHERE '.$where.' ORDER BY '.$sort.' LIMIT '.(int)$perPage.' OFFSET '.$offset,$args)->fetchAll();
        return compact('items','count');
    }
    public function image(int $id):array|false {
        $extra=PlatformSchema::ready($this->db)?',mp.download_allowed,mp.payload AS publication_payload':',1 AS download_allowed';
        $image=Db::query($this->db,'SELECT i.*,m.id AS martyr_id,m.first_name,m.last_name,m.code'.$this->nameSelect().$extra.' '.$this->mediaFrom().' WHERE i.id=? AND i.published=1 AND m.published=1',[$id])->fetch();
        if($image&&isset($image['publication_payload']))$image['publication']=Protocol::decode($image['publication_payload']);return $image;
    }
    public function export(array $f): array {
        [$w,$args]=$this->conditions($f);
        $martyrs=Db::query($this->db,'SELECT m.* FROM martyrs m WHERE '.$w.' ORDER BY m.id',$args)->fetchAll();
        $images=Db::query($this->db,'SELECT m.code,i.title,i.sequence_no,i.designer,i.mime,i.width,i.height,i.bytes,i.downloads FROM images i JOIN martyrs m ON m.id=i.martyr_id WHERE '.$w.' AND i.published=1 ORDER BY m.id,i.sequence_no',$args)->fetchAll();
        return ['شهدا'=>$martyrs,'تصاویر'=>$images];
    }
}



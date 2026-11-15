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

    public function conditions(array $f,bool $images=false): array {
        $where=['m.published=1']; $args=[];
        $q=Security::normalize(Security::text($f,'q',150,false));
        if($q!=='') foreach(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY) as $term){ $name=ProtocolSchema::ready($this->db)?' OR EXISTS(SELECT 1 FROM martyr_protocols mp WHERE mp.martyr_id=m.id AND mp.public_name LIKE ?)':'';$media=$images?' OR i.title LIKE ? OR i.designer LIKE ?':'';$where[]='(m.first_name LIKE ? OR m.last_name LIKE ? OR m.code LIKE ? OR m.summary LIKE ?'.$name.$media.')'; $args=[...$args,...array_fill(0,($name?5:4)+($images?2:0),'%'.$term.'%')]; }
        foreach(['first_name','last_name'] as $key){$v=Security::normalize(Security::text($f,$key,150,false));if($v!==''){$where[]='m.'.$key.' LIKE ?';$args[]='%'.$v.'%';}}
        $person=Security::text(isset($f['martyr_id'])&&is_int($f['martyr_id'])?array_replace($f,['martyr_id'=>(string)$f['martyr_id']]):$f,'martyr_id',12,false);if($person!==''){if(!ctype_digit($person)||(int)$person<1)throw new HttpError(422,'شناسه شهید معتبر نیست.');$where[]='m.id=?';$args[]=(int)$person;}
        $code=Security::normalize(Security::text($f,'archive_code',80,false));if($code!==''){$where[]='m.code=?';$args[]=$code;}
        $text=Security::text($f,'text_query',150,false);if($text!==''){$field=Security::text($f,'text_field',30);if(!in_array($field,['summary','biography','will_text','memories'],true))throw new HttpError(422,'بخش متن عمومی معتبر نیست.');$where[]='m.'.$field.' LIKE ?';$args[]='%'.$text.'%';}
        if(!$images&&self::hasMediaFilters($f)){[$mw,$ma]=self::mediaConditions($f,false,'si','sp');$where[]='EXISTS(SELECT 1 FROM media_public_martyrs sr JOIN images si ON si.id=sr.image_id JOIN media_publication sp ON sp.image_id=si.id WHERE sr.martyr_id=m.id AND '.$mw.')';$args=[...$args,...$ma];}
        foreach(['province','city','job','organization'] as $key){ $v=Security::text($f,$key,150,false); if($v!==''){ $where[]='TRIM(m.'.$key.')=?'; $args[]=$v; } }
        if(!empty($f['category'])){ $where[]='EXISTS(SELECT 1 FROM martyr_categories mc WHERE mc.martyr_id=m.id AND mc.category_id=?)'; $args[]=(int)$f['category']; }
        [$catalogSql,$catalogArgs]=MartyrFilters::conditions($this->db,$f);if($catalogSql!==''){$where[]=$catalogSql;$args=[...$args,...$catalogArgs];}
        return [implode(' AND ',$where),$args];
    }
    private function mediaFrom():string {
        if(!PlatformSchema::ready($this->db))return 'FROM images i JOIN martyrs m ON m.id=i.martyr_id';
        return 'FROM images i JOIN media_publication mp ON mp.image_id=i.id JOIN martyrs m ON m.id=(SELECT MIN(mm.martyr_id) FROM media_public_martyrs mm JOIN martyrs pm ON pm.id=mm.martyr_id WHERE mm.image_id=i.id AND pm.published=1)';
    }
    public static function hasMediaFilters(array $f):bool {foreach(['kind','media_type','artist_id','mime','orientation','download','media_q'] as $key)if(($f[$key]??'')!=='')return true;return false;}
    /** A single public image predicate is shared by gallery, people search and facets. */
    public static function mediaConditions(array $f,bool $defaultKind=true,string $i='i',string $mp='mp'):array {
        if(!in_array($i,['i','si'],true)||!in_array($mp,['mp','ip','sp'],true))throw new \LogicException('Unexpected search alias');$where=[$i.'.published=1'];$args=[];
        $kind=Security::text($f,'kind',30,false)?:($defaultKind?'design':'');if($kind!==''&&$kind!=='all'){if(!in_array($kind,['design','photo','document'],true))throw new HttpError(422,'نوع تصویر معتبر نیست.');$where[]=$i.'.kind=?';$args[]=$kind;}
        foreach(['media_type','artist_id'] as $key){$value=$f[$key]??'';if(is_int($value))$value=(string)$value;$raw=Security::normalize(Security::text([$key=>$value],$key,12,false));if($raw===''||$raw==='0')continue;if(!ctype_digit($raw)||(int)$raw<1)throw new HttpError(422,'شناسه فیلتر تصویر معتبر نیست.');if($key==='media_type')$where[]=$mp.'.type_id=?';else $where[]="EXISTS(SELECT 1 FROM media_public_artists sa JOIN artists spa ON spa.id=sa.artist_id AND spa.public_visible=1 AND spa.archived=0 WHERE sa.image_id=".$i.'.id AND sa.artist_id=?)';$args[]=(int)$raw;}
        $mime=Security::text($f,'mime',80,false);if($mime!==''){if(!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$mime))throw new HttpError(422,'فرمت تصویر معتبر نیست.');$where[]=$i.'.mime=?';$args[]=$mime;}
        $orientation=Security::text($f,'orientation',20,false);if($orientation!==''){$operator=['portrait'=>'>','landscape'=>'<','square'=>'='][$orientation]??null;if(!$operator)throw new HttpError(422,'جهت تصویر معتبر نیست.');$where[]=$i.'.width>0 AND '.$i.'.height>0 AND '.$i.'.height'.$operator.$i.'.width';}
        $download=Security::text($f,'download',1,false);if($download!==''){if(!in_array($download,['0','1'],true))throw new HttpError(422,'وضعیت دریافت معتبر نیست.');$where[]=$mp.'.download_allowed=?';$args[]=(int)$download;}
        $q=Security::normalize(Security::text($f,'media_q',150,false));if($q!==''){$where[]='('.$i.'.title LIKE ? OR '.$i.'.designer LIKE ? OR '.$mp.'.payload LIKE ?)';array_push($args,...array_fill(0,3,'%'.$q.'%'));}
        return [implode(' AND ',$where),$args];
    }
    public function forPerson(int $id,int $page=1,int $type=0):array {return $this->images(['martyr_id'=>$id,'kind'=>'all','media_type'=>$type],$page);}
    public function relatedPeople(int $image):array{
        if(!PlatformSchema::ready($this->db))return [];
        return Db::query($this->db,"SELECT m.id,m.code,m.first_name,m.last_name".$this->nameSelect()." FROM media_public_martyrs mm JOIN martyrs m ON m.id=mm.martyr_id WHERE mm.image_id=? AND m.published=1 ORDER BY m.id",[$image])->fetchAll();
    }
    public function personTypes(int $person):array{
        if(!PlatformSchema::ready($this->db))return [];
        return Db::query($this->db,"SELECT t.id,t.title,COUNT(*) AS total FROM media_public_martyrs mm JOIN images i ON i.id=mm.image_id JOIN media_publication mp ON mp.image_id=i.id JOIN protocol_terms t ON t.id=mp.type_id WHERE mm.martyr_id=? AND i.published=1 GROUP BY t.id,t.title,t.sort_order ORDER BY t.sort_order,t.id",[$person])->fetchAll();
    }
    public function creators(int $image):array {
        if(!PlatformSchema::ready($this->db))return [];
        return Db::query($this->db,'SELECT ma.artist_id,ma.creator_role,ma.credit_name,a.slug,a.status,a.archived,a.public_visible FROM media_public_artists ma JOIN artists a ON a.id=ma.artist_id WHERE ma.image_id=? ORDER BY ma.credit_order,ma.artist_id',[$image])->fetchAll();
    }
    public function images(array $f=[],int $page=1,int $perPage=24): array {
        [$where,$args]=$this->conditions($f,true);
        if(PlatformSchema::ready($this->db))$where='EXISTS(SELECT 1 FROM media_public_martyrs related JOIN martyrs m ON m.id=related.martyr_id WHERE related.image_id=i.id AND '.$where.')';
        [$mediaSql,$mediaArgs]=self::mediaConditions($f);$where.=' AND '.$mediaSql;$args=[...$args,...$mediaArgs];
        $sort=['new'=>'i.id DESC','name'=>'m.last_name,m.first_name,i.id','downloads'=>'i.downloads DESC,i.id DESC'][Security::text($f,'sort',20,false)?:'new']??'i.id DESC';
        if(!empty($f['martyr_id'])&&PlatformSchema::ready($this->db))$sort='(SELECT mm.is_featured FROM media_public_martyrs mm WHERE mm.image_id=i.id AND mm.martyr_id='.(int)$f['martyr_id'].') DESC,mp.sort_order,i.id DESC';
        $count=(int)Db::query($this->db,'SELECT COUNT(*) '.$this->mediaFrom().' WHERE '.$where,$args)->fetchColumn();
        $offset=(max(1,$page)-1)*$perPage;
        $items=Db::query($this->db,'SELECT i.*,m.id AS martyr_id,m.first_name,m.last_name,m.code,m.province,m.city'.(PlatformSchema::ready($this->db)?',mp.download_allowed':',1 AS download_allowed').$this->nameSelect().' '.$this->mediaFrom().' WHERE '.$where.' ORDER BY '.$sort.' LIMIT '.(int)$perPage.' OFFSET '.$offset,$args)->fetchAll();
        if($items&&PlatformSchema::ready($this->db)&&array_filter(MartyrFilters::galleryInput($f))){[$pw,$pa]=$this->conditions($f,true);$ids=array_map(fn($row)=>(int)$row['id'],$items);$marks=implode(',',array_fill(0,count($ids),'?'));$people=Db::query($this->db,'SELECT r.image_id,m.id AS martyr_id,m.first_name,m.last_name,m.code,m.province,m.city'.$this->nameSelect().' FROM media_public_martyrs r JOIN martyrs m ON m.id=r.martyr_id JOIN images i ON i.id=r.image_id WHERE r.image_id IN ('.$marks.') AND '.$pw.' ORDER BY m.id',[...$ids,...$pa])->fetchAll();$chosen=[];foreach($people as $person)if(!isset($chosen[$person['image_id']]))$chosen[$person['image_id']]=$person;foreach($items as &$item)if(isset($chosen[$item['id']])){$person=$chosen[$item['id']];unset($person['image_id']);$item=array_replace($item,$person);}unset($item);}
        $items=Storefront::cards($this->db,$items);return compact('items','count');
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



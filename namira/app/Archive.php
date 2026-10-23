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
    public function find(int $id,bool $public=true): array|false { return Db::query($this->db,'SELECT * FROM martyrs WHERE id=?'.($public?' AND published=1':''),[$id])->fetch(); }
    public function conditions(array $f): array {
        $where=['m.published=1']; $args=[];
        $q=Security::normalize(Security::text($f,'q',150,false));
        if($q!==''){ $where[]='(m.first_name LIKE ? OR m.last_name LIKE ? OR m.code LIKE ? OR m.summary LIKE ?)'; $args=[...$args,...array_fill(0,4,'%'.$q.'%')]; }
        foreach(['province','city','job','organization'] as $key){ $v=Security::text($f,$key,150,false); if($v!==''){ $where[]='m.'.$key.'=?'; $args[]=$v; } }
        if(!empty($f['category'])){ $where[]='EXISTS(SELECT 1 FROM martyr_categories mc WHERE mc.martyr_id=m.id AND mc.category_id=?)'; $args[]=(int)$f['category']; }
        return [implode(' AND ',$where),$args];
    }
    public function images(array $f=[],int $page=1,int $perPage=24): array {
        [$where,$args]=$this->conditions($f); $where.=' AND i.published=1';
        foreach(['kind','mime'] as $key){ if(!empty($f[$key])){ $where.=' AND i.'.$key.'=?'; $args[]=Security::text($f,$key,50); } }
        if(($f['orientation']??'')==='portrait') $where.=' AND i.height>i.width';
        if(($f['orientation']??'')==='landscape') $where.=' AND i.width>i.height';
        $sort=['new'=>'i.id DESC','name'=>'m.last_name,m.first_name,i.id','downloads'=>'i.downloads DESC,i.id DESC'][$f['sort']??'new']??'i.id DESC';
        $count=(int)Db::query($this->db,'SELECT COUNT(*) FROM images i JOIN martyrs m ON m.id=i.martyr_id WHERE '.$where,$args)->fetchColumn();
        $offset=(max(1,$page)-1)*$perPage;
        $items=Db::query($this->db,'SELECT i.*,m.first_name,m.last_name,m.code,m.province,m.city FROM images i JOIN martyrs m ON m.id=i.martyr_id WHERE '.$where.' ORDER BY '.$sort.' LIMIT '.(int)$perPage.' OFFSET '.$offset,$args)->fetchAll();
        return compact('items','count');
    }
    public function image(int $id): array|false { return Db::query($this->db,'SELECT i.*,m.first_name,m.last_name,m.code FROM images i JOIN martyrs m ON m.id=i.martyr_id WHERE i.id=? AND i.published=1 AND m.published=1',[$id])->fetch(); }
    public function export(array $f): array {
        [$w,$args]=$this->conditions($f);
        $martyrs=Db::query($this->db,'SELECT m.* FROM martyrs m WHERE '.$w.' ORDER BY m.id',$args)->fetchAll();
        $images=Db::query($this->db,'SELECT m.code,i.title,i.sequence_no,i.designer,i.mime,i.width,i.height,i.bytes,i.downloads FROM images i JOIN martyrs m ON m.id=i.martyr_id WHERE '.$w.' AND i.published=1 ORDER BY m.id,i.sequence_no',$args)->fetchAll();
        return ['شهدا'=>$martyrs,'تصاویر'=>$images];
    }
}


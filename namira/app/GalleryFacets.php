<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Read-only facets over exactly the public image/person relationship used by the gallery. */
final class GalleryFacets {
    public const ROLES=['residence_at_incident'=>'محل سکونت هنگام واقعه','birth_place'=>'محل تولد','martyrdom_place'=>'محل شهادت','burial_place'=>'محل دفن'];
    public const LEVELS=['country','province','county','city'];
    private static function base(PDO $db,array $f):array {
        [$where,$args]=(new Archive($db))->conditions($f);
        return ["FROM images i JOIN media_publication ip ON ip.image_id=i.id JOIN media_public_martyrs rel ON rel.image_id=i.id JOIN martyrs m ON m.id=rel.martyr_id", "i.published=1 AND i.kind='design' AND ".$where,$args];
    }
    public static function input(PDO $db,array $input):array {$f=MartyrFilters::galleryInput($input);MartyrFilters::conditions($db,$f);foreach((array)($f['axis']??[]) as $key=>$values){$ids=[];foreach((array)$values as $v)if($v!=='')$ids[]=(string)MartyrCatalog::canonical($db,(int)$v);if($ids)$f['axis'][$key]=array_values(array_unique($ids));else unset($f['axis'][$key]);}return $f;}
    public static function count(PDO $db,array $f):int {[$from,$where,$args]=self::base($db,$f);return (int)Db::query($db,'SELECT COUNT(DISTINCT i.id) '.$from.' WHERE '.$where,$args)->fetchColumn();}
    private static function withoutGeo(array $f):array {foreach(self::LEVELS as $level)unset($f['geo_'.$level]);unset($f['geo_role']);return $f;}
    public static function roles(PDO $db,array $f):array {
        [$from,$where,$args]=self::base($db,self::withoutGeo($f));$keys=Db::query($db,'SELECT DISTINCT gf.fact_key '.$from.' JOIN martyr_public_facts gf ON gf.martyr_id=m.id WHERE '.$where.' AND (gf.country_id IS NOT NULL OR gf.province_id IS NOT NULL OR gf.county_id IS NOT NULL OR gf.city_id IS NOT NULL)',$args)->fetchAll(PDO::FETCH_COLUMN);$rows=[];foreach(self::ROLES as $id=>$title)if(in_array($id,$keys,true))$rows[]=['id'=>$id,'title'=>$title];return $rows;
    }
    public static function geo(PDO $db,array $f,string $role):array {
        [$from,$where,$args]=self::base($db,self::withoutGeo($f));$out=[];$parents=[];$parentArgs=[];
        foreach(self::LEVELS as $level){$col=$level.'_id';$sql='SELECT DISTINCT t.id,t.title,t.parent_id,t.sort_order '.$from.' JOIN martyr_public_facts gf ON gf.martyr_id=m.id JOIN protocol_terms t ON t.id=gf.'.$col.' WHERE '.$where.' AND gf.fact_key=? AND t.taxonomy=? AND t.active=1';if($parents)$sql.=' AND '.implode(' AND ',$parents);$out[$level]=Db::query($db,$sql.' ORDER BY t.sort_order,t.title,t.id',[...$args,$role,$level,...$parentArgs])->fetchAll();if(($f['geo_'.$level]??'')!==''){$parents[]='gf.'.$col.'=?';$parentArgs[]=(int)$f['geo_'.$level];}}
        return $out;
    }
    public static function options(PDO $db,array $f=[]):array {
        if(!MartyrTaxonomySchema::ready($db)||!PlatformSchema::ready($db))return ['axes'=>[],'geo'=>array_fill_keys(self::LEVELS,[]),'roles'=>[],'geoDefaultRole'=>'residence_at_incident'];
        // Validate the original request before dropping a facet's own restriction.
        MartyrFilters::conditions($db,$f);$axes=MartyrCatalog::axes($db,true);
        $terms=[];foreach(Db::query($db,'SELECT id,title,parent_id,active,taxonomy,sort_order FROM protocol_terms')->fetchAll() as $term)$terms[(int)$term['id']]=$term;
        $redirects=[];foreach(Db::query($db,'SELECT source_term_id,target_term_id FROM martyr_term_redirects WHERE active=1')->fetchAll() as $r)$redirects[(int)$r['source_term_id']]=(int)$r['target_term_id'];
        $canonical=function(int $id)use($redirects){$seen=[];while(isset($redirects[$id])){if(isset($seen[$id]))throw new HttpError(409,'چرخه در نگاشت مقادیر وجود دارد.');$seen[$id]=true;$id=$redirects[$id];}return $id;};
        foreach($axes as &$axis){$context=$f;unset($context['axis'][$axis['axis_key']]);[$from,$where,$args]=self::base($db,$context);$ids=Db::query($db,'SELECT DISTINCT ft.term_id '.$from.' JOIN martyr_public_terms ft ON ft.martyr_id=m.id WHERE '.$where.' AND ft.axis_key=?',[...$args,$axis['axis_key']])->fetchAll(PDO::FETCH_COLUMN);$values=[];
            foreach($ids as $rawId){$id=$canonical((int)$rawId);$term=$terms[$id]??null;if($term&&$term['active']&&$term['taxonomy']===$axis['axis_key'])$values[$id]=$term;}
            if($axis['hierarchical'])foreach(array_values($values) as $term){$parent=(int)$term['parent_id'];$seen=[];while($parent&&!isset($seen[$parent])){$seen[$parent]=true;$t=$terms[$parent]??null;if(!$t||$t['taxonomy']!==$axis['axis_key'])break;if($t['active'])$values[$parent]=$t;$parent=(int)$t['parent_id'];}}
            uasort($values,fn($a,$b)=>((int)$a['sort_order']<=>(int)$b['sort_order'])?:strcmp($a['title'],$b['title']));$axis['options']=array_values(array_map(fn($t)=>array_intersect_key($t,array_flip(['id','title','parent_id'])),$values));
            $axis=array_intersect_key($axis,array_flip(['axis_key','title','selection_mode','filter_group','options']));
        }unset($axis);$roles=self::roles($db,$f);$role=$f['geo_role']??'';if($role===''&&array_filter(array_map(fn($l)=>$f['geo_'.$l]??'',self::LEVELS)))$role='residence_at_incident';if($role==='')$role=in_array('residence_at_incident',array_column($roles,'id'),true)?'residence_at_incident':($roles[0]['id']??'residence_at_incident');return ['axes'=>$axes,'geo'=>self::geo($db,$f,$role),'roles'=>$roles,'geoDefaultRole'=>$role];
    }
    public static function snapshot(PDO $db,array $input,string $changed=''):array {
        $f=self::input($db,$input);unset($f['p']);MartyrFilters::conditions($db,$f);$cleared=[];$keys=['geo_role',...array_map(fn($l)=>'geo_'.$l,self::LEVELS)];
        if($changed!==''&&!in_array($changed,$keys,true))throw new HttpError(422,'فیلتر تغییرکرده معتبر نیست.');
        if($changed!==''){// Preserve still-valid descendants, discard only those incompatible with the new parent/role.
            $start=array_search($changed,$keys,true);foreach(self::LEVELS as $i=>$level){$key='geo_'.$level;if($i+1<=$start||($f[$key]??'')==='')continue;$role=$f['geo_role']??'residence_at_incident';$options=self::geo($db,$f,$role)[$level];if(!in_array((int)$f[$key],array_map('intval',array_column($options,'id')),true)){unset($f[$key]);$cleared[]=$key;}}
        }
        $options=self::options($db,$f);return ['filters'=>$f,'facets'=>$options,'count'=>self::count($db,$f),'cleared'=>$cleared,'url'=>url('gallery',$f)];
    }
    public static function response(PDO $db,array $input,string $changed='',bool $results=false):array {
        $data=self::snapshot($db,$input,$changed);if($results){$f=$data['filters'];$f['p']=max(1,(int)($input['p']??1));$result=(new Archive($db))->images($f,$f['p']);$data['filters']=$f;$data['url']=url('gallery',$f);$data['count']=$result['count'];ob_start();view('gallery-results',compact('result','f'));$data['html']=ob_get_clean();ob_start();view('martyr-filter-tags',compact('db','f'));$data['tags']=ob_get_clean();}return $data;
    }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MartyrGeography {
    public static function install(PDO $db):array {
        $data=json_decode((string)file_get_contents(BASE_PATH.'/database/iran-geography.json'),true,32,JSON_THROW_ON_ERROR);$country=Db::query($db,"SELECT id FROM protocol_terms WHERE taxonomy='country' AND title=? ORDER BY id LIMIT 1",['ایران'])->fetchColumn();if(!$country)$country=Db::insert($db,'protocol_terms',['taxonomy'=>'country','title'=>'ایران','slug'=>'geo-ir','aliases'=>'[]','metadata'=>'{}']);$parents=['IR'=>(int)$country];$counts=['created'=>0,'reused'=>0,'conflicts'=>[],'source'=>$data['source'],'commit'=>$data['commit'],'source_year'=>$data['sourceYear']];
        Db::transaction($db,function()use($db,$data,&$parents,&$counts){foreach($data['records'] as [$kind,$external,$title,$parent]){
            if(!in_array($kind,['province','county','city'],true)||!preg_match('/^[0-9]+$/D',$external)||!isset($parents[$parent]))throw new HttpError(503,'ساختار فرهنگ جغرافیایی معتبر نیست.');$parentId=$parents[$parent];$slug='ir1404-'.$kind.'-'.$external;$existing=Db::query($db,'SELECT id,parent_id FROM protocol_terms WHERE taxonomy=? AND slug=?',[$kind,$slug])->fetch();
            if($existing){if((int)$existing['parent_id']!==$parentId)throw new HttpError(409,'والد گزینه جغرافیایی مرجع تغییر کرده است؛ بررسی دستی لازم است.');$id=(int)$existing['id'];$counts['reused']++;}
            else{$same=Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND title=? AND parent_id=?',[$kind,$title,$parentId])->fetchAll(PDO::FETCH_COLUMN);if(count($same)===1){$id=(int)$same[0];$counts['reused']++;}else{$id=Db::insert($db,'protocol_terms',['taxonomy'=>$kind,'slug'=>$slug,'title'=>$title,'parent_id'=>$parentId,'aliases'=>'[]','metadata'=>Protocol::encode(['source'=>$data['source'],'source_commit'=>$data['commit'],'source_year'=>$data['sourceYear'],'external_id'=>$external])]);$counts['created']++;if(count($same)>1)$counts['conflicts'][]=['kind'=>$kind,'external'=>$external,'policy'=>'Existing ambiguous catalog terms retained; created separate reference ID.'];}}
            $parents[$external]=$id;
        }});return $counts;
    }
}

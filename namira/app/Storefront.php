<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Public storefront settings and metadata; never reads private sections. */
final class Storefront {
    public const DEFAULTS=['home_hero_title'=>'تصویرشان ماندگار، یادشان نامیرا','home_hero_subtitle'=>'گنجینه تصاویر طراحی‌شده شهدا؛ تماشا و دریافت فایل اصلی، کاملاً رایگان'];
    public static function settings(PDO $db):array {
        $values=self::DEFAULTS;if(!PlatformSchema::ready($db))return $values;
        foreach(Db::query($db,"SELECT setting_key,value FROM app_settings WHERE setting_key IN ('home_hero_title','home_hero_subtitle')")->fetchAll() as $row)$values[$row['setting_key']]=$row['value'];
        return $values;
    }
    public static function save(PDO $db,array $actor,array $in):void {
        if(!Permissions::allows($db,$actor,'settings.manage'))throw new HttpError(403,'مدیریت ویترین مجاز نیست.');
        $values=[];foreach(['home_hero_title'=>180,'home_hero_subtitle'=>400] as $key=>$max)$values[$key]=Security::text($in,$key,$max);
        Db::transaction($db,function()use($db,$actor,$values){foreach($values as $key=>$value){if(Db::query($db,'SELECT setting_key FROM app_settings WHERE setting_key=?',[$key])->fetchColumn())Db::query($db,'UPDATE app_settings SET value=?,updated_by=?,updated_at=? WHERE setting_key=?',[$value,$actor['id'],date(DATE_ATOM),$key]);else Db::insert($db,'app_settings',['setting_key'=>$key,'value'=>$value,'updated_by'=>$actor['id'],'updated_at'=>date(DATE_ATOM)]);}Db::audit($db,'storefront.save','home');});
    }
    public static function topics(PDO $db):array {
        foreach(GalleryFacets::options($db,[])['axes'] as $axis)if($axis['axis_key']==='collection')return $axis['options'];return [];
    }
    public static function home(PDO $db,array $in):array {
        $topics=self::topics($db);$id=filter_var($in['topic']??0,FILTER_VALIDATE_INT);if($id===false||$id<0)throw new HttpError(422,'موضوع معتبر نیست.');
        if($id&&!in_array($id,array_map('intval',array_column($topics,'id')),true))throw new HttpError(422,'موضوع دارای تصویر عمومی انتخاب کنید.');
        $f=$id?['axis'=>['collection'=>[(string)$id]]]:[];
        return ['topics'=>$topics,'topic'=>$id,'f'=>$f,'result'=>(new Archive($db))->images($f,1,24)];
    }
    public static function cards(PDO $db,array $images):array {
        if(!$images||!MartyrTaxonomySchema::ready($db))return $images;
        $ids=array_values(array_unique(array_map(fn($im)=>(int)$im['martyr_id'],$images)));$marks=implode(',',array_fill(0,count($ids),'?'));
        $topics=[];$cities=[];
        foreach(Db::query($db,"SELECT p.martyr_id,t.title FROM martyr_public_terms p JOIN martyrs m ON m.id=p.martyr_id AND m.published=1 JOIN protocol_terms t ON t.id=p.term_id AND t.active=1 JOIN taxonomy_axes a ON a.axis_key=p.axis_key AND a.active=1 AND a.public_filter=1 WHERE p.axis_key='collection' AND p.martyr_id IN ($marks) ORDER BY t.sort_order,t.id",$ids)->fetchAll() as $r)$topics[$r['martyr_id']][]=$r['title'];
        foreach(Db::query($db,"SELECT f.martyr_id,t.title,t.metadata FROM martyr_public_facts f JOIN martyrs m ON m.id=f.martyr_id AND m.published=1 JOIN protocol_terms t ON t.id=f.city_id AND t.taxonomy='city' AND t.active=1 WHERE f.fact_key='residence_at_incident' AND f.martyr_id IN ($marks) ORDER BY f.ordinal",$ids)->fetchAll() as $r){
            $meta=Protocol::decode($r['metadata']);if(($meta['settlement_kind']??'')==='village')continue;$cities[$r['martyr_id']][$r['title']]=true;
        }
        foreach($images as &$im){$all=array_values(array_unique($topics[$im['martyr_id']]??[]));$im['topic_label']=$all?($all[0].(count($all)>1?' +'.fa(count($all)-1):'')):'';$names=array_keys($cities[$im['martyr_id']]??[]);$im['public_city']=count($names)===1?$names[0]:'';}unset($im);return $images;
    }
}

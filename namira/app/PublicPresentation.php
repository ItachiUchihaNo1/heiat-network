<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class PublicPresentation {
    public static function meaningful(mixed $v):bool {
        if($v===null||$v===false||$v===[] )return false;if(is_array($v))return (bool)array_filter($v,fn($x)=>self::meaningful($x));
        $text=trim(str_replace(["\xc2\xa0",'&nbsp;'], ' ',strip_tags((string)$v)));
        return $text!==''&&!in_array(mb_strtolower($text),['unknown','missing','null','نامشخص','نامعلوم','ثبت نشده','ثبت‌نشده','-','—','انتخاب نشده'],true);
    }
    public static function imageTitle(array $image):string {
        $title=trim((string)($image['title']??''));$technical=preg_match('/^(?:[0-9_-]+\.(?:jpe?g|png|webp)|(?:jpe?g|png|webp)\.[0-9_-]+)$/iu',$title)||str_contains($title,'Undefined array key')||str_contains($title,'<b>Warning');
        if(!$technical&&self::meaningful($title))return $title;
        return trim((string)(($image['display_name']??'')?:trim(($image['first_name']??'').' '.($image['last_name']??''))));
    }
    public static function account(PDO $db,array $actor):array {
        $name='';if(CommunitySchema::ready($db))$name=(string)Db::query($db,'SELECT display_name FROM account_profiles WHERE user_id=?',[$actor['id']])->fetchColumn();
        return ['name'=>$name?:'حساب کاربری','initial'=>$name!==''?mb_substr($name,0,1):'ن'];
    }
    public static function render(mixed $value,array $def,array $snapshot):string {
        if(!self::meaningful($value))return '';$type=$def['type'];$terms=$snapshot['terms']??[];
        if($type==='date'){if(!is_array($value))return '';$label=ProtocolDates::label($value);return self::meaningful($label)?e($label):'';}
        if($type==='enum')return self::meaningful($value)?e($def['options'][$value]??$value):'';
        if($type==='bool')return $value?'بله':'';
        if(in_array($type,['source','record','affiliation'],true))return '';
        if($type==='place'){$out=[];foreach(['country_id','province_or_state_id','county_id','city_or_village_id'] as $k)if(!empty($value[$k]))$out[]=$terms[$value[$k]]??'';foreach(['locality_text','historical_place_name','village_name'] as $k)if(self::meaningful($value[$k]??null))$out[]=$value[$k];return e(implode('، ',array_filter($out,fn($x)=>self::meaningful($x))));}
        if(in_array($type,['terms','term'],true))return e(implode('، ',array_filter(array_map(fn($id)=>$terms[$id]??'',(array)$value),fn($x)=>self::meaningful($x))));
        if($type==='event')return e($snapshot['events'][$value]['title']??'');
        if($type==='rich')return self::meaningful($value)?ProtocolValidator::safeHtml((string)$value):'';
        if($type==='lines')return e(implode('، ',array_filter((array)$value,fn($x)=>self::meaningful($x))));
        if($type==='url')return '<a rel="noopener noreferrer" href="'.e($value).'">'.e($value).'</a>';
        return nl2br(e((string)$value));
    }
    public static function rows(array $data,array $defs,array $snapshot):array {
        $rows=[];$skip=['display_name','short_bio','archive_code','identity_status','collections_note','service_notes','official_note','record_id','is_approximate'];
        foreach($defs as $key=>$def){
            if(in_array($key,$skip,true)||str_starts_with($key,'official_')||str_contains($key,'source')||str_ends_with($key,'_note')||str_ends_with($key,'_notes')||str_contains($key,'info_status'))continue;
            if(!array_key_exists($key,$data))continue;$html=self::render($data[$key],$def,$snapshot);if(!self::meaningful($html))continue;
            $label=['children_count'=>'تعداد فرزندان','reported_age'=>'سن','occupations'=>'شغل و حرفه','martyrdom_summary'=>'شرح چگونگی شهادت'][$key]??explode('؛',$def['label'])[0];
            $rows[]=['key'=>$key,'label'=>$label,'html'=>$html,'long'=>in_array($def['type'],['rich','textarea'],true)];
        }if(isset($defs["birth_date"],$defs["martyrdom_date"])&&!self::meaningful($data["reported_age"]??null)){ $birth=$data["birth_date"]??[];$death=$data["martyrdom_date"]??[];if(!empty($birth["year"])&&!empty($death["year"])&&($birth["calendar"]??null)===($death["calendar"]??null)){ $age=ProtocolDates::age($birth,$death);if(!str_contains($age,"نیازمند")&&!str_contains($age,"قابل محاسبه نیست"))$rows[]=["key"=>"derived_age","label"=>"سن","html"=>e($age),"long"=>false];}}return $rows;
    }
}

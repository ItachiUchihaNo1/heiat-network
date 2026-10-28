<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Narrow editor over existing protocol storage; hidden records are never replaced. */
final class SimpleMartyrForm {
    public const STEPS=[1=>'اطلاعات هویتی',2=>'زندگی و شهادت',3=>'تحصیلات',4=>'شغل',5=>'زندگی‌نامه و اسناد',6=>'ماجرای شهادت',7=>'دسته‌بندی موضوعی',8=>'وضعیت انتشار'];
    public const STORAGE=[1=>1,2=>2,3=>3,4=>4,5=>7,6=>5,7=>6];
    public static function additions(int|string $part):array {
        $f=fn($label,$type='text',$options=[])=>ProtocolSpec::field($label,$type,$options);
        return match($part){
            1=>['spouse_name'=>$f('نام همسر')],
            2=>['burial_place'=>$f('محل مزار','place')],
            3=>['education_degree'=>$f('مدرک تحصیلی','terms',['taxonomy'=>'education_level']),'employment_state'=>$f('وضعیت اشتغال','terms',['taxonomy'=>'employment_state'])],
            4=>['service_organization'=>$f('سازمان محل خدمت','term',['taxonomy'=>'organization']),'service_subunit'=>$f('زیربخش سازمانی','term',['taxonomy'=>'organization_subunit']),'service_position'=>$f('سمت','term',['taxonomy'=>'organization_position']),'service_place'=>$f('موقعیت جغرافیایی محل خدمت','place')],
            5=>['martyrdom_agent'=>$f('عامل شهادت','terms',['taxonomy'=>'mechanism']),'martyrdom_type'=>$f('نوع شهادت','terms',['taxonomy'=>'event_type'])],default=>[]};
    }
    public static function definitions(PDO $db,int $step):array {
        $p=self::STORAGE[$step]??0;$all=MartyrCatalog::definitions($db,$p);
        $keys=match($step){1=>['archive_code','display_name','first_name','last_name','father_name','mother_name','nationalities','marital_status','spouse_name','children_count'],2=>['birth_date','martyrdom_date','identification_date','reported_age','birth_place','residence_at_incident','martyrdom_place','burial_place'],3=>['education_status','education_degree'],4=>['employment_state','occupations','service_organization','service_subunit','service_position','service_place'],5=>['biography','will_text','memories'],6=>['martyrdom_summary','martyrdom_agent','martyrdom_type'],7=>['collections'],default=>[]};
        if($step===5)$all['biography']=ProtocolSpec::field('زندگی‌نامه','rich');if($step===4)$all=array_replace($all,MartyrCatalog::definitions($db,3));$out=[];
        $labels=['archive_code'=>'کد آرشیو','mother_name'=>'نام مادر','nationalities'=>'تابعیت','children_count'=>'تعداد فرزندان','reported_age'=>'سن','residence_at_incident'=>'محل زندگی','education_status'=>'وضعیت تحصیل','occupations'=>'شغل و حرفه','collections'=>'موضوعات','martyrdom_summary'=>'شرح چگونگی شهادت','will_text'=>'وصیت‌نامه','memories'=>'خاطرات'];
        foreach($keys as $key){$out[$key]=$all[$key]??ProtocolSpec::fields($p)[$key]??ProtocolSpec::fields(3)[$key]??self::additions($p)[$key]??throw new \LogicException('Missing simple field '.$key);$out[$key]['label']=$labels[$key]??$out[$key]['label'];}return $out;
    }
    public static function values(array $bundle,int $step,?PDO $db=null):array {
        $s=$bundle['sections'];$v=$s[self::STORAGE[$step]??0]??[];
        if($step===4)foreach(['occupations','employment_state'] as $k)$v[$k]=$s[3][$k]??[];
        if($step===4&&$db&&empty($v['employment_state'])&&!array_key_exists('employment_state',$s[3]??[])&&in_array($s[3]['employment_status']??'',['employed','retired','unknown','other'],true)){$legacy=(int)Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND slug=?',['employment_state',$s[3]['employment_status']])->fetchColumn();if($legacy)$v['employment_state']=[$legacy];}
        if($step===5)$v['biography']=$s[1]['biography']??$bundle['core']['biography']??'';
        $fallback=function(string $kind,string $field)use($bundle){$vals=[];foreach($bundle['records'][$kind]??[] as $row){$value=$row['data'][$field]??null;if($value!==null&&$value!==''&&$value!==[])$vals[Protocol::encode($value)]=$value;}return count($vals)===1?array_values($vals)[0]:null;};
        if($step===3&&!array_key_exists('education_degree',$v)&&($degree=$fallback('education','level_id'))!==null)$v['education_degree']=(array)$degree;
        if($step===2&&!array_key_exists('burial_place',$v)){$current=array_filter($bundle['records']['burial']??[],fn($r)=>!empty($r['data']['is_current']));if(count($current)===1)$v['burial_place']=array_values($current)[0]['data']['place']??[];}
        if($step===4)foreach(['service_organization'=>'organization','service_position'=>'position_id','service_place'=>'service_place'] as $key=>$field)if(!array_key_exists($key,$v)&&($old=$fallback('affiliation',$field))!==null)$v[$key]=$old;
        return $v;
    }
    public static function save(PDO $db,array $actor,int $id,int $step,array $in):int {
        if(!$id||!isset(self::STORAGE[$step]))throw new HttpError(422,'پرونده و بخش معتبر لازم است.');if(($in['form_complete']??'')!=='1')throw new HttpError(422,'فرم کامل دریافت نشد؛ اطلاعات قبلی محفوظ است.');
        $p=new Protocol($db,$actor);$bundle=$p->get($id);$defs=self::definitions($db,$step);$raw=is_array($in['data']??null)?$in['data']:[];$input=array_intersect_key($raw,$defs);$presence=is_array($raw['_present']??null)?array_intersect_key($raw['_present'],$defs):[];
        foreach($defs as $key=>$def)if(!array_key_exists($key,$input)&&!empty($presence[$key]))$input[$key]=$def['type']==='terms'?[]:($def['type']==='term'?'':null);$input['_present']=$presence;
        if($step===1&&($input['marital_status']??'')!=='married'){unset($input['spouse_name'],$input['_present']['spouse_name']);}
        if($step===4){$values=array_replace(self::values($bundle,4,$db),$input);$org=(int)($values['service_organization']??0);$sub=(int)($values['service_subunit']??0);if($sub&&!Db::query($db,'SELECT id FROM protocol_terms WHERE id=? AND taxonomy=? AND parent_id=?',[$sub,'organization_subunit',$org])->fetchColumn())throw new ProtocolErrors(['data.service_subunit'=>'زیربخش با سازمان انتخاب‌شده سازگار نیست؛ انتخاب قبلی خودکار حذف نشده است.']);}
        $storage=self::STORAGE[$step];$payload=$in;$payload['simple_form']='1';$payload['data']=$input;unset($payload['records']);
        return Db::transaction($db,function()use($p,$id,$step,$storage,$payload,$input){
            if($step===5){$first=$payload;$first['data']=array_intersect_key($input,['biography'=>1]);$p->save($id,1,$first);$payload['revision']=$p->get($id)['profile']['revision'];unset($payload['data']['biography'],$payload['data']['_present']['biography']);}
            if($step===4){$other=$payload;$other['data']=array_intersect_key($input,['occupations'=>1,'employment_state'=>1]);$other['data']['_present']=array_intersect_key($input['_present'],['occupations'=>1,'employment_state'=>1]);$p->save($id,3,$other);$payload['revision']=$p->get($id)['profile']['revision'];foreach(['occupations','employment_state'] as $key)unset($payload['data'][$key],$payload['data']['_present'][$key]);}
            $p->save($id,$storage,$payload);return $id;
        });
    }
}

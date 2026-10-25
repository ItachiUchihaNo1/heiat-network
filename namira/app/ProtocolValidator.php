<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ProtocolValidator {
    public array $errors=[];
    public function __construct(private PDO $db,private array $actor,private ?int $personId=null){}
    public static function formValues(array $definitions,array $saved):array {
        foreach($definitions as $key=>$def){
            if(!array_key_exists($key,$saved))continue;
            if($def['type']==='lines'&&is_array($saved[$key]))$saved[$key]=implode("\n",$saved[$key]);
            elseif(in_array($def['type'],['date','place'],true)&&is_array($saved[$key]))foreach($saved[$key] as &$part)if($part===null)$part='';
        }
        return $saved;
    }
    public function fields(array $definitions,array $input,string $prefix='',array $old=[]):array {
        $out=[];
        foreach($definitions as $key=>$def){try{$out[$key]=$this->value($key,$def,$input[$key]??null,$old[$key]??null);if(in_array($def['type'],['terms','term'],true)){
            $ids=$def['type']==='term'?array_filter([$out[$key]]):$out[$key];$titles=[];foreach($ids as $id)$titles[]=Db::query($this->db,'SELECT title FROM protocol_terms WHERE id=?',[$id])->fetchColumn();
            if(count($ids)>1&&(in_array('نامشخص',$titles,true)||in_array('مشغول تحصیل نبوده',$titles,true)))throw new HttpError(422,'نامشخص یا مشغول تحصیل نبوده با گزینه‌های دیگر قابل انتخاب نیست.');
            if(in_array('سایر',$titles,true))$out[$key.'_other']=Security::text($input,$key.'_other',1000);
        }}catch(HttpError $e){$this->errors[$prefix.$key]=$def['label'].' — '.$e->getMessage();}}
        $states=$input['states']??[];if(!is_array($states))$states=[];$out['states']=[];
        foreach($states as $key=>$state){if(!isset($definitions[$key])||!in_array($state,['missing','unknown','not_applicable','recorded'],true)){$this->errors[$prefix.'states']='وضعیت فقدان اطلاعات معتبر نیست.';continue;}$out['states'][$key]=$state;}
        return $out;
    }
    private function value(string $key,array $def,mixed $v,mixed $old):mixed {
        switch($def['type']){
            case 'bool':return in_array($v,['1',1,true,'on'],true);
            case 'integer':if($v===null||$v==='')return null;$n=Security::normalize((string)$v);if(!ctype_digit($n)||strlen($n)>6)throw new HttpError(422,'عدد صحیح نامنفی یا مقدار خالی لازم است.');return (int)$n;
            case 'enum':$v=$v??array_key_first($def['options']);if(!is_string($v)||!array_key_exists($v,$def['options']))throw new HttpError(422,'گزینه معتبر انتخاب کنید.');if($key==='review_status'&&$this->actor['role']!=='superadmin'&&$v==='reviewed')throw new HttpError(403,'تأیید ادعا فقط برای بازبین مجاز است.');return $v;
            case 'date':return ProtocolDates::read(is_array($v)?$v:[]);
            case 'place':return $this->place(is_array($v)?$v:[]);
            case 'terms':case 'term':
                if($def['type']==='terms'&&!is_array($v)&&$v!==null)throw new HttpError(422,'گزینه‌های چندانتخابی باید فهرست شناسه‌ها باشند.');$ids=$def['type']==='term'?($v?[$v]:[]):($v??[]);if(count($ids)>100)throw new HttpError(422,'تعداد گزینه‌ها بیش از حد است.');$result=[];
                foreach(array_unique($ids) as $id){if(!ctype_digit((string)$id))throw new HttpError(422,'شناسه گزینه نامعتبر است.');$term=Db::query($this->db,'SELECT * FROM protocol_terms WHERE id=? AND taxonomy=?',[(int)$id,$def['options']['taxonomy']])->fetch();if(!$term||(!$term['active']&&!in_array((int)$id,(array)$old)))throw new HttpError(422,'گزینه از فرهنگ موردنظر نیست یا غیرفعال است.');if($key==='collections'&&(Protocol::decode($term['metadata'])['mode']??'')==='filter')throw new HttpError(422,'مجموعه مبتنی بر شرط از داده‌های پرونده محاسبه می‌شود و انتخاب دستی ندارد.');$result[]=(int)$id;}
                return $def['type']==='term'?($result[0]??null):$result;
            case 'source':case 'event':case 'record':case 'affiliation':
                if($v===null||$v==='')return null;if(!ctype_digit((string)$v))throw new HttpError(422,'شناسه ارجاع نامعتبر است.');$table=match($def['type']){'source'=>'protocol_sources','event'=>'protocol_events',default=>'protocol_records'};$args=[(int)$v];$sql='SELECT id FROM '.$table.' WHERE id=?';
                if(in_array($def['type'],['record','affiliation'],true)){$sql.=' AND martyr_id=? AND archived=0';$args[]=$this->personId;if($def['type']==='affiliation')$sql.=" AND kind='affiliation'";}
                if(!Db::query($this->db,$sql,$args)->fetchColumn())throw new HttpError(422,'ارجاع پیدا نشد یا متعلق به این پرونده نیست.');return (int)$v;
            case 'url':$s=Security::text(['v'=>$v??''],'v',2000,false);if($s!==''&&(!filter_var($s,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($s,PHP_URL_SCHEME)),['http','https'],true)))throw new HttpError(422,'فقط نشانی معتبر http یا https مجاز است.');return $s;
            case 'lines':$s=Security::text(['v'=>$v??''],'v',10000,false);$lines=array_values(array_filter(array_map('trim',preg_split('/\R/u',$s))));if(count($lines)>50)throw new HttpError(422,'حداکثر ۵۰ عنوان جایگزین مجاز است.');return $lines;
            case 'claim':$s=Security::text(['v'=>$v??''],'v',150,false);if($s!==''&&!preg_match('/^[a-z][a-z0-9_.]{0,149}$/D',$s))throw new HttpError(422,'مسیر ادعا معتبر نیست.');return $s;
            case 'rich':$clean=self::safeHtml(Security::text(['v'=>$v??''],'v',50000,false));if(strlen($clean)>60000)throw new HttpError(422,'حجم زندگی‌نامه از حد مجاز پایگاه داده بیشتر است.');return $clean;
            default:return Security::text(['v'=>$v??''],'v',(['display_name'=>255,'first_name'=>150,'last_name'=>150,'father_name'=>150,'mother_name'=>150,'archive_code'=>80,'short_bio'=>2000,'title'=>255][$key]??(in_array($def['type'],['textarea'],true)?50000:1000)),false);
        }
    }
    public function place(array $input):array {
        $out=[];$parent=null;
        foreach(['country_id'=>'country','province_or_state_id'=>'province','county_id'=>'county','city_or_village_id'=>'city'] as $key=>$taxonomy){$id=$input[$key]??'';$out[$key]=null;if($id===''){continue;}if(!ctype_digit((string)$id))throw new HttpError(422,'شناسه مکان معتبر نیست.');$term=Db::query($this->db,'SELECT * FROM protocol_terms WHERE id=? AND taxonomy=?',[(int)$id,$taxonomy])->fetch();if(!$term||($taxonomy!=='country'&&(int)$term['parent_id']!==(int)$parent)||($taxonomy!=='country'&&$parent===null))throw new HttpError(422,'سطوح جغرافیایی با کشور و سطح بالاتر سازگار نیستند.');$out[$key]=(int)$id;$parent=(int)$id;}
        foreach(['locality_text','historical_place_name','address_text'] as $key)$out[$key]=Security::text($input,$key,2000,false);
        foreach(['latitude'=>90,'longitude'=>180] as $key=>$max){$n=$input[$key]??'';if($n==='')$out[$key]=null;else{if(!is_numeric(Security::normalize((string)$n))||abs((float)Security::normalize((string)$n))>$max)throw new HttpError(422,'مختصات مکانی معتبر نیست.');$out[$key]=(float)Security::normalize((string)$n);}}
        $out['needs_matching']=!empty($input['needs_matching'])||($out['locality_text']!==''&&empty($out['city_or_village_id']));return $out;
    }
    public static function safeHtml(string $html):string {
        if(!str_contains($html,'<'))return '<p>'.nl2br(e($html)).'</p>';
        $dom=new \DOMDocument();$prior=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8"><div id="bio-root">'.$html.'</div>',LIBXML_NONET|LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();libxml_use_internal_errors($prior);$root=$dom->getElementById('bio-root');if(!$root)return e(strip_tags($html));
        $allowed=['p','br','strong','b','em','i','ul','ol','li','blockquote'];$clean=function(\DOMNode $node)use(&$clean,$allowed):string{if($node instanceof \DOMText)return e($node->textContent);if(!$node instanceof \DOMElement)return '';if(in_array(strtolower($node->tagName),['script','style','iframe','object','svg'],true))return '';$body='';foreach($node->childNodes as $child)$body.=$clean($child);$tag=strtolower($node->tagName);return in_array($tag,$allowed,true)?($tag==='br'?'<br>':'<'.$tag.'>'.$body.'</'.$tag.'>'):$body;};$out='';foreach($root->childNodes as $child)$out.=$clean($child);return $out;
    }
    public function assert():void {if($this->errors)throw new ProtocolErrors($this->errors);}
}

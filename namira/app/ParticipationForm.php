<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ParticipationForm {
    public const PROPOSALS=['first_name'=>[1,'نام'],'last_name'=>[1,'نام خانوادگی'],'father_name'=>[1,'نام پدر'],'mother_name'=>[1,'نام مادر'],'birth_date'=>[2,'تاریخ تولد'],'martyrdom_date'=>[2,'تاریخ شهادت'],'identification_date'=>[2,'تاریخ شناسایی'],'martyrdom_summary'=>[6,'شرح چگونگی شهادت'],'biography'=>[5,'زندگی‌نامه'],'will_text'=>[5,'وصیت‌نامه'],'memories'=>[5,'خاطرات']];
    public static function proposal(array $in):array {
        $field=Security::text($in,'proposed_field',40,false);if($field==='')return [];if(!isset(self::PROPOSALS[$field]))throw new HttpError(422,'فیلد پیشنهادی مجاز نیست.');
        $value=str_ends_with($field,'_date')?Protocol::encode(ProtocolDates::read($in['proposed_date']??[])):Security::text($in,'proposed_value',30000);return ['proposed_field'=>$field,'proposed_value'=>$value];
    }
    public static function dateValue(mixed $value):array {if(is_array($value))return $value;if(!is_string($value)||$value==='')return [];try{$decoded=json_decode($value,true,16,JSON_THROW_ON_ERROR);return is_array($decoded)?$decoded:[];}catch(\JsonException){return [];}}
    public static function label(string $field,mixed $value):string {if(str_ends_with($field,'_date'))return ProtocolDates::label(self::dateValue($value));return strip_tags(is_scalar($value)?(string)$value:'');}
    public static function publicValue(PDO $db,int $person,string $field):mixed {
        if(!isset(self::PROPOSALS[$field]))return '';
        $raw=Db::query($db,'SELECT p.public_snapshot FROM martyr_protocols p JOIN martyrs m ON m.id=p.martyr_id WHERE p.martyr_id=? AND m.published=1',[$person])->fetchColumn();$snapshot=$raw?Protocol::decode($raw):[];
        $section=['first_name'=>1,'last_name'=>1,'father_name'=>1,'mother_name'=>1,'birth_date'=>2,'martyrdom_date'=>2,'identification_date'=>2,'martyrdom_summary'=>5,'biography'=>1,'will_text'=>7,'memories'=>7][$field];
        return $snapshot['sections'][$section][$field]??'';
    }
    public static function accept(PDO $db,array $actor,int $person,array $bundle,array $payload,string $accepted):array {
        $field=$payload['proposed_field']??'';if(!isset(self::PROPOSALS[$field]))throw new HttpError(422,'فیلد پیشنهادی قابل انتقال نیست.');$value=$accepted;
        if(str_ends_with($field,'_date')){try{$value=json_decode($accepted,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'تاریخ پیشنهادی باید ساختار معتبر تاریخ داشته باشد.');}if(!is_array($value))throw new HttpError(422,'ساختار تاریخ معتبر نیست.');}
        $step=self::PROPOSALS[$field][0];$before=SimpleMartyrForm::values($bundle,$step,$db)[$field]??null;
        SimpleMartyrForm::save($db,$actor,$person,$step,['form_complete'=>'1','revision'=>$bundle['profile']['revision'],'data'=>[$field=>$value]]);
        return [[$field=>$before],[$field=>$value]];
    }
    public static function relations(PDO $db):array {
        $default=['family'=>'خانواده شهید','friend'=>'دوست','colleague'=>'هم‌رزم یا همکار','neighbor'=>'هم‌محلی یا همشهری','acquaintance'=>'آشنا','researcher'=>'پژوهشگر','other'=>'سایر'];
        $raw=Db::query($db,"SELECT value FROM app_settings WHERE setting_key='participation_relations'")->fetchColumn();if(!$raw)return $default;
        $saved=Protocol::decode($raw);return $saved?:$default;
    }
    public static function saveRelations(PDO $db,array $actor,array $in):void {
        if(!Permissions::allows($db,$actor,'settings.manage'))throw new HttpError(403,'مدیریت مقادیر مجاز نیست.');
        $raw=Security::text($in,'relations',6000);$lines=preg_split('/\r?\n/u',$raw,-1,PREG_SPLIT_NO_EMPTY);if(count($lines)>30)throw new HttpError(422,'حداکثر ۳۰ نوع ارتباط مجاز است.');$values=[];
        foreach($lines as $line){$parts=explode('=',trim($line),2);if(count($parts)!==2||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$parts[0])||trim($parts[1])===''||mb_strlen($parts[1])>100||isset($values[$parts[0]]))throw new HttpError(422,'هر سطر شامل کلید پایدار یکتا و عنوان باشد.');$values[$parts[0]]=trim($parts[1]);}
        $json=Protocol::encode($values);if(Db::query($db,"SELECT setting_key FROM app_settings WHERE setting_key='participation_relations'")->fetchColumn())Db::query($db,"UPDATE app_settings SET value=?,updated_by=?,updated_at=? WHERE setting_key='participation_relations'",[$json,$actor['id'],date(DATE_ATOM)]);else Db::insert($db,'app_settings',['setting_key'=>'participation_relations','value'=>$json,'updated_by'=>$actor['id'],'updated_at'=>date(DATE_ATOM)]);Db::audit($db,'participation.references','relations');
    }
    public static function relation(PDO $db,array $in):array {
        $key=Security::text($in,'relation_kind',40);$choices=self::relations($db);if(!isset($choices[$key]))throw new HttpError(422,'ارتباط خود با شهید را انتخاب کنید.');
        $detail=Security::text($in,'relation_detail',500,$key==='other');return ['relation_kind'=>$key,'relation_detail'=>$detail,'relation'=>$choices[$key].($detail!==''?'؛ '.$detail:'')];
    }
}

<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class SimpleMartyrSchema {
    public static function ready(PDO $db):bool{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['006_simple_martyr_form'])->fetchColumn();}
    public static function ensure(PDO $db,array $actor,bool $backup=true):void {
        if(self::ready($db))return;if(($actor['role']??'')!=='superadmin')throw new HttpError(503,'مدیر اصلی یک بار بانک شهدا را باز کند تا فهرست‌های فرم به‌روزرسانی شوند.');
        $lock=fopen(Env::storage().'/.simple-martyr-upgrade.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new HttpError(409,'به‌روزرسانی فرم در پنجره دیگری در حال اجراست.');
        try{if(self::ready($db))return;$before=DataBackup::counts($db);$copy=$backup?DataBackup::create($db,'before-006-simple-martyr-form'):null;
            Db::transaction($db,function()use($db){foreach(['employment_state'=>['وضعیت اشتغال',3,'employment_state',0],'organization_subunit'=>['زیربخش سازمانی',4,'service_subunit',1]] as $key=>$spec){if(!Db::query($db,'SELECT axis_key FROM taxonomy_axes WHERE axis_key=?',[$key])->fetchColumn())Db::insert($db,'taxonomy_axes',['axis_key'=>$key,'subject'=>'martyr','title'=>$spec[0],'active'=>1,'public_filter'=>1]);if(!Db::query($db,'SELECT axis_key FROM martyr_axis_settings WHERE axis_key=?',[$key])->fetchColumn())Db::insert($db,'martyr_axis_settings',['axis_key'=>$key,'description'=>'','selection_mode'=>$key==='employment_state'?'multiple':'single','form_section'=>$spec[1],'form_order'=>100,'filter_order'=>100,'hierarchical'=>$spec[3],'binding_kind'=>'person','binding_key'=>$spec[2]]);}
                foreach(['employed'=>'شاغل','retired'=>'بازنشسته','military'=>'نظامی','unknown'=>'نامشخص','other'=>'سایر'] as $slug=>$title)if(!Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND slug=?',['employment_state',$slug])->fetchColumn())Db::insert($db,'protocol_terms',['taxonomy'=>'employment_state','slug'=>$slug,'title'=>$title,'aliases'=>'[]','metadata'=>Protocol::encode(['military_trigger'=>$slug==='military'])]);
                foreach(Db::query($db,"SELECT id,metadata FROM protocol_terms WHERE taxonomy='occupation' AND title IN ('نظامی','مأمور انتظامی')")->fetchAll() as $t){$meta=Protocol::decode($t['metadata']);if(!array_key_exists('military_trigger',$meta)){$meta['military_trigger']=true;Db::query($db,'UPDATE protocol_terms SET metadata=? WHERE id=?',[Protocol::encode($meta),$t['id']]);}}
                
            });DataBackup::verifyCounts($db,$before);$report=['before'=>$before,'after'=>DataBackup::counts($db),'backup'=>$copy['name']??null,'schema_changes'=>false,'policy'=>'Additive reference rows only. No person payload, record, source, draft or publication altered.'];$dir=Env::storage().'/backups';if(!is_dir($dir))mkdir($dir,0700,true);$path=$dir.'/migration-006-'.bin2hex(random_bytes(8)).'.json';if(file_put_contents($path,Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش به‌روزرسانی ذخیره نشد.');chmod($path,0600);Db::insert($db,'migrations',['version'=>'006_simple_martyr_form','applied_at'=>date('Y-m-d H:i:s')]);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

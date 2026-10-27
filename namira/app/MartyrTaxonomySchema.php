<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MartyrTaxonomySchema {
    public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['005_martyr_taxonomy'])->fetchColumn();}catch(\PDOException){return false;}}
    public static function migrate(PDO $db,bool $backup=true):void {
        if(self::ready($db))return;CommunitySchema::requireReady($db);$lock=fopen(Env::storage().'/.martyr-taxonomy-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقای دسته‌بندی در دسترس نیست.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقای دسته‌بندی‌ها در پنجره دیگری در جریان است.');}
        try{
            if(self::ready($db))return;$before=DataBackup::counts($db);$snapshot=$backup?DataBackup::create($db,'before-005-martyr-taxonomy'):null;$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';$text=$mysql?'LONGTEXT':'TEXT';
            $tables=[
                'martyr_axis_settings'=>"axis_key VARCHAR(40) PRIMARY KEY,description $text NOT NULL,selection_mode VARCHAR(10) NOT NULL DEFAULT 'multiple',form_section INTEGER NOT NULL DEFAULT 6,form_order INTEGER NOT NULL DEFAULT 0,filter_order INTEGER NOT NULL DEFAULT 0,hierarchical INTEGER NOT NULL DEFAULT 0,show_in_form INTEGER NOT NULL DEFAULT 1,binding_kind VARCHAR(30) NOT NULL DEFAULT 'person',binding_key VARCHAR(80) NOT NULL,revision INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(axis_key) REFERENCES taxonomy_axes(axis_key)",
                'martyr_term_redirects'=>"source_term_id INTEGER PRIMARY KEY,target_term_id INTEGER NOT NULL,reason $text NOT NULL,created_by INTEGER NOT NULL,created_at VARCHAR(30) NOT NULL,active INTEGER NOT NULL DEFAULT 1,FOREIGN KEY(source_term_id) REFERENCES protocol_terms(id),FOREIGN KEY(target_term_id) REFERENCES protocol_terms(id),FOREIGN KEY(created_by) REFERENCES users(id)",
                'martyr_public_facts'=>"martyr_id INTEGER NOT NULL,fact_key VARCHAR(40) NOT NULL,ordinal INTEGER NOT NULL DEFAULT 0,country_id INTEGER,province_id INTEGER,county_id INTEGER,city_id INTEGER,calendar VARCHAR(15),year INTEGER,month INTEGER,day INTEGER,date_precision VARCHAR(15),revision INTEGER NOT NULL,PRIMARY KEY(martyr_id,fact_key,ordinal),FOREIGN KEY(martyr_id) REFERENCES martyrs(id)",
                'martyr_public_terms'=>"martyr_id INTEGER NOT NULL,axis_key VARCHAR(40) NOT NULL,term_id INTEGER NOT NULL,revision INTEGER NOT NULL,PRIMARY KEY(martyr_id,axis_key,term_id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(term_id) REFERENCES protocol_terms(id)"
            ];foreach($tables as $name=>$cols)$db->exec("CREATE TABLE IF NOT EXISTS $name($cols)$tail");
            foreach(['martyr_public_term_lookup'=>'martyr_public_terms(axis_key,term_id,martyr_id)','martyr_public_geo_lookup'=>'martyr_public_facts(fact_key,province_id,city_id,martyr_id)','martyr_public_year_lookup'=>'martyr_public_facts(fact_key,calendar,year,martyr_id)'] as $name=>$cols)try{$db->exec("CREATE INDEX $name ON $cols");}catch(\PDOException $e){if(!str_contains($e->getMessage(),'already exists')&&(int)($e->errorInfo[1]??0)!==1061)throw $e;}
            foreach(MartyrCatalog::bindings() as $key=>$spec){
                if(!Db::query($db,'SELECT axis_key FROM taxonomy_axes WHERE axis_key=?',[$key])->fetchColumn())Db::insert($db,'taxonomy_axes',['axis_key'=>$key,'title'=>$spec['title'],'subject'=>'martyr','public_filter'=>1]);
                if(!Db::query($db,'SELECT axis_key FROM martyr_axis_settings WHERE axis_key=?',[$key])->fetchColumn())Db::insert($db,'martyr_axis_settings',['axis_key'=>$key,'description'=>'','selection_mode'=>$spec['mode'],'form_section'=>$spec['step'],'form_order'=>$spec['order'],'filter_order'=>$spec['order'],'hierarchical'=>0,'binding_kind'=>$spec['kind'],'binding_key'=>$spec['field']]);
            }
            foreach(['education_level'=>['ابتدایی','متوسطه اول','متوسطه دوم','کاردانی','کارشناسی','کارشناسی ارشد','دکتری'],'social_role'=>['فعال فرهنگی','مربی','فعال اجتماعی'],'organization_position'=>['فرمانده','مسئول واحد','نیروی عملیاتی']] as $tax=>$titles)foreach($titles as $title)if(!Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND title=?',[$tax,$title])->fetchColumn())Db::insert($db,'protocol_terms',['taxonomy'=>$tax,'slug'=>'catalog-'.substr(hash('sha256',$title),0,20),'title'=>$title,'aliases'=>'[]','metadata'=>'{}']);
            $geography=MartyrGeography::install($db);
            $indexed=0;foreach(Db::query($db,"SELECT p.martyr_id,p.public_snapshot,p.revision FROM martyr_protocols p JOIN martyrs m ON m.id=p.martyr_id WHERE m.published=1 AND p.public_snapshot<>'{}'")->fetchAll() as $published){MartyrCatalog::indexSnapshot($db,(int)$published['martyr_id'],Protocol::decode($published['public_snapshot']),(int)$published['revision']);$indexed++;}
            DataBackup::verifyCounts($db,$before);$after=DataBackup::counts($db);$report=['before'=>$before,'after'=>$after,'backup'=>$snapshot['name']??null,'geography'=>$geography,'indexed_published'=>$indexed,'legacy_unmatched'=>Db::query($db,"SELECT id,code,province,city,job,organization FROM martyrs WHERE province<>'' OR city<>'' OR job<>'' OR organization<>''")->fetchAll(),'policy'=>'Legacy raw values retained; no guessed semantic/geographic mapping; SQL backup does not include media.'];
            $dir=Env::storage().'/backups';if(!is_dir($dir))mkdir($dir,0700,true);$reportPath=$dir.'/migration-005-'.bin2hex(random_bytes(8)).'.json';if(file_put_contents($reportPath,Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'ذخیره گزارش ارتقا انجام نشد.');chmod($reportPath,0600);
            Db::insert($db,'migrations',['version'=>'005_martyr_taxonomy','applied_at'=>date('Y-m-d H:i:s')]);Db::audit($db,'migration.005',basename($reportPath));
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}

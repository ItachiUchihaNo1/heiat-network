<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ProtocolSchema {
    public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['002_martyr_protocol'])->fetchColumn();}catch(\PDOException){return false;}}
    public static function migrate(PDO $db):void {
        if(self::ready($db))return;
        if(!Db::query($db,'SELECT version FROM migrations WHERE version=?',['001_initial'])->fetchColumn())throw new HttpError(503,'این دیتابیس، دیتابیس نصب‌شده نامیرا نیست.');
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$id=$mysql?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $tables=[
        'martyr_protocols'=>"martyr_id INTEGER PRIMARY KEY,display_name VARCHAR(255) NOT NULL,archive_code VARCHAR(80) UNIQUE,search_text TEXT NOT NULL,public_name VARCHAR(255) NOT NULL DEFAULT '',public_snapshot TEXT NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'draft',revision INTEGER NOT NULL DEFAULT 0,public_fields TEXT NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(martyr_id) REFERENCES martyrs(id)",
        'martyr_sections'=>"martyr_id INTEGER NOT NULL,step INTEGER NOT NULL,payload TEXT NOT NULL,PRIMARY KEY(martyr_id,step),FOREIGN KEY(martyr_id) REFERENCES martyrs(id)",
        'protocol_terms'=>"id $id,taxonomy VARCHAR(40) NOT NULL,slug VARCHAR(100) NOT NULL,title VARCHAR(200) NOT NULL,parent_id INTEGER,sort_order INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1,aliases TEXT NOT NULL,metadata TEXT NOT NULL,UNIQUE(taxonomy,slug),FOREIGN KEY(parent_id) REFERENCES protocol_terms(id)",
        'protocol_sources'=>"id $id,title VARCHAR(255) NOT NULL,payload TEXT NOT NULL,created_by INTEGER,created_at VARCHAR(30) NOT NULL",
        'protocol_events'=>"id $id,title VARCHAR(255) NOT NULL,payload TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 1,created_by INTEGER,created_at VARCHAR(30) NOT NULL",
        'protocol_event_locations'=>"id $id,event_id INTEGER NOT NULL,payload TEXT NOT NULL,archived INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(event_id) REFERENCES protocol_events(id)",
        'protocol_records'=>"id $id,martyr_id INTEGER NOT NULL,kind VARCHAR(40) NOT NULL,event_id INTEGER,source_id INTEGER,payload TEXT NOT NULL,archived INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(event_id) REFERENCES protocol_events(id),FOREIGN KEY(source_id) REFERENCES protocol_sources(id)",
        'protocol_person_terms'=>"martyr_id INTEGER NOT NULL,step INTEGER NOT NULL,field_key VARCHAR(80) NOT NULL,term_id INTEGER NOT NULL,PRIMARY KEY(martyr_id,step,field_key,term_id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(term_id) REFERENCES protocol_terms(id)",
        'protocol_record_terms'=>"record_id INTEGER NOT NULL,field_key VARCHAR(80) NOT NULL,term_id INTEGER NOT NULL,PRIMARY KEY(record_id,field_key,term_id),FOREIGN KEY(record_id) REFERENCES protocol_records(id),FOREIGN KEY(term_id) REFERENCES protocol_terms(id)",
        'protocol_event_terms'=>"event_id INTEGER NOT NULL,field_key VARCHAR(80) NOT NULL,term_id INTEGER NOT NULL,PRIMARY KEY(event_id,field_key,term_id),FOREIGN KEY(event_id) REFERENCES protocol_events(id),FOREIGN KEY(term_id) REFERENCES protocol_terms(id)",
        'protocol_memberships'=>"martyr_id INTEGER NOT NULL,term_id INTEGER NOT NULL,source_id INTEGER NOT NULL,approved_by INTEGER,approved_at VARCHAR(30),PRIMARY KEY(martyr_id,term_id),FOREIGN KEY(martyr_id) REFERENCES martyrs(id),FOREIGN KEY(term_id) REFERENCES protocol_terms(id),FOREIGN KEY(source_id) REFERENCES protocol_sources(id)",
        'protocol_reviews'=>"id $id,martyr_id INTEGER NOT NULL,user_id INTEGER NOT NULL,from_status VARCHAR(30) NOT NULL,to_status VARCHAR(30) NOT NULL,reason TEXT NOT NULL,incomplete_ack INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(30) NOT NULL,FOREIGN KEY(martyr_id) REFERENCES martyrs(id)",
        'protocol_changes'=>"id $id,martyr_id INTEGER,user_id INTEGER,field_key VARCHAR(200) NOT NULL,before_value TEXT NOT NULL,after_value TEXT NOT NULL,reason TEXT NOT NULL,created_at VARCHAR(30) NOT NULL"
        ];
        foreach($tables as $table=>$cols){if($mysql)$cols=str_replace(" TEXT"," LONGTEXT",$cols);$db->exec("CREATE TABLE IF NOT EXISTS $table ($cols)$tail");}
        foreach(ProtocolSpec::seeds() as $taxonomy=>$titles)foreach($titles as $position=>$title){$slug='seed-'.substr(hash('sha256',$title),0,20);if(!Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND slug=?',[$taxonomy,$slug])->fetchColumn())Db::insert($db,'protocol_terms',['taxonomy'=>$taxonomy,'slug'=>$slug,'title'=>$title,'sort_order'=>$position,'aliases'=>'[]','metadata'=>'{}']);}
        Db::insert($db,'migrations',['version'=>'002_martyr_protocol','applied_at'=>date('Y-m-d H:i:s')]);
    }
}


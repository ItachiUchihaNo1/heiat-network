<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class DataBackup {
    public static function tables(PDO $db):array {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN):$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
    public static function identifier(PDO $db,string $name):string {$mark=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?chr(96):'"';return $mark.str_replace($mark,$mark.$mark,$name).$mark;}
    public static function counts(PDO $db):array {$out=[];foreach(self::tables($db) as $table)$out[$table]=(int)$db->query('SELECT COUNT(*) FROM '.self::identifier($db,$table))->fetchColumn();return $out;}
    public static function create(PDO $db,string $reason='manual'):array {
        if($db->inTransaction())throw new HttpError(409,'پشتیبان‌گیری باید پیش از شروع تراکنش تغییرات اجرا شود.');
        $dir=Env::storage().'/backups';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new HttpError(503,'ساخت پوشه خصوصی پشتیبان انجام نشد.');
        if(file_put_contents($dir.'/.htaccess',"Require all denied\n")===false)throw new HttpError(503,'حفاظت پوشه پشتیبان انجام نشد.');
        $name='db-'.date('Ymd-His').'-'.bin2hex(random_bytes(8));$file=$dir.'/'.$name.'.sql';$handle=fopen($file,'x');if(!$handle)throw new HttpError(503,'ساخت نسخه پشتیبان انجام نشد.');chmod($file,0600);
        $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$counts=[];
        $write=static function(string $text)use($handle):void{if(fwrite($handle,$text)!==strlen($text))throw new HttpError(503,'فضای ذخیره‌سازی برای پشتیبان کافی نیست.');};
        try {
            $db->beginTransaction();$tables=self::tables($db);
            $write("-- Namira private database backup; ".date(DATE_ATOM)."\n".($mysql?"SET FOREIGN_KEY_CHECKS=0;\n":"PRAGMA foreign_keys=OFF;\n")."BEGIN;\n");
            foreach($tables as $table){
                $quoted=self::identifier($db,$table);$row=$mysql?$db->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM):Db::query($db,"SELECT sql FROM sqlite_master WHERE type='table' AND name=?",[$table])->fetch(PDO::FETCH_NUM);
                $ddl=$mysql?$row[1]:$row[0];$write($ddl.";\n");$counts[$table]=0;
                for($offset=0;;$offset+=500){$rows=$db->query('SELECT * FROM '.$quoted.' LIMIT 500 OFFSET '.$offset)->fetchAll();if(!$rows)break;
                    foreach($rows as $data){$columns=implode(',',array_map(fn($k)=>self::identifier($db,$k),array_keys($data)));$values=implode(',',array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($data)));$write('INSERT INTO '.$quoted.' ('.$columns.') VALUES ('.$values.");\n");$counts[$table]++;}}
            }
            $write("COMMIT;\n".($mysql?"SET FOREIGN_KEY_CHECKS=1;\n":"PRAGMA foreign_keys=ON;\n"));fflush($handle);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();fclose($handle);@unlink($file);throw $e;}
        fclose($handle);$manifest=['name'=>$name,'created_at'=>date(DATE_ATOM),'reason'=>$reason,'engine'=>$mysql?'mysql':'sqlite','tables'=>$counts,'sha256'=>hash_file('sha256',$file),'bytes'=>filesize($file),'includes_media'=>false,'media_policy'=>'Additive migration does not modify media. Keep independent cPanel/storage backups.'];
        if(file_put_contents($dir.'/'.$name.'.json',Protocol::encode($manifest),LOCK_EX)===false)throw new HttpError(503,'ثبت گزارش پشتیبان انجام نشد.');chmod($dir.'/'.$name.'.json',0600);return $manifest;
    }
    public static function verifyCounts(PDO $db,array $before):void {foreach($before as $table=>$count)if((int)$db->query('SELECT COUNT(*) FROM '.self::identifier($db,$table))->fetchColumn()<$count)throw new HttpError(503,'تعداد رکوردهای جدول '.$table.' پس از مهاجرت برابر نیست؛ نسخه پشتیبان محفوظ است.');}
}

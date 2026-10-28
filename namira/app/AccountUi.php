<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class AccountUi {
    public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['008_account_location'])->fetchColumn();}catch(\PDOException){return false;}}
    public static function migrate(PDO $db):void {
        if(self::ready($db))return;if(!MemberSchema::ready($db))throw new HttpError(503,'ابتدا ارتقای حساب‌ها انجام شود.');
        $lock=fopen(Env::storage().'/.account-ui-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقا در دسترس نیست.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقا در جریان است.');}
        try{if(self::ready($db))return;$before=DataBackup::counts($db);$backup=DataBackup::create($db,'before-008-account-location');$tail=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
            $db->exec('CREATE TABLE IF NOT EXISTS account_locations(user_id INTEGER PRIMARY KEY,province_id INTEGER,county_id INTEGER,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(province_id) REFERENCES protocol_terms(id),FOREIGN KEY(county_id) REFERENCES protocol_terms(id))'.$tail);
            DataBackup::verifyCounts($db,$before);$report=['backup'=>$backup,'before'=>$before,'after'=>DataBackup::counts($db),'account_auth_rows_changed'=>0];
            if(file_put_contents(Env::storage().'/backups/migration-008-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json',Protocol::encode($report),LOCK_EX)===false)throw new HttpError(503,'گزارش ارتقا ذخیره نشد.');
            Db::insert($db,'migrations',['version'=>'008_account_location','applied_at'=>date(DATE_ATOM)]);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function location(PDO $db,int $user):array {return self::ready($db)?(Db::query($db,'SELECT province_id,county_id FROM account_locations WHERE user_id=?',[$user])->fetch()?:[]):[];}
    public static function provinces(PDO $db):array {if(!ProtocolSchema::ready($db))return [];return Db::query($db,"SELECT p.id,p.title,c.title AS country FROM protocol_terms p LEFT JOIN protocol_terms c ON c.id=p.parent_id WHERE p.taxonomy='province' AND p.active=1 ORDER BY p.sort_order,p.title")->fetchAll();}
    public static function counties(PDO $db,int $parent):array {if(!$parent)return [];if(!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='province' AND active=1",[$parent])->fetchColumn())throw new HttpError(422,'استان معتبر نیست.');return Db::query($db,"SELECT id,title FROM protocol_terms WHERE taxonomy='county' AND active=1 AND parent_id=? ORDER BY sort_order,title",[$parent])->fetchAll();}
    public static function saveLocation(PDO $db,int $user,array $in):void {
        if(!array_key_exists('province_id',$in)&&!array_key_exists('county_id',$in))return;
        if(!self::ready($db))throw new HttpError(503,'ثبت محل حساب هنوز فعال نشده است.');
        foreach(['province_id','county_id'] as $key)if(array_key_exists($key,$in)&&$in[$key]!==''&&(!is_scalar($in[$key])||!ctype_digit((string)$in[$key])))throw new HttpError(422,'مقدار جغرافیایی معتبر انتخاب کنید.');
        $old=self::location($db,$user);$province=array_key_exists('province_id',$in)?(int)$in['province_id']:(int)($old['province_id']??0);$county=array_key_exists('county_id',$in)?(int)$in['county_id']:($province===(int)($old['province_id']??0)?(int)($old['county_id']??0):0);
        if($province&&!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='province' AND active=1",[$province])->fetchColumn())throw new HttpError(422,'استان معتبر انتخاب کنید.');
        if($county&&(!$province||!Db::query($db,"SELECT id FROM protocol_terms WHERE id=? AND taxonomy='county' AND parent_id=? AND active=1",[$county,$province])->fetchColumn()))throw new HttpError(422,'شهرستان باید متعلق به استان انتخاب‌شده باشد.');
        if(Db::query($db,'SELECT user_id FROM account_locations WHERE user_id=?',[$user])->fetchColumn())Db::query($db,'UPDATE account_locations SET province_id=?,county_id=?,updated_at=? WHERE user_id=?',[$province?:null,$county?:null,date(DATE_ATOM),$user]);
        elseif($province||$county)Db::insert($db,'account_locations',['user_id'=>$user,'province_id'=>$province?:null,'county_id'=>$county?:null,'updated_at'=>date(DATE_ATOM)]);
    }
    public static function password(PDO $db,int $user,array $in):void {
        $value=$in['new_password']??'';if($value==='')return;
        if(!is_string($value)||str_contains($value,"\0")||mb_strlen($value)<12||strlen($value)>1024||!is_string($in['password_confirm']??null)||!hash_equals($value,$in['password_confirm']))throw new HttpError(422,'رمز دائمی حداقل ۱۲ نویسه و تکرار آن یکسان باشد.');
        Db::query($db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash($value,PASSWORD_DEFAULT),$user]);Db::audit($db,'account.password.otp',(string)$user);
    }
    public static function authenticate(PDO $db,array $in):int {
        if(!MemberSchema::ready($db))throw new HttpError(503,'ارتقای حساب‌ها هنوز فعال نشده است.');
        $mobile=Security::phone(Security::text($in,'mobile',20));$password=$in['password']??null;
        Security::limit($db,'mobile-password-ip',8,900);Security::limit($db,'mobile-password-'.hash('sha256',$mobile),8,900);
        if(!is_string($password)||str_contains($password,"\0")||strlen($password)>1024)throw new HttpError(422,'شماره همراه یا رمز ورود درست نیست.');
        $user=Db::query($db,'SELECT u.id,u.password_hash FROM users u JOIN account_identities i ON i.user_id=u.id WHERE i.mobile=? AND i.mobile_verified_at IS NOT NULL AND u.active=1',[$mobile])->fetch();
        $dummy='$2y$12$wRLT9sXPwMZLxTIMVs0Wu.AiY.ZVQdFruc/9RC02/fJmMmbG2vGCe';
        if(!password_verify($password,$user['password_hash']??$dummy)||!$user)throw new HttpError(422,'شماره همراه یا رمز ورود درست نیست.');return (int)$user['id'];
    }
}

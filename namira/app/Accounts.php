<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Accounts {
    public static function register(PDO $db,array $in):int {
        CommunitySchema::requireReady($db);Security::limit($db,'registration',5,3600);
        $email=strtolower(Security::text($in,'email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpError(422,'ایمیل ورود معتبر لازم است.');
        $name=Security::normalize(Security::text($in,'display_name',255));$phone=Security::text($in,'phone',30,false);if($phone!=='')$phone=Security::phone($phone);
        $password=$in['password']??null;$confirm=$in['password_confirm']??null;
        if(!is_string($password)||!is_string($confirm)||str_contains($password,"\0")||mb_strlen($password)<12||strlen($password)>1024||!hash_equals($password,$confirm))throw new HttpError(422,'رمز حداقل ۱۲ نویسه و تکرار آن یکسان باشد.');
        if(($in['consent']??'')!=='1')throw new HttpError(422,'پذیرش شرایط ثبت حساب لازم است.');
        if(Db::query($db,'SELECT id FROM users WHERE email=?',[$email])->fetchColumn())throw new HttpError(409,'این ایمیل قبلاً ثبت شده است؛ از صفحه ورود استفاده کنید.');
        try{return Db::transaction($db,function()use($db,$name,$email,$password,$phone){
            $id=Db::insert($db,'users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'member','active'=>1]);
            Db::insert($db,'user_roles',['user_id'=>$id,'role_key'=>'member']);$now=date('Y-m-d H:i:s');
            Db::insert($db,'account_profiles',['user_id'=>$id,'display_name'=>$name,'phone'=>$phone,'created_at'=>$now,'updated_at'=>$now]);
            Db::insert($db,'audit_logs',['user_id'=>$id,'action'=>'account.register','target'=>(string)$id,'created_at'=>$now]);return $id;
        });}catch(\PDOException $e){if(in_array((int)($e->errorInfo[1]??0),[19,1062],true))throw new HttpError(409,'این ایمیل قبلاً ثبت شده است.');throw $e;}
    }
    public static function profile(PDO $db,array $actor,array $in):void {
        CommunitySchema::requireReady($db);$actor=CommunityHistory::requireActor($db,$actor);$name=Security::normalize(Security::text($in,'display_name',255));$phone=Security::text($in,'phone',30,false);if($phone!=='')$phone=Security::phone($phone);$now=date('Y-m-d H:i:s');
        if(Db::query($db,'SELECT user_id FROM account_profiles WHERE user_id=?',[$actor['id']])->fetchColumn())Db::query($db,'UPDATE account_profiles SET display_name=?,phone=?,updated_at=? WHERE user_id=?',[$name,$phone,$now,$actor['id']]);else Db::insert($db,'account_profiles',['user_id'=>$actor['id'],'display_name'=>$name,'phone'=>$phone,'created_at'=>$now,'updated_at'=>$now]);
    }
    public static function save(PDO $db,array $actor,array $in):int {
        if(!Permissions::allows($db,$actor,'users.manage'))throw new HttpError(403,'مدیریت کاربران مجاز نیست.');
        $id=(int)($in['id']??0);$email=strtolower(Security::text($in,'email',190));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new HttpError(422,'ایمیل معتبر لازم است.');
        $roles=array_values(array_unique((array)($in['roles']??[])));$allowed=array_keys(Permissions::defaults());if(!$roles||array_diff($roles,$allowed))throw new HttpError(422,'حداقل یک نقش معتبر لازم است.');$role=in_array('superadmin',$roles,true)?'superadmin':$roles[0];
        $password=is_string($in['password']??null)?$in['password']:'';if((!$id||$password!=='')&&(mb_strlen($password)<12||strlen($password)>1024))throw new HttpError(422,'رمز باید بین ۱۲ تا ۱۰۲۴ نویسه باشد.');
        $active=!empty($in['active'])?1:0;if(!$id&&!$active)throw new HttpError(422,'حساب جدید باید فعال باشد.');
        return Db::transaction($db,function()use($db,$actor,$id,$email,$roles,$role,$password,$active){
            $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';Db::query($db,"SELECT id FROM users WHERE role='superadmin' AND active=1 ORDER BY id".$lock)->fetchAll();
            $old=$id?Db::query($db,'SELECT * FROM users WHERE id=?'.$lock,[$id])->fetch():null;if($id&&!$old)throw new HttpError(404,'حساب پیدا نشد.');
            if(Db::query($db,'SELECT id FROM users WHERE email=? AND id<>?',[$email,$id])->fetchColumn())throw new HttpError(409,'این ایمیل قبلاً ثبت شده است.');
            if($id===(int)$actor['id']&&(!$active||$role!=='superadmin'))throw new HttpError(422,'برای جلوگیری از قطع دسترسی، حساب مدیر فعلی باید فعال و مدیر اصلی بماند.');
            if($old&&$old['role']==='superadmin'&&(!$active||$role!=='superadmin')&&(int)Db::query($db,"SELECT COUNT(*) FROM users WHERE role='superadmin' AND active=1 AND id<>?",[$id])->fetchColumn()<1)throw new HttpError(422,'آخرین مدیر اصلی را نمی‌توان غیرفعال کرد.');
            if($id){Db::query($db,'UPDATE users SET email=?,role=?,active=? WHERE id=?',[$email,$role,$active,$id]);if($password!=='')Db::query($db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$id]);}
            else $id=Db::insert($db,'users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'active'=>$active]);
            Db::query($db,'DELETE FROM user_roles WHERE user_id=?',[$id]);foreach($roles as $key)Db::insert($db,'user_roles',['user_id'=>$id,'role_key'=>$key]);Db::audit($db,'user.access.save',(string)$id);return $id;
        });
    }
    public static function password(PDO $db,array $actor,array $in):void{
        $password=is_string($in['password']??null)?$in['password']:'';$current=is_string($in['current_password']??null)?$in['current_password']:'';
        Security::limit($db,'password-change',8);$old=Db::query($db,'SELECT password_hash FROM users WHERE id=? AND active=1',[$actor['id']])->fetchColumn();if(!$old||!password_verify($current,$old))throw new HttpError(422,'رمز فعلی درست نیست.');
        if(mb_strlen($password)<12||strlen($password)>1024||!hash_equals($password,(string)($in['password_confirm']??'')))throw new HttpError(422,'رمز جدید حداقل ۱۲ نویسه و تکرار آن یکسان باشد.');
        Db::query($db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$actor['id']]);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));Db::audit($db,'user.password.change',(string)$actor['id']);
    }
}

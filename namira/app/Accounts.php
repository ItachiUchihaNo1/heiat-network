<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Accounts {
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

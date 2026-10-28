<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MobileAuth {
    private static function secret():string {$key=Env::get('OTP_SECRET');if(strlen($key)<32)throw new HttpError(503,'ورود پیامکی هنوز پیکربندی نشده است.');return $key;}
    public static function available():bool{return false;} // A real SMS provider has not been supplied.
    private static function sessionKey():string {return $_SESSION['otp_binding']??=bin2hex(random_bytes(32));}
    public static function request(PDO $db,string $phone,string $purpose='login',?array $actor=null,?SmsGateway $gateway=null):string {
        if(!MemberSchema::ready($db))throw new HttpError(503,'ارتقای حساب‌ها هنوز انجام نشده است.');
        $mobile=Security::phone($phone);$secret=self::secret();
        if(!in_array($purpose,['login','change'],true)||($purpose==='change'&&!$actor))throw new HttpError(422,'درخواست تأیید معتبر نیست.');
        if(!$gateway||Env::get('APP_ENV','production')==='production')throw new HttpError(503,'سرویس پیامک هنوز متصل نشده است.'); // Injected adapters are tests only.
        Security::limit($db,'otp-ip',10,3600);Security::limit($db,'otp-mobile-'.hash_hmac('sha256',$mobile,$secret),5,3600);
        $binding=hash_hmac('sha256',self::sessionKey(),$secret);$now=time();$id=bin2hex(random_bytes(24));$code=(string)random_int(100000,999999);
        Db::transaction($db,function()use($db,$mobile,$purpose,$actor,$binding,$now,$id,$code,$secret){
            // Serializes issuance across sessions for one mobile, including the first request.
            $bucket='otp-lock-'.hash_hmac('sha256',$mobile,$secret);$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            Db::query($db,$mysql?'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,0,?) ON DUPLICATE KEY UPDATE hits=hits':'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,0,?) ON CONFLICT(bucket) DO NOTHING',[$bucket,$now+3600]);
            Db::query($db,'SELECT bucket FROM rate_limits WHERE bucket=?'.($mysql?' FOR UPDATE':''),[$bucket])->fetch();
            $last=Db::query($db,'SELECT created_at FROM otp_challenges WHERE mobile=? ORDER BY created_at DESC LIMIT 1',[$mobile])->fetchColumn();if($last!==false&&$now-(int)$last<60)throw new HttpError(429,'برای ارسال دوباره یک دقیقه صبر کنید.');
            Db::query($db,'UPDATE otp_challenges SET consumed_at=? WHERE mobile=? AND consumed_at IS NULL',[$now,$mobile]);
            Db::insert($db,'otp_challenges',['id'=>$id,'mobile'=>$mobile,'purpose'=>$purpose,'bound_user_id'=>$actor['id']??null,'session_digest'=>$binding,'code_digest'=>hash_hmac('sha256',$id.':'.$code,$secret),'expires_at'=>$now+120,'created_at'=>$now]);
        });
        try{$gateway->send($mobile,$code);Db::query($db,'UPDATE otp_challenges SET delivered=1 WHERE id=?',[$id]);}catch(\Throwable){Db::query($db,'UPDATE otp_challenges SET consumed_at=? WHERE id=?',[$now,$id]);throw new HttpError(503,'ارسال کد انجام نشد؛ بعداً دوباره تلاش کنید.');}
        return $id;
    }
    /** Commits failed attempts even when authentication is rejected. */
    public static function verify(PDO $db,string $id,string $code):array {
        $secret=self::secret();$result=Db::transaction($db,function()use($db,$id,$code,$secret){
            $row=Db::query($db,'SELECT * FROM otp_challenges WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id])->fetch();
            if(!$row||$row['consumed_at']!==null||!$row['delivered']||(int)$row['expires_at']<time()||(int)$row['attempts']>=5||!hash_equals($row['session_digest'],hash_hmac('sha256',self::sessionKey(),$secret)))return null;
            Db::query($db,'UPDATE otp_challenges SET attempts=attempts+1 WHERE id=?',[$id]);
            if(!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($row['code_digest'],hash_hmac('sha256',$id.':'.$code,$secret)))return null;
            Db::query($db,'UPDATE otp_challenges SET consumed_at=? WHERE id=?',[time(),$id]);return $row;
        });
        if(!$result)throw new HttpError(422,'کد نامعتبر یا منقضی شده است.');return $result;
    }
    public static function identity(PDO $db,int $user):array|false {return MemberSchema::ready($db)?Db::query($db,'SELECT * FROM account_identities WHERE user_id=?',[$user])->fetch():false;}
    public static function verified(PDO $db,array|false $actor):bool {if(!$actor)return false;$identity=self::identity($db,(int)$actor['id']);return $identity&&!empty($identity['mobile_verified_at'])&&!empty($identity['mobile']);}
    public static function finish(PDO $db,array $proof,array $in,?array $actor=null):int {
        // Proof comes only from the server session after verify; callers must not accept browser JSON here.
        $mobile=$proof['mobile'];$now=date(DATE_ATOM);
        return Db::transaction($db,function()use($db,$proof,$in,$actor,$mobile,$now){
            if($actor&&!Db::query($db,'SELECT id FROM users WHERE id=? AND active=1',[$actor['id']])->fetchColumn())throw new HttpError(403,'حساب فعال لازم است.');
            $existing=Db::query($db,'SELECT i.user_id,u.active FROM account_identities i JOIN users u ON u.id=i.user_id WHERE i.mobile=?',[$mobile])->fetch();
            if($proof['purpose']==='change'){
                if(!$actor||(int)$proof['bound_user_id']!==(int)$actor['id'])throw new HttpError(403,'تأیید به این حساب تعلق ندارد.');
                if($existing&&(int)$existing['user_id']!==(int)$actor['id'])throw new HttpError(409,'این شماره قابل اتصال نیست.');
                $id=(int)$actor['id'];
            }elseif($existing){if(!$existing['active'])throw new HttpError(403,'حساب غیرفعال است.');return (int)$existing['user_id'];}
            elseif($actor){$id=(int)$actor['id'];}
            else{
                $legacy=Db::query($db,"SELECT user_id,phone FROM account_profiles WHERE phone<>''")->fetchAll();foreach($legacy as $p)if(Security::normalize($p['phone'])===$mobile)throw new HttpError(409,'برای حفظ سوابق، ابتدا با روش ورود قبلی وارد حساب شوید و موبایل را تأیید کنید.');
                $first=Security::text($in,'first_name',150);$last=Security::text($in,'last_name',150);
                $id=Db::insert($db,'users',['email'=>'member-'.bin2hex(random_bytes(16)).'@members.invalid','password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'role'=>'member','active'=>1]);
                Db::insert($db,'user_roles',['user_id'=>$id,'role_key'=>'member']);Db::insert($db,'account_profiles',['user_id'=>$id,'display_name'=>$first.' '.$last,'phone'=>'','created_at'=>$now,'updated_at'=>$now]);
            }
            $old=self::identity($db,$id);$profile=Db::query($db,'SELECT display_name FROM account_profiles WHERE user_id=?',[$id])->fetchColumn();
            $first=Security::text($in,'first_name',150,false)?:($old['first_name']??'');$last=Security::text($in,'last_name',150,false)?:($old['last_name']??'');
            if(!$old){Db::insert($db,'account_identities',['user_id'=>$id,'first_name'=>$first,'last_name'=>$last,'mobile'=>$mobile,'mobile_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);}
            else Db::query($db,'UPDATE account_identities SET mobile=?,mobile_verified_at=?,revision=revision+1,updated_at=? WHERE user_id=?',[$mobile,$now,$now,$id]);
            return $id;
        });
    }
}

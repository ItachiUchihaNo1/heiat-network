<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Security {
    public static function start(): void {
        ini_set('session.use_strict_mode','1'); session_name('namira_session');
        session_set_cookie_params(['httponly'=>true,'secure'=>Env::get('APP_ENV','production')==='production','samesite'=>'Lax','path'=>'/']); session_start();
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
        if(Env::get('APP_ENV')==='production') header('Strict-Transport-Security: max-age=31536000');
    }
    public static function csrf(): string { return $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
    public static function field(): string { return '<input type="hidden" name="csrf" value="'.self::csrf().'">'; }
    public static function verify(array $in): void { if(!is_string($in['csrf']??null)||!hash_equals(self::csrf(),$in['csrf'])) throw new HttpError(419,'نشست فرم منقضی شده؛ صفحه را دوباره باز کنید.'); }
    public static function user(PDO $db): array|false { return isset($_SESSION['user_id'])?Db::query($db,'SELECT id,email,role FROM users WHERE id=? AND active=1',[$_SESSION['user_id']])->fetch():false; }
    public static function requireRole(PDO $db,string $area): array {
        $u=self::user($db); $roles=['content'=>['superadmin','content'],'shop'=>['superadmin','shop'],'users'=>['superadmin']];
        if(!$u||!in_array($u['role'],$roles[$area]??[],true)) throw new HttpError(403,'اجازه دسترسی ندارید.'); return $u;
    }
    public static function limit(PDO $db,string $name,int $max=10,int $seconds=900): void {
        $bucket=hash('sha256',$name.':'.($_SERVER['REMOTE_ADDR']??'cli'));
        Db::transaction($db,function()use($db,$bucket,$seconds){
            Db::query($db,'DELETE FROM rate_limits WHERE expires<?',[time()]);
            $sql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1':'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1';
            Db::query($db,$sql,[$bucket,time()+$seconds]);
        });
        if((int)Db::query($db,'SELECT hits FROM rate_limits WHERE bucket=?',[$bucket])->fetchColumn()>$max) throw new HttpError(429,'تعداد درخواست زیاد است؛ کمی بعد تلاش کنید.');
    }
    public static function text(array $in,string $key,int $max=255,bool $required=true): string {
        $v=$in[$key]??''; if(!is_string($v)) throw new HttpError(422,'ورودی نامعتبر است.'); $v=trim($v);
        if(($required&&$v==='')||mb_strlen($v)>$max) throw new HttpError(422,'فیلد «'.$key.'» را بررسی کنید.'); return $v;
    }
    public static function phone(string $v): string { $v=self::normalize($v); if(!preg_match('/^09[0-9]{9}$/D',$v)) throw new HttpError(422,'شماره موبایل معتبر وارد کنید.'); return $v; }
    public static function normalize(string $v): string { return strtr(trim($v),['ي'=>'ی','ك'=>'ک','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']); }
}

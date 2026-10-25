<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Installer {
    public function __construct(private string $root = BASE_PATH) {}
    public function enabled(): bool {
        return !is_file($this->root.'/.env') && !is_file($this->root.'/config.local.json') && !is_file($this->root.'/.setup.lock') && is_file($this->root.'/.setup.key');
    }
    public function unlock(string $key): void {
        if(!$this->enabled()) throw new HttpError(404,'نصب‌کننده فعال نیست.');
        $expected=trim((string)file_get_contents($this->root.'/.setup.key'));
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($expected,$key)) throw new HttpError(403,'کلید نصب معتبر نیست.');
        $_SESSION['install_grant']=hash('sha256',$expected); $_SESSION['install_grant_at']=time();
    }
    public function authorized(): bool {
        if(!$this->enabled() || (int)($_SESSION['install_grant_at']??0)<time()-1800) return false;
        $expected=trim((string)file_get_contents($this->root.'/.setup.key'));
        return hash_equals(hash('sha256',$expected),(string)($_SESSION['install_grant']??''));
    }
    private function limit(): void {
        $file=$this->root.'/.setup-rate-'.hash('sha256',$_SERVER['REMOTE_ADDR']??'local').'.json';
        $handle=fopen($file,'c+'); if(!$handle||!flock($handle,LOCK_EX)) throw new HttpError(503,'امکان کنترل درخواست نصب نیست.');
        try { $data=json_decode(stream_get_contents($handle),true)?:[]; if(($data['expires']??0)<time())$data=['hits'=>0,'expires'=>time()+3600]; $data['hits']++; ftruncate($handle,0);rewind($handle);fwrite($handle,json_encode($data));fflush($handle);chmod($file,0600); if($data['hits']>10)throw new HttpError(429,'تلاش‌های نصب بیش از حد است؛ یک ساعت بعد تلاش کنید.'); }
        finally {flock($handle,LOCK_UN);fclose($handle);}
    }
    public static function input(array $in): array {
        $host=Security::text($in,'db_host',253);$name=Security::text($in,'db_name',64);$user=Security::text($in,'db_user',100);$port=filter_var($in['db_port']??3306,FILTER_VALIDATE_INT);
        if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host)||!preg_match('/^[A-Za-z0-9_]+$/D',$name)||!$port||$port<1||$port>65535)throw new HttpError(422,'نام میزبان، پورت یا نام دیتابیس معتبر نیست.');
        $password=$in['db_password']??null;
        if(!is_string($password)||$password===''||strlen($password)>4096||str_contains($password,"\0")) throw new HttpError(422,'رمز دیتابیس را وارد کنید.');
        return ['APP_ENV'=>'production','APP_URL'=>'https://test.shahidpics.com','DB_DSN'=>'mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4','DB_USER'=>$user,'DB_PASSWORD'=>$password,'PAYMENT_DRIVER'=>'disabled','SHIPPING_TOMAN'=>'50000','MAX_UPLOAD_MB'=>'20'];
    }
    public function install(PDO $db,array $config,array $in): void {
        if(!$this->authorized()) throw new HttpError(403,'کلید نصب لازم است.');
        $email=Security::text($in,'admin_email',190);$password=$in['admin_password']??'';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_string($password)||mb_strlen($password)<12||strlen($password)>1024||!hash_equals($password,(string)($in['admin_password_confirm']??''))) throw new HttpError(422,'ایمیل مدیر و رمز حداقل ۱۲ کاراکتری و تکرار یکسان آن لازم است.');
        $mutex=fopen($this->root.'/.setup.mutex','c+');if(!$mutex||!flock($mutex,LOCK_EX|LOCK_NB))throw new HttpError(409,'نصب دیگری در حال انجام است.');
        try {
            if(!$this->enabled())throw new HttpError(409,'نصب قبلاً انجام شده است.');
            $tables=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN):$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            if($tables)throw new HttpError(422,'دیتابیس باید کاملاً خالی و اختصاصی نامیرا باشد. هیچ جدول موجودی تغییر نکرد.');
            foreach([$this->root,$this->root.'/storage'] as $dir){
                $probe=$dir.'/.setup-writecheck-'.bin2hex(random_bytes(12));$handle=@fopen($probe,'x');
                if(!$handle)throw new HttpError(503,'پوشه خصوصی نامیرا و storage باید برای PHP قابل نوشتن باشند.');
                fclose($handle);unlink($probe);
            }
            (require BASE_PATH.'/database/migrate.php')($db); \Namira\ProtocolSchema::migrate($db);
            Db::insert($db,'users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'superadmin']);
            $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
            $handle=fopen($this->root.'/config.local.json','x');if(!$handle)throw new HttpError(503,'ساخت تنظیمات خصوصی انجام نشد.');
            try {chmod($this->root.'/config.local.json',0600);if(fwrite($handle,$json)!==strlen($json))throw new \RuntimeException('Configuration write failed');fflush($handle);}finally{fclose($handle);}
            file_put_contents($this->root.'/.setup.lock',date(DATE_ATOM),LOCK_EX);chmod($this->root.'/.setup.lock',0600);
            unlink($this->root.'/.setup.key');unset($_SESSION['install_grant'],$_SESSION['install_grant_at']);
        } finally {flock($mutex,LOCK_UN);fclose($mutex);}
    }
    public static function web(): void {
        $installer=new self();if(!$installer->enabled())throw new HttpError(404,'نصب‌کننده فعال نیست؛ سامانه قبلاً تنظیم شده است.');
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');$error='';$values=[];
        if($_SERVER['REQUEST_METHOD']==='POST'){
            Security::verify($_POST);
            foreach(['db_host','db_name','db_user','db_port','admin_email'] as $key)$values[$key]=is_string($_POST[$key]??null)?$_POST[$key]:'';
            try {
                $installer->limit();
                if(($_POST['action']??'')==='unlock'){
                    $installer->unlock(Security::text($_POST,'setup_key',64));session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));redirect('setup');
                }
                if(($_POST['action']??'')!=='install'||!$installer->authorized())throw new HttpError(403,'ابتدا کلید نصب را وارد کنید.');
                $config=self::input($_POST);
                $db=new PDO($config['DB_DSN'],$config['DB_USER'],$config['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
                $installer->install($db,$config,$_POST);$_SESSION['flash']='نصب نامیرا انجام شد. با ایمیل و رمز مدیر وارد شوید.';redirect('login');
            }catch(HttpError $e){$error=$e->getMessage();http_response_code($e->status);}
            catch(\PDOException $e){$driver=(int)($e->errorInfo[1]??0);$error=match($driver){1045=>'نام کاربری یا رمز دیتابیس پذیرفته نشد.',1049=>'دیتابیس پیدا نشد؛ نام کامل همراه پیشوند cPanel را وارد کنید.',1044,1142=>'کاربر MySQL به دیتابیس دسترسی کافی ندارد؛ کاربر را به دیتابیس متصل کنید و مجوزهای لازم را بدهید.',2002,2005=>'اتصال به میزبان MySQL برقرار نشد؛ نام میزبان و پورت را بررسی کنید.',default=>'نصب دیتابیس کامل نشد؛ خطای هاست را بررسی کنید. در صورت ساخت جدول‌های ناقص، برای تلاش بعدی دیتابیس خالی جدید انتخاب کنید.'};error_log('Namira setup database error code '.$driver);http_response_code(422);}
            catch(\Throwable $e){$error='نصب کامل نشد؛ مجوز نوشتن پوشه خصوصی و خطای هاست را بررسی کنید. اطلاعات محرمانه را در چت نفرستید.';error_log('Namira installer error: '.get_class($e));http_response_code(503);}
        }
        $authorized=$installer->authorized();ob_start();view('setup',compact('authorized','error','values'));$content=ob_get_clean();$page='setup';$title='نصب اولیه نامیرا';$user=false;view('layout',compact('content','page','title','user'));
    }
}


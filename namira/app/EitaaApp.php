<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** One integration with the existing users database; no remote content replica. */
final class EitaaApp {
 public static function enabled():bool {return Env::get('EITAA_ENABLED')==='1'&&trim(Env::get('EITAA_TOKEN'))!=='';}
 public static function publicEnabled():bool {return self::enabled()&&DomainRouting::surface()==='public';}
 public static function ready(PDO $db):bool {try{return (bool)Db::query($db,'SELECT version FROM migrations WHERE version=?',['014_eitaa'])->fetchColumn();}catch(\PDOException){return false;}}
 public static function migrate(PDO $db,bool $backup=true):void {
  if(self::ready($db))return;if(!MemberSchema::ready($db))throw new HttpError(503,'ابتدا ارتقای حساب کاربران لازم است.');
  $lock=fopen(Env::storage().'/.eitaa-upgrade.lock','c');if(!$lock)throw new HttpError(503,'قفل ارتقا در دسترس نیست.');
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new HttpError(409,'ارتقای اتصال ایتا در حال اجراست.');}
  try{if(self::ready($db))return;$before=DataBackup::counts($db);if($backup)DataBackup::create($db,'before-014-eitaa');
   $tail=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
   $db->exec("CREATE TABLE IF NOT EXISTS eitaa_accounts(eitaa_id VARCHAR(20) PRIMARY KEY,user_id INTEGER NOT NULL UNIQUE,welcome_state VARCHAR(20) NOT NULL DEFAULT 'pending',created_at VARCHAR(30) NOT NULL,updated_at VARCHAR(30) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id))".$tail);
   DataBackup::verifyCounts($db,$before);Db::insert($db,'migrations',['version'=>'014_eitaa','applied_at'=>date(DATE_ATOM)]);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 /** Verify both launch and contact query strings; never trust initDataUnsafe or plain phone JSON. */
 private static function signedData(string $raw,?int $now=null):array {
  if(!self::enabled())throw new HttpError(503,'اتصال ایتا هنوز فعال نشده است.');if($raw===''||strlen($raw)>16384)throw new HttpError(422,'اطلاعات ورود ایتا معتبر نیست.');
  $pairs=[];foreach(explode('&',$raw) as $pair){$kv=explode('=',$pair,2);if(count($kv)!==2||preg_match('/%(?![0-9a-f]{2})/i',$pair))throw new HttpError(422,'اطلاعات ورود ایتا معتبر نیست.');$key=urldecode($kv[0]);$value=urldecode($kv[1]);if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$key)||array_key_exists($key,$pairs)||!mb_check_encoding($value,'UTF-8'))throw new HttpError(422,'اطلاعات ورود ایتا معتبر نیست.');$pairs[$key]=$value;}
  $hash=$pairs['hash']??'';unset($pairs['hash']);if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new HttpError(401,'ورود ایتا تأیید نشد.');ksort($pairs,SORT_STRING);$lines=[];foreach($pairs as $k=>$v)$lines[]=$k.'='.$v;$secret=hash_hmac('sha256',Env::get('EITAA_TOKEN'),'WebAppData',true);if(!hash_equals(hash_hmac('sha256',implode("\n",$lines),$secret),$hash))throw new HttpError(401,'ورود ایتا تأیید نشد.');
  $now??=time();$date=$pairs['auth_date']??'';if(!preg_match('/^[0-9]{1,11}$/D',$date)||(int)$date>$now+30||(int)$date<$now-300)throw new HttpError(401,'ورود ایتا منقضی شده؛ برنامک را دوباره باز کنید.');return $pairs+['hash'=>$hash,'expires'=>(int)$date+300];
 }
 private static function providerId(mixed $id):string {if((!is_string($id)&&!is_int($id))||!preg_match('/^[1-9][0-9]{0,18}$/D',(string)$id)||strlen((string)$id)>strlen((string)PHP_INT_MAX)||(strlen((string)$id)===strlen((string)PHP_INT_MAX)&&strcmp((string)$id,(string)PHP_INT_MAX)>0))throw new HttpError(422,'شناسه ایتا معتبر نیست.');return (string)$id;}
 public static function proof(string $raw,?int $now=null):array {
  $pairs=self::signedData($raw,$now);try{$user=json_decode($pairs['user']??'',true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new HttpError(422,'شناسه ایتا معتبر نیست.');}if(!is_array($user))throw new HttpError(422,'شناسه ایتا معتبر نیست.');return ['id'=>self::providerId($user['id']??null),'first_name'=>mb_substr(is_string($user['first_name']??null)?trim($user['first_name']):'',0,150),'last_name'=>mb_substr(is_string($user['last_name']??null)?trim($user['last_name']):'',0,150),'allows_write'=>($user['allows_write_to_pm']??false)===true,'hash'=>$pairs['hash'],'expires'=>$pairs['expires']];
 }
 public static function contact(string $raw,string $eitaaId,?int $now=null):array {
  $data=self::signedData($raw,$now);try{$contact=json_decode($data['contact']??'',true,8,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){throw new HttpError(422,'تأیید شماره ایتا معتبر نیست.');}
  if(!is_array($contact)||self::providerId($contact['user_id']??null)!==$eitaaId)throw new HttpError(401,'شماره به این کاربر ایتا تعلق ندارد.');$phone=$contact['phone_number']??null;if(!is_string($phone))throw new HttpError(422,'شماره ایتا معتبر نیست.');$phone=Security::normalize($phone);if(str_starts_with($phone,'0098'))$phone='0'.substr($phone,4);elseif(str_starts_with($phone,'+98'))$phone='0'.substr($phone,3);elseif(str_starts_with($phone,'98'))$phone='0'.substr($phone,2);return ['mobile'=>Security::phone($phone),'hash'=>$data['hash'],'expires'=>$data['expires']];
 }
 private static function member(PDO $db,int $id):array {
  $user=Db::query($db,'SELECT id,email,role,active FROM users WHERE id=?'.($db->inTransaction()&&$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id])->fetch();
  if(!$user||!$user['active'])throw new HttpError(403,'حساب فعال نیست.');
  if(Permissions::allows($db,$user,'dashboard.view')||in_array($user['role'],['superadmin','content','shop'],true))throw new HttpError(403,'ورود مدیران از مسیر اختصاصی پنل انجام می‌شود.');
  return $user;
 }
 public static function authenticate(PDO $db,string $raw,array|false $actor=false,bool $link=false,string $contactRaw='',string $binding='',bool $allowCreate=true):array {
  if(!self::ready($db))throw new HttpError(503,'مدیر اصلی باید یک بار پنل را برای آماده‌سازی اتصال ایتا باز کند.');if($binding!==''&&!preg_match('/^[a-f0-9]{64}$/D',$binding))throw new HttpError(422,'شناسه مرورگر معتبر نیست.');$proof=self::proof($raw);$eid=$proof['id'];$contact=$contactRaw!==''?self::contact($contactRaw,$eid):null;
  $id=Db::transaction($db,function()use($db,$proof,$eid,$actor,$link,$contact,$binding,$allowCreate){
   $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$bucket='eitaa-user-'.hash('sha256',$eid);Db::query($db,$mysql?'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,0,?) ON DUPLICATE KEY UPDATE hits=hits':'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,0,?) ON CONFLICT(bucket) DO NOTHING',[$bucket,time()+600]);Db::query($db,'SELECT bucket FROM rate_limits WHERE bucket=?'.($mysql?' FOR UPDATE':''),[$bucket])->fetch();
   $row=Db::query($db,'SELECT user_id FROM eitaa_accounts WHERE eitaa_id=?',[$eid])->fetch();$id=$row?(int)$row['user_id']:0;
   if($actor&&$id&&$id!==(int)$actor['id'])throw new HttpError(409,'این ایتا به حساب دیگری متصل است؛ ابتدا از حساب فعلی خارج شوید.');if(!$id&&$actor&&!$link&&!$contact)throw new HttpError(409,'برای اتصال ایتا به حساب فعلی، تأیید اتصال را انتخاب کنید.');
   if(!$id&&!$actor&&!$contact&&!$allowCreate)return 0;
   $same=($_SESSION['eitaa_proof']??'')===$proof['hash']&&($_SESSION['eitaa_id']??'')===$eid&&$actor&&$id===(int)$actor['id'];$used='eitaa-proof-'.$proof['hash'];$lease=$binding!==''?'eitaa-lease-'.hash('sha256',$proof['hash'].':'.$binding):'';$leaseExists=$lease!==''&&(bool)Db::query($db,'SELECT bucket FROM rate_limits WHERE bucket=?',[$lease])->fetchColumn();
   if($contact){$contactUsed='eitaa-contact-'.$contact['hash'];if(Db::query($db,'SELECT bucket FROM rate_limits WHERE bucket=?',[$contactUsed])->fetchColumn()&&!$leaseExists&&!$same)throw new HttpError(401,'تأیید شماره قبلاً استفاده شده؛ شماره را دوباره از ایتا تأیید کنید.');if(!Db::query($db,'SELECT bucket FROM rate_limits WHERE bucket=?',[$contactUsed])->fetchColumn())Db::insert($db,'rate_limits',['bucket'=>$contactUsed,'hits'=>1,'expires'=>$contact['expires']]);}
   $usedAlready=(bool)Db::query($db,'SELECT bucket FROM rate_limits WHERE bucket=?',[$used])->fetchColumn();if($usedAlready&&!$same&&!$leaseExists&&!$contact)throw new HttpError(401,'برای بازیابی این ورود، شماره حساب را دوباره از ایتا تأیید کنید.');if(!$usedAlready)Db::insert($db,'rate_limits',['bucket'=>$used,'hits'=>1,'expires'=>$proof['expires']]);if($lease!==''&&!$leaseExists)Db::insert($db,'rate_limits',['bucket'=>$lease,'hits'=>1,'expires'=>$proof['expires']]);
   $previous=$id;$id=$id?:($actor?(int)$actor['id']:0);if($id)self::member($db,$id);if($contact)$id=EitaaIdentity::bindPhone($db,$id,$contact['mobile']);
   if($id){self::member($db,$id);$other=Db::query($db,'SELECT eitaa_id FROM eitaa_accounts WHERE user_id=?'.($mysql?' FOR UPDATE':''),[$id])->fetchColumn();if($other&&$other!==$eid)throw new HttpError(409,'این شماره قبلاً به ایتای دیگری متصل شده است.');}
   $now=date(DATE_ATOM);if(!$id){$id=Db::insert($db,'users',['email'=>'eitaa-'.bin2hex(random_bytes(16)).'@members.invalid','password_hash'=>password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'role'=>'member','active'=>1]);Db::insert($db,'user_roles',['user_id'=>$id,'role_key'=>'member']);$name=trim($proof['first_name'].' '.$proof['last_name'])?:'کاربر ایتا';Db::insert($db,'account_profiles',['user_id'=>$id,'display_name'=>$name,'phone'=>'','created_at'=>$now,'updated_at'=>$now]);Db::insert($db,'account_identities',['user_id'=>$id,'first_name'=>$proof['first_name'],'last_name'=>$proof['last_name'],'mobile'=>$contact['mobile']??null,'mobile_verified_at'=>$contact?$now:null,'created_at'=>$now,'updated_at'=>$now]);}
   if($previous){if($id!==$previous)Db::query($db,'UPDATE eitaa_accounts SET user_id=?,updated_at=? WHERE eitaa_id=?',[$id,$now,$eid]);}else Db::insert($db,'eitaa_accounts',['eitaa_id'=>$eid,'user_id'=>$id,'created_at'=>$now,'updated_at'=>$now]);return $id;
  });
  if(!$id)return ['needs_contact'=>true,'allows_write'=>$proof['allows_write']];$identity=MobileAuth::identity($db,$id);return ['user_id'=>$id,'eitaa_id'=>$eid,'proof'=>$proof['hash'],'allows_write'=>$proof['allows_write'],'needs_mobile'=>empty($identity['mobile_verified_at'])||empty($identity['mobile'])];
 }
 /** POST JSON with token in body; fixed official endpoint, TLS, no redirects/retries. */
 public static function send(string $chat,string $text):void {
  if(!self::enabled()||!preg_match('/^[1-9][0-9]{0,18}$/D',$chat)||filter_var($chat,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false||trim($text)===''||mb_strlen($text)>4096)throw new HttpError(422,'پیام ایتا معتبر نیست.');
  if(!function_exists('curl_init'))throw new HttpError(503,'افزونه cURL سرور فعال نیست.');
  $h=curl_init('https://eitaayar.ir/api/app/sendMessage');$body='';$oversize=false;
  try{curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>json_encode(['token'=>Env::get('EITAA_TOKEN'),'chat_id'=>(int)$chat,'text'=>$text],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($h,string $part)use(&$body,&$oversize){if(strlen($body)+strlen($part)>32768){$oversize=true;return 0;}$body.=$part;return strlen($part);}]);
   $ok=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);if($ok===false||$oversize||$status!==200)throw new HttpError(503,'ارسال پیام ایتا تأیید نشد.');
   $data=json_decode($body,true,8);if(!is_array($data)||($data['ok']??false)!==true||($data['result']??null)!=='success')throw new HttpError(503,'ارسال پیام ایتا تأیید نشد.');
  }finally{curl_close($h);}
 }
 /** One durable claim per recipient: an ambiguous timeout must not resend the welcome. */
 public static function welcome(PDO $db,int $user,?callable $sender=null):string {
  if($sender!==null&&Env::get('APP_ENV','production')==='production')throw new HttpError(503,'ارسال آزمایشی در محیط اصلی مجاز نیست.');
  $row=Db::query($db,'SELECT eitaa_id,welcome_state FROM eitaa_accounts WHERE user_id=?',[$user])->fetch();if(!$row)throw new HttpError(403,'اتصال ایتا برای این حساب وجود ندارد.');self::member($db,$user);
  if($row['welcome_state']!=='pending')return $row['welcome_state'];
  $q=Db::query($db,"UPDATE eitaa_accounts SET welcome_state='sending',updated_at=? WHERE user_id=? AND welcome_state='pending'",[date(DATE_ATOM),$user]);if(!$q->rowCount())return 'sending';
  try{($sender??self::send(...))((string)$row['eitaa_id'],"به نامیرا خوش آمدید.\nگنجینه تصاویر شهدا، جست‌وجو و دریافت رایگان آثار در اختیار شماست.");$state='sent';}catch(\Throwable){$state='unknown';error_log('Namira Eitaa welcome not confirmed; no automatic resend.');}
  Db::query($db,'UPDATE eitaa_accounts SET welcome_state=?,updated_at=? WHERE user_id=?',[$state,date(DATE_ATOM),$user]);return $state;
 }
 public static function endpoint(PDO $db):void {
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');
  if(!self::publicEnabled())throw new HttpError(404,'صفحه پیدا نشد.');
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new HttpError(405,'فقط درخواست POST مجاز است.');
  if((int)($_SERVER['CONTENT_LENGTH']??0)>20000)throw new HttpError(413,'درخواست بیش از حد بزرگ است.');
  Security::verify($_POST);Security::limit($db,'eitaa-auth',30,600);$actor=Security::user($db);$action=$_POST['action']??'';
  if($action==='eitaa-auth'){
   $r=self::authenticate($db,Security::text($_POST,'init_data',16384),$actor,($_POST['link']??'')==='1',Security::text($_POST,'contact_data',16384,false),Security::text($_POST,'binding',64,false),($_POST['without_contact']??'')==='1');
   if(!empty($r['needs_contact'])){if(!array_key_exists('without_contact',$_POST))throw new HttpError(409,'نسخه صفحه قدیمی است؛ یک بار صفحه را کامل تازه‌سازی کنید.');echo json_encode(['ok'=>true,'needs_contact'=>true]);return;}
   session_regenerate_id(true);$_SESSION['user_id']=$r['user_id'];$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['eitaa_id']=$r['eitaa_id'];$_SESSION['eitaa_proof']=$r['proof'];
   echo json_encode(['ok'=>true,'csrf'=>Security::csrf(),'allows_write'=>$r['allows_write'],'needs_mobile'=>$r['needs_mobile']],JSON_UNESCAPED_UNICODE);return;
  }
  if($action==='eitaa-state'){echo json_encode(['ok'=>true,'authenticated'=>(bool)($actor&&isset($_SESSION['eitaa_id'])&&Db::query($db,'SELECT user_id FROM eitaa_accounts WHERE eitaa_id=?',[$_SESSION['eitaa_id']])->fetchColumn()==$actor['id'])]);return;}
  if($action==='eitaa-welcome'&&$actor&&isset($_SESSION['eitaa_id'])){
   $row=Db::query($db,'SELECT eitaa_id FROM eitaa_accounts WHERE user_id=?',[$actor['id']])->fetchColumn();if($row!==$_SESSION['eitaa_id'])throw new HttpError(403,'نشست ایتا معتبر نیست.');
   $state=self::welcome($db,(int)$actor['id']);echo json_encode(['ok'=>true,'state'=>$state]);return;
  }
  throw new HttpError(403,'درخواست معتبر نیست.');
 }
}
